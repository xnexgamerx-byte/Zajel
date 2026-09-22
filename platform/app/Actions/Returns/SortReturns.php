<?php

namespace App\Actions\Returns;

use App\Actions\Transport\BagShipments;
use App\Models\Bag;
use App\Models\Branch;
use App\Models\Hub;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * فرز الراجع للفروع.
 *
 * الراجع يعود إلى حيث يقف المندوب لا إلى حيث يقف التاجر. فراجعُ تاجرٍ
 * من البصرة رفضه زبونٌ في بغداد يصل رفّ بغداد — وتاجره لن يأتي إليه.
 * هذه الخطوة بين «استُلم من المندوب» و«سُلّم للتاجر»: ما في غير فرعه
 * يُكيَّس إلى فرعه، ويسير في الأكياس والكشوف نفسها التي تسير فيها
 * الشحنات، ويصل راجعاً (BagShipments::open) فيظهر هناك جاهزاً للتسليم.
 *
 * والنظام المرجعي يعرض على لوحته «شحنات راجعة تم إرسالها إلى الفرع ولم
 * يتم استلامها» — ١٧٦ شحنة لشركة واحدة. هذه الشاشة تمنع أن يصير ذلك
 * رقماً يُكتشف، وتجعله قائمة تُعالَج.
 */
class SortReturns
{
    public function __construct(protected BagShipments $bags) {}

    /**
     * ما على الرفّ في غير فرع تاجره، مجمَّعاً بفرع الوجهة.
     *
     * @return Collection<int, Collection<int, Shipment>>
     */
    public function misplaced(): Collection
    {
        return Shipment::query()
            ->with(['merchant:id,business_name,code,branch_id', 'hub:id,name,branch_id', 'lastFailureReason:id,name_ar'])
            ->returnOnShelf()
            ->awayFromHomeBranch()
            ->orderBy('return_received_at')
            ->get()
            ->groupBy(fn (Shipment $shipment) => $shipment->merchant->branch_id);
    }

    /**
     * رواجع في الطريق إلى فروعها: كُيِّست ولم تُفتح أكياسها بعد.
     *
     * @return Collection<int, Shipment>
     */
    public function onTheWay(): Collection
    {
        return Shipment::query()
            ->with(['merchant:id,business_name,branch_id', 'currentBag:id,code,status,to_hub_id,sealed_at,created_at', 'currentBag.toHub:id,name'])
            ->where('status', 'returning')
            ->whereNotNull('return_received_at')
            ->whereNotNull('current_bag_id')
            ->orderBy('current_bag_id')
            ->get();
    }

    /**
     * يُكيّس رواجع فرعٍ واحد في كيسٍ إلى مركزه.
     *
     * لا يُختم الكيس هنا: الموظّف يضيف ما يصل بعدُ من راجع الفرع نفسه،
     * ثم يختم ويحمّل على الكشف من شاشتَي الأكياس والكشوف كما هي.
     *
     * @param  array<int>  $shipmentIds
     */
    public function bagFor(Branch $destination, array $shipmentIds, User $actor): Bag
    {
        $to = Hub::forBranch($destination->id);

        if (! $to) {
            throw ValidationException::withMessages([
                'branch' => "لا مركز مفعّل لـ {$destination->name}. أضِف له مركزاً قبل أن يُفرَز إليه.",
            ]);
        }

        return DB::transaction(function () use ($destination, $shipmentIds, $actor, $to) {
            $shipments = Shipment::query()
                ->with('merchant:id,branch_id')
                ->whereIn('id', $shipmentIds)
                ->returnOnShelf()
                ->awayFromHomeBranch()
                ->lockForUpdate()
                ->get();

            $wrong = $shipments->reject(fn (Shipment $s) => (int) $s->merchant->branch_id === (int) $destination->id);

            if ($wrong->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'shipment_ids' => "ليست من راجع {$destination->name}: ".$wrong->pluck('number')->implode('، '),
                ]);
            }

            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages([
                    'shipment_ids' => 'لا راجع قابلاً للفرز بين المُختار — قد يكون كُيِّس أو سُلِّم سلفاً.',
                ]);
            }

            /*
            | الكيس يخرج من المركز الذي عليه الراجع فعلاً. ورواجعُ على
            | مركزين مختلفين لا تجتمع في كيسٍ واحد: كيسٌ لا يخرج من مكانين.
            */
            $origins = $shipments->pluck('hub_id')->unique();

            if ($origins->count() > 1) {
                throw ValidationException::withMessages([
                    'shipment_ids' => 'المُختار على أكثر من مركز. افرز رواجع كل مركز على حدة.',
                ]);
            }

            $from = Hub::findOrFail($origins->first());
            $bag = $this->bags->create($from, $to, $actor, "راجع مفروز إلى {$destination->name}");

            $result = $this->bags->add($bag, $shipments->pluck('number')->all(), $actor);

            foreach ($result['added'] as $shipment) {
                ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => $shipment->status->value,
                    'to_status'   => $shipment->status->value,
                    'event_type'  => 'return_sorted',
                    'actor_type'  => 'user',
                    'actor_id'    => $actor->id,
                    'actor_name'  => $actor->name,
                    'hub_id'      => $shipment->hub_id,
                    'note'        => "فُرز إلى {$destination->name} في الكيس {$bag->code}",
                    'ip'          => request()->ip(),
                ]);
            }

            return $bag->refresh();
        });
    }
}

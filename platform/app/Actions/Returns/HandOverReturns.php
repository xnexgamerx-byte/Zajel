<?php

namespace App\Actions\Returns;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\ReturnBatch;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسليم الراجع للتاجر.
 *
 * هنا تصير الشحنة «راجعة» فعلاً، وعندها تُقيَّد أجرة الإرجاع على التاجر
 * وعمولة الإرجاع للمندوب. لا يُسلَّم طرد لم يُستلم من المندوب أصلاً:
 * توقيع التاجر على ما ليس في يدك هو الخلاف نفسه الذي نتجنّبه.
 *
 * وكل تسليمٍ «دفعة راجع» بإيصالٍ برقم: من المخزن فيُستلم في الحال، أو مع
 * مندوب الاستلام فيبقى «لم يؤكَّد» حتى يؤكّده التاجر أو الموظّف. وطلب كشف
 * الراجع المفتوح للتاجر يُغلَق بها.
 */
class HandOverReturns
{
    public function __construct(
        protected ChangeShipmentStatus $changeStatus,
        protected SequenceGenerator $sequences,
    ) {}

    /** يُرجع دفعة الراجع ومعها شحناتها، أو null إن لم يُسلَّم شيء. */
    public function handle(
        array $shipmentIds,
        Merchant $merchant,
        ?User $actor = null,
        ?string $note = null,
        ?Courier $pickupCourier = null,
    ): ?ReturnBatch {
        if ($shipmentIds === []) {
            return null;
        }

        return DB::transaction(function () use ($shipmentIds, $merchant, $actor, $note, $pickupCourier) {
            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->visibleTo($actor)
                ->where('merchant_id', $merchant->id)
                ->where('status', ShipmentStatus::Returning->value)
                ->lockForUpdate()
                ->get();

            $unreceived = $shipments->whereNull('return_received_at');

            if ($unreceived->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'shipments' => 'لم تُستلم هذه الشحنات من المندوب بعد: '
                        .$unreceived->pluck('number')->implode('، '),
                ]);
            }

            /*
            | ولا يُسلَّم ما ليس على رفّ هذا الفرع: راجعٌ في كيسٍ على
            | الطريق، أو على رفّ فرعٍ آخر. توقيع التاجر على طردٍ في شاحنة
            | هو الخلاف نفسه الذي وُجدت له هذه الخطوة.
            */
            $bagged = $shipments->whereNotNull('current_bag_id');

            if ($bagged->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'shipments' => 'هذه الرواجع في أكياس لم تُفتح بعد: '.$bagged->pluck('number')->implode('، '),
                ]);
            }

            $away = Shipment::query()
                ->whereIn('id', $shipments->pluck('id'))
                ->awayFromHomeBranch()
                ->pluck('number');

            if ($away->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'shipments' => 'هذه الرواجع على رفّ غير فرع التاجر — تُفرَز إليه أولاً: '.$away->implode('، '),
                ]);
            }

            if ($shipments->isEmpty()) {
                return null;
            }

            $batch = ReturnBatch::create([
                'merchant_id'       => $merchant->id,
                'number'            => $this->sequences->next('return_batch'),
                'via'               => $pickupCourier ? 'pickup_courier' : 'store',
                'courier_id'        => $pickupCourier?->id,
                'shipments_count'   => $shipments->count(),
                'return_fees_total' => (int) $shipments->sum('return_fee'),
                'note'              => $note,
                'handed_at'         => now(),
                'handed_by_user_id' => $actor?->id,
                // من المخزن: التاجر أمامنا ويوقّع الآن
                'received_at'       => $pickupCourier ? null : now(),
                'received_by'       => $pickupCourier ? null : 'في المخزن',
            ]);

            $default = $pickupCourier
                ? "سُلّم الراجع لمندوب الاستلام {$pickupCourier->name} ليوصله للتاجر"
                : 'سُلّم الراجع للتاجر';

            $shipments = $shipments->map(function (Shipment $shipment) use ($actor, $note, $default, $batch) {
                $shipment->forceFill(['return_batch_id' => $batch->id])->save();

                return $this->changeStatus->handle($shipment, ShipmentStatus::Returned, $actor, ['note' => $note ?: $default]);
            });

            MerchantRequest::query()
                ->where('merchant_id', $merchant->id)
                ->ofType('returns')
                ->open()
                ->update([
                    'status'             => 'handled',
                    'handled_at'         => now(),
                    'handled_by_user_id' => $actor?->id,
                    'return_batch_id'    => $batch->id,
                ]);

            return $batch->setRelation('shipments', $shipments);
        });
    }

    /**
     * «تسليم الراجع لمندوب الاستلام»: المحدَّد من رواجع تجّاره يُسلَّم له،
     * دفعةً لكل تاجر — فلكلّ تاجرٍ إيصاله الذي يوقّع عليه عند بابه.
     *
     * @return Collection<int, ReturnBatch>
     */
    public function toPickupCourier(array $shipmentIds, Courier $courier, ?User $actor = null, ?string $note = null): Collection
    {
        return DB::transaction(function () use ($shipmentIds, $courier, $actor, $note) {
            $byMerchant = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->visibleTo($actor)
                ->where('status', ShipmentStatus::Returning->value)
                ->get(['id', 'merchant_id'])
                ->groupBy('merchant_id');

            $merchants = Merchant::whereIn('id', $byMerchant->keys())->get()->keyBy('id');

            return $byMerchant
                ->map(fn (Collection $rows, $merchantId) => $this->handle(
                    $rows->pluck('id')->all(), $merchants[$merchantId], $actor, $note, $courier,
                ))
                ->filter()
                ->values();
        });
    }

    /** الإيصال استُلم فعلاً عند التاجر: بتأكيده من بوابته أو بيد موظّف. */
    public function confirmReceived(ReturnBatch $batch, string $by): bool
    {
        return ReturnBatch::whereKey($batch->id)
            ->whereNull('received_at')
            ->update(['received_at' => now(), 'received_by' => mb_substr($by, 0, 120), 'updated_at' => now()]) === 1;
    }

    /**
     * ما على رفّ فرع تاجره وينتظره.
     *
     * وما على رفّ فرعٍ آخر لا يظهر هنا بل في فرز الراجع — فالشاشتان
     * تقتسمان الرواجع المستلَمة، وكلُّ راجعٍ في واحدة منهما لا في كلتيهما.
     */
    public function ready(?int $merchantId = null, ?int $pickupCourierId = null, ?User $viewer = null): Collection
    {
        return Shipment::query()
            ->visibleTo($viewer)
            ->with(['merchant:id,business_name,code,pickup_courier_id', 'deliveryCourier:id,name', 'lastFailureReason:id,name_ar'])
            ->returnOnShelf()
            ->awayFromHomeBranch(false)
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))
            // رواجع تجّار مندوب الاستلام هذا: هو من يمرّ بهم
            ->when($pickupCourierId, fn ($q) => $q->whereIn('merchant_id',
                Merchant::query()->select('id')->where('pickup_courier_id', $pickupCourierId)))
            ->orderBy('return_received_at')
            ->get();
    }
}

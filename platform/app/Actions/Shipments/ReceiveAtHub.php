<?php

namespace App\Actions\Shipments;

use App\Actions\Returns\ReceiveReturns;
use App\Enums\ShipmentStatus;
use App\Models\Branch;
use App\Models\Hub;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * «استلام وصولات في كل المراحل»: كل طردٍ يُمسح عند باب المخزن يدخله، أيّاً كانت
 * مرحلته — والانتقال الذي تحتاجه كل مرحلة يُختار له:
 *
 * - أنشأه التاجر وأحضره بنفسه (أو بانتظار الاستلام): «تم الاستلام» ثم «في المخزن».
 * - مع مندوب الاستلام، أو في كيسٍ بين فرعين، أو عاد مع مندوب التوصيل بلا حسم:
 *   «في المخزن».
 * - راجعٌ بيد المندوب، أو باقي واصلٍ جزئي، أو قديم استبدال: استلام الراجع
 *   (ReceiveReturns) — يبقى «قيد الإرجاع» حتى يأخذه تاجره، فالمال لا يتحرّك
 *   بوصوله إلينا.
 *
 * وما عدا ذلك يُتخطّى بسببه: المسلَّمة لا تعود إلى الرفّ بمسحة.
 */
class ReceiveAtHub
{
    public function __construct(
        protected ChangeShipmentStatus $change,
        protected ReceiveReturns $returns,
    ) {}

    /**
     * @param  array<int>  $shipmentIds  ما يراه المستخدم وحده — يُصفّى قبل الاستدعاء
     * @param  string  $note  ما يُكتب في سجلّ كل شحنة: بالمسح، أو من القائمة بالجملة
     * @return array{received: list<string>, skipped: array<string, string>}
     */
    public function handle(array $shipmentIds, User $actor, string $note = 'استلام بالمسح'): array
    {
        $hub = static::hubOf($actor);
        $received = [];
        $skipped = [];

        $shipments = Shipment::whereIn('id', $shipmentIds)->orderBy('id')->get();

        foreach ($shipments as $shipment) {
            $options = ['note' => $note, 'hub_id' => $hub?->id];

            try {
                $reason = $this->receive($shipment, $actor, $options);
            } catch (ValidationException $e) {
                // سبقه أحدٌ إليها بين المسح والحفظ: تُتخطّى بسببها ولا يسقط الباقي
                $reason = collect($e->errors())->flatten()->first();
            }

            if ($reason === null) {
                $received[] = $shipment->number;
            } else {
                $skipped[$shipment->number] = $reason;
            }
        }

        return ['received' => $received, 'skipped' => $skipped];
    }

    /** @return string|null سبب التخطّي، أو null إن دخلت المخزن */
    protected function receive(Shipment $shipment, User $actor, array $options): ?string
    {
        $status = $shipment->status;

        // راجعٌ بيد المندوب، ومثله باقي الواصل الجزئي وقديم الاستبدال: يُستلم راجعاً
        if ($status === ShipmentStatus::Returning || $status === ShipmentStatus::PartiallyDelivered) {
            // راجعٌ «لم يصل» مع كشفه ثم وُجد: يدخل رفّ هذا المخزن راجعاً كما لو وصل في كيسه (docs/plan/50)
            if ($shipment->missing_at !== null && $shipment->return_received_at !== null) {
                $shipment->forceFill(['hub_id' => $options['hub_id']])->save();
                \App\Models\ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => $status->value,
                    'to_status'   => $status->value,
                    'event_type'  => 'return_arrived',
                    'actor_type'  => 'user',
                    'actor_id'    => $actor->id,
                    'actor_name'  => $actor->name,
                    'hub_id'      => $options['hub_id'],
                    'note'        => 'وُجد الراجع بعد أن لم يصل مع كشفه',
                    'ip'          => request()->ip(),
                ]);

                return null;
            }

            if ($shipment->return_received_at !== null) {
                // راجعٌ وصل في كيسٍ من فرعٍ آخر: يُستلم كشفه فيصل راجعاً (docs/plan/38)
                return $shipment->current_bag_id
                    ? 'راجعٌ في كيس نقل: استلم كشفه من «النقل بين الفروع»'
                    : 'راجع مستلَم سلفاً';
            }

            $this->returns->handle([$shipment->id], $actor, $options['note']);

            return null;
        }

        if (in_array($status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true)) {
            $this->change->handle($shipment, ShipmentStatus::PickedUp, $actor, $options);
            $this->change->handle($shipment->refresh(), ShipmentStatus::AtHub, $actor, $options);

            return null;
        }

        if ($status === ShipmentStatus::AtHub) {
            return 'بالمخزن سلفاً';
        }

        if (! $status->canMoveTo(ShipmentStatus::AtHub)) {
            return $status->label();
        }

        $this->change->handle($shipment, ShipmentStatus::AtHub, $actor, $options);

        return null;
    }

    /**
     * المخزن الذي يقف فيه الموظّف: مركز فرعه، وإلّا مركز الفرع الرئيسي —
     * صاحب الشركة لا فرع له ويستلم في الرئيسي.
     */
    public static function hubOf(User $user): ?Hub
    {
        return Hub::forBranch($user->branch_id)
            ?? Hub::forBranch(Branch::where('is_main', true)->value('id'));
    }
}

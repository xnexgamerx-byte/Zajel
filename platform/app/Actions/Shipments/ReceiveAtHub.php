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
 * - راجعٌ بيد المندوب: استلام الراجع (ReceiveReturns) — يبقى «قيد الإرجاع»
 *   حتى يأخذه تاجره، فالمال لا يتحرّك بوصوله إلينا.
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
     * @return array{received: list<string>, skipped: array<string, string>}
     */
    public function handle(array $shipmentIds, User $actor): array
    {
        $hub = static::hubOf($actor);
        $received = [];
        $skipped = [];

        $shipments = Shipment::whereIn('id', $shipmentIds)->orderBy('id')->get();

        foreach ($shipments as $shipment) {
            $options = ['note' => 'استلام بالمسح', 'hub_id' => $hub?->id];

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

        if ($status === ShipmentStatus::Returning) {
            if ($shipment->return_received_at !== null) {
                return 'راجعٌ مستلَمٌ سلفاً';
            }

            $this->returns->handle([$shipment->id], $actor, 'استلام بالمسح');

            return null;
        }

        if (in_array($status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true)) {
            $this->change->handle($shipment, ShipmentStatus::PickedUp, $actor, $options);
            $this->change->handle($shipment->refresh(), ShipmentStatus::AtHub, $actor, $options);

            return null;
        }

        if ($status === ShipmentStatus::AtHub) {
            return 'في المخزن سلفاً';
        }

        // ما بقي من التسليم الجزئي راجعٌ، يُستلم من «تصفيات الراجع» لا من هنا
        if ($status === ShipmentStatus::PartiallyDelivered) {
            return 'تسليم جزئي — باقيها من «تصفيات الراجع»';
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

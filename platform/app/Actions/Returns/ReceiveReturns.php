<?php

namespace App\Actions\Returns;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Hub;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * استلام الراجع من المندوب.
 *
 * ليست تغيير حالة بل إثبات حيازة: الطرد انتقل من حقيبة المندوب إلى
 * رفّ المخزن. تبقى الشحنة «قيد الإرجاع» حتى يستلمها التاجر فعلاً،
 * فالمال لا يتحرّك بمجرّد وصول الطرد إلينا.
 *
 * ومعه باقي الواصل الجزئي وقديم الاستبدال (الوثيقتان ٢٤ و٣١): في حقيبة المندوب منذ
 * التسليم، ولا قرار فيه ينتظر أحداً — يصير «راجع» باستلامه هنا.
 */
class ReceiveReturns
{
    public function __construct(protected ChangeShipmentStatus $change) {}

    /** @return Collection<int, Shipment> */
    public function handle(array $shipmentIds, ?User $actor = null, ?string $note = null): Collection
    {
        if ($shipmentIds === []) {
            return collect();
        }

        /*
        | مكان الاستلام هو مكان الطرد.
        |
        | مَن يمسح الراجع عند العدّاد واقفٌ في فرعه، وهذه أصدق إشارة
        | لمكان الطرد: hub_id القديم هو المركز الذي خرج منه للتوصيل لا
        | الذي عاد إليه. وبلا مكانٍ معروف لا يُعرف إن كان الراجع في فرع
        | تاجره فيُسلَّم، أم في فرعٍ آخر فيُفرَز إليه.
        */
        $receivedAt = Hub::forBranch($actor?->branch_id);

        return DB::transaction(function () use ($shipmentIds, $actor, $note, $receivedAt) {
            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->visibleTo($actor)
                ->where(fn ($q) => static::withCourier($q))
                ->lockForUpdate()
                ->get();

            foreach ($shipments as $shipment) {
                if ($shipment->status === ShipmentStatus::PartiallyDelivered) {
                    $this->change->handle($shipment, ShipmentStatus::Returning, $actor, [
                        'note' => $shipment->type === 'exchange' ? 'القطعة القديمة من الاستبدال' : 'باقي الواصل الجزئي',
                    ]);
                }

                $shipment->forceFill(array_filter([
                    'return_received_at'         => now(),
                    'return_received_by_user_id' => $actor?->id,
                    // مجهول المكان يبقى على ما كان، لا يُمحى
                    'hub_id'                     => $receivedAt?->id,
                ], fn ($value) => $value !== null))->save();

                ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => $shipment->status->value,
                    'to_status'   => $shipment->status->value,
                    'event_type'  => 'return_received',
                    'actor_type'  => $actor ? 'user' : 'system',
                    'actor_id'    => $actor?->id,
                    'actor_name'  => $actor?->name,
                    'courier_id'  => $shipment->delivery_courier_id,
                    'hub_id'      => $shipment->hub_id,
                    'note'        => $note ?: 'استُلم الراجع من المندوب',
                    'ip'          => request()->ip(),
                ]);
            }

            return $shipments;
        });
    }

    /** ما هو في طريق العودة ولم يصل المخزن بعد. */
    public function pending(?int $courierId = null, ?User $viewer = null): Collection
    {
        return Shipment::query()
            ->visibleTo($viewer)
            // راجعُ مناديب هذا الفرع وحده — والرئيسي بغداد (docs/plan/50)
            ->withCourierOf($viewer)
            ->with(['merchant:id,business_name,code', 'deliveryCourier:id,name', 'lastFailureReason:id,name_ar'])
            ->where(fn ($q) => static::withCourier($q))
            ->when($courierId, fn ($q) => $q->where('delivery_courier_id', $courierId))
            ->orderBy('status_changed_at')
            ->get();
    }

    /**
     * راجعٌ ما زال بيد المندوب: قُرّر إرجاعه ولم يُستلم، أو باقي واصلٍ جزئي وقديم استبدالٍ
     * لم يُستلما بعد. شرطٌ واحد للقائمة وللاستلام ولعدّاد «راجع عند المندوب».
     */
    public static function withCourier(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('shipments.status', ShipmentStatus::Returning->value)
            ->whereNull('shipments.return_received_at'))
            ->orWhere('shipments.status', ShipmentStatus::PartiallyDelivered->value);
    }
}

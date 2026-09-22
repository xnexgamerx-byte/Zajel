<?php

namespace App\Actions\Returns;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * استلام الراجع من المندوب.
 *
 * ليست تغيير حالة بل إثبات حيازة: الطرد انتقل من حقيبة المندوب إلى
 * رفّ المخزن. تبقى الشحنة «قيد الإرجاع» حتى يستلمها التاجر فعلاً،
 * فالمال لا يتحرّك بمجرّد وصول الطرد إلينا.
 */
class ReceiveReturns
{
    /** @return Collection<int, Shipment> */
    public function handle(array $shipmentIds, ?User $actor = null, ?string $note = null): Collection
    {
        if ($shipmentIds === []) {
            return collect();
        }

        return DB::transaction(function () use ($shipmentIds, $actor, $note) {
            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->where('status', ShipmentStatus::Returning->value)
                ->whereNull('return_received_at')
                ->lockForUpdate()
                ->get();

            foreach ($shipments as $shipment) {
                $shipment->forceFill([
                    'return_received_at'        => now(),
                    'return_received_by_user_id' => $actor?->id,
                ])->save();

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
    public function pending(?int $courierId = null): Collection
    {
        return Shipment::query()
            ->with(['merchant:id,business_name,code', 'deliveryCourier:id,name', 'lastFailureReason:id,name_ar'])
            ->where('status', ShipmentStatus::Returning->value)
            ->whereNull('return_received_at')
            ->when($courierId, fn ($q) => $q->where('delivery_courier_id', $courierId))
            ->orderBy('status_changed_at')
            ->get();
    }
}

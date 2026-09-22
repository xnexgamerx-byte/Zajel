<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * المندوب استلم طرود التاجر.
 *
 * الأثر الحقيقي ليس إغلاق الطلب بل نقل شحنات التاجر المعلّقة إلى
 * "تم الاستلام" دفعة واحدة. بدونه يبقى المندوب يفتح كل شحنة على حدة
 * — عشرون طرداً تعني عشرين ضغطة عند باب المتجر.
 */
class CompletePickup
{
    public function __construct(protected ChangeShipmentStatus $changeStatus) {}

    public function handle(PickupRequest $pickup, int $actualCount, ?User $actor = null, ?string $note = null): PickupRequest
    {
        return DB::transaction(function () use ($pickup, $actualCount, $actor, $note) {
            $pending = Shipment::query()
                ->where('merchant_id', $pickup->merchant_id)
                ->whereIn('status', [ShipmentStatus::Created->value, ShipmentStatus::PendingPickup->value])
                ->get();

            foreach ($pending as $shipment) {
                $this->changeStatus->handle($shipment, ShipmentStatus::PickedUp, $actor, [
                    'actor_type' => 'courier',
                    'courier_id' => $pickup->courier_id,
                    'note'       => "استلام ضمن طلب {$pickup->number}",
                ]);

                $shipment->forceFill([
                    'pickup_request_id' => $pickup->id,
                    'pickup_courier_id' => $pickup->courier_id,
                ])->save();
            }

            $pickup->forceFill([
                'status'       => 'completed',
                'actual_count' => $actualCount,
                'completed_at' => now(),
                'notes'        => $note ?: $pickup->notes,
            ])->save();

            return $pickup->refresh();
        });
    }
}

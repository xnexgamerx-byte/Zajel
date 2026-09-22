<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المدخل الوحيد لتغيير حالة الشحنة.
 *
 * لا يُسمح بكتابة $shipment->status = ... في أي مكان آخر:
 * كل تغيير يمرّ من هنا فيُتحقَّق من شرعيّة الانتقال ويُسجَّل حدثه،
 * وإلّا صار سجلّ التتبّع فيه ثقوب وصارت التقارير تكذب.
 */
class ChangeShipmentStatus
{
    public function handle(
        Shipment $shipment,
        ShipmentStatus $to,
        ?User $actor = null,
        array $options = [],
    ): Shipment {
        $from = $shipment->status;

        if ($from === $to) {
            return $shipment;
        }

        if (! $from->canMoveTo($to)) {
            throw ValidationException::withMessages([
                'status' => "انتقال غير مسموح: من «{$from->label()}» إلى «{$to->label()}».",
            ]);
        }

        return DB::transaction(function () use ($shipment, $from, $to, $actor, $options) {
            $attributes = [
                'status'            => $to,
                'status_changed_at' => now(),
            ];

            match ($to) {
                ShipmentStatus::PickedUp      => $attributes['picked_up_at'] = now(),
                ShipmentStatus::Delivered     => $attributes['delivered_at'] = now(),
                ShipmentStatus::Returned      => $attributes['returned_at'] = now(),
                ShipmentStatus::Cancelled     => $attributes['cancelled_at'] = now(),
                default                       => null,
            };

            if ($to === ShipmentStatus::FailedAttempt) {
                $attributes['attempts_count'] = $shipment->attempts_count + 1;
            }

            if (isset($options['failure_reason_id'])) {
                $attributes['last_failure_reason_id'] = $options['failure_reason_id'];
            }

            if (isset($options['courier_id'])) {
                $attributes['delivery_courier_id'] = $options['courier_id'];
                $attributes['assigned_at'] = now();
            }

            if (isset($options['hub_id'])) {
                $attributes['hub_id'] = $options['hub_id'];
            }

            // المبلغ المحصَّل يُثبَّت عند التسليم ولا يُعدَّل بعده
            if (in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered], true)) {
                $attributes['collected_amount'] = array_key_exists('collected_amount', $options)
                    ? (int) $options['collected_amount']
                    : $shipment->cod_amount;
            }

            $shipment->fill($attributes)->save();

            ShipmentEvent::create([
                'shipment_id'       => $shipment->id,
                'from_status'       => $from->value,
                'to_status'         => $to->value,
                'event_type'        => 'status_change',
                'actor_type'        => $options['actor_type'] ?? ($actor ? 'user' : 'system'),
                'actor_id'          => $actor?->id,
                'actor_name'        => $actor?->name,
                'courier_id'        => $options['courier_id'] ?? $shipment->delivery_courier_id,
                'hub_id'            => $options['hub_id'] ?? $shipment->hub_id,
                'failure_reason_id' => $options['failure_reason_id'] ?? null,
                'amount'            => $attributes['collected_amount'] ?? null,
                'note'              => $options['note'] ?? null,
                'lat'               => $options['lat'] ?? null,
                'lng'               => $options['lng'] ?? null,
                'ip'                => request()->ip(),
            ]);

            return $shipment->refresh();
        });
    }
}

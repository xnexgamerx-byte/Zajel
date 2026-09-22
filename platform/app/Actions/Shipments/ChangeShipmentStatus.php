<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Ledger;
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
    public function __construct(protected Ledger $ledger) {}

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

        /*
        | «واصل إجباري»: تسليم من غير مسار الحالات الطبيعي.
        |
        | أشيع تلاعب في هذا المجال أن يُعلَن التسليم من غير تسليم، ثم
        | يُسوّى النقد لاحقاً «عند الجباية». فالانتقال غير المسموح لا
        | يُفتَح إلّا بسبب مكتوب باسم صاحبه، ويبقى موسوماً في الشحنة
        | وفي تقرير يُقرأ.
        */
        if (! empty($options['force'])) {
            if (empty($options['forced_reason'])) {
                throw ValidationException::withMessages([
                    'forced_reason' => 'التسليم الإجباري لا يمرّ بلا سبب مكتوب.',
                ]);
            }

            if (in_array($from, ShipmentStatus::terminal(), true)) {
                throw ValidationException::withMessages([
                    'status' => "الشحنة في حالة نهائية «{$from->label()}» فلا تُفتَح بالإجبار.",
                ]);
            }
        } elseif (! $from->canMoveTo($to)) {
            throw ValidationException::withMessages([
                'status' => "انتقال غير مسموح: من «{$from->label()}» إلى «{$to->label()}».",
            ]);
        }

        // «راجعة للتاجر» تعني أن التاجر استلمها، وعندها تُقيَّد أجرة الراجع
        // عليه. تسليم طرد ما زال في حقيبة المندوب هو الخلاف نفسه الذي
        // يُبنى هذا المسار لمنعه.
        if ($to === ShipmentStatus::Returned && $shipment->return_received_at === null) {
            throw ValidationException::withMessages([
                'status' => "لم تُستلم الشحنة {$shipment->number} من المندوب بعد، فلا تُسلَّم للتاجر.",
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

            // طرد في المخزن قُرّر إرجاعه لم يغادر أصلاً: استلامه من المندوب
            // خطوة لا وجود لها، فلا تُفرض على الموظّف.
            if ($to === ShipmentStatus::Returning && $from === ShipmentStatus::AtHub) {
                $attributes['return_received_at'] = now();
                $attributes['return_received_by_user_id'] = $actor?->id;
            }

            if (! empty($options['force'])) {
                $attributes['is_forced'] = true;
                $attributes['forced_reason'] = $options['forced_reason'];
                $attributes['forced_by_user_id'] = $actor?->id;
            }

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

            // المبلغ المحصَّل يُثبَّت عند التسليم ولا يُعدَّل بعده،
            // ومعه تُجمَّد عمولة المندوب ويُعاد حساب مستحقّ التاجر على
            // أساس ما حُصِّل فعلاً لا ما كان مطلوباً.
            if (in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered], true)) {
                $attributes['collected_amount'] = array_key_exists('collected_amount', $options)
                    ? (int) $options['collected_amount']
                    : $shipment->cod_amount;

                $courier = $shipment->deliveryCourier;
                $attributes['courier_commission'] = $courier?->commission_per_delivery ?? 0;

                $attributes['merchant_due'] = $shipment->fees_paid_by === 'customer'
                    ? $attributes['collected_amount'] - $shipment->cod_fee + $shipment->discount
                    : $attributes['collected_amount'] - $shipment->total_fees;
            }

            if ($to === ShipmentStatus::Returned) {
                $courier = $shipment->deliveryCourier;
                $attributes['courier_commission'] = $courier?->commission_per_return ?? 0;
                $attributes['merchant_due'] = -$shipment->return_fee;
            }

            $shipment->fill($attributes)->save();

            ShipmentEvent::create([
                'shipment_id'       => $shipment->id,
                'from_status'       => $from->value,
                'to_status'         => $to->value,
                'event_type'        => empty($options['force']) ? 'status_change' : 'forced_status',
                'actor_type'        => $options['actor_type'] ?? ($actor ? 'user' : 'system'),
                'actor_id'          => $actor?->id,
                'actor_name'        => $actor?->name,
                'courier_id'        => $options['courier_id'] ?? $shipment->delivery_courier_id,
                'hub_id'            => $options['hub_id'] ?? $shipment->hub_id,
                'failure_reason_id' => $options['failure_reason_id'] ?? null,
                'amount'            => $attributes['collected_amount'] ?? null,
                'note'              => empty($options['force'])
                    ? ($options['note'] ?? null)
                    : 'واصل إجباري — '.$options['forced_reason'],
                'lat'               => $options['lat'] ?? null,
                'lng'               => $options['lng'] ?? null,
                'ip'                => request()->ip(),
            ]);

            $shipment->refresh();

            // المال يتحرّك بعد ثبوت الحالة، وداخل المعاملة نفسها:
            // إمّا أن تُسجَّل الحالة والحركة معاً أو لا يُكتب شيء.
            match ($to) {
                ShipmentStatus::Delivered,
                ShipmentStatus::PartiallyDelivered => $this->ledger->recordDelivery($shipment, $actor),
                ShipmentStatus::Returned           => $this->ledger->recordReturn($shipment, $actor),
                default                            => null,
            };

            return $shipment->refresh();
        });
    }
}

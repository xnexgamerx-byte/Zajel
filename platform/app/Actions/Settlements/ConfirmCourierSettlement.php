<?php

namespace App\Actions\Settlements;

use App\Models\CashBox;
use App\Models\CourierSettlement;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * لحظة انتقال المال: المندوب سلّم النقد، الشركة قبضت، الكشف يُقفَل.
 *
 * بعد هذا لا يُعدَّل الكشف. أي تصحيح يكون بحركة معاكسة في الدفتر —
 * لأن كشفاً يتغيّر بعد التوقيع عليه لا يصلح دليلاً في خلاف.
 */
class ConfirmCourierSettlement
{
    public function __construct(protected Ledger $ledger, protected CashBook $cash) {}

    public function handle(CourierSettlement $settlement, ?User $actor = null, int $deductions = 0, ?string $notes = null): CourierSettlement
    {
        if ($settlement->isLocked()) {
            throw ValidationException::withMessages([
                'status' => 'هذا الكشف مُقفَل بالفعل.',
            ]);
        }

        return DB::transaction(function () use ($settlement, $actor, $deductions, $notes) {
            $settlement->forceFill([
                'deductions'           => $deductions,
                'net_amount'           => $settlement->cod_total - $settlement->commission_total + $deductions,
                'notes'                => $notes,
                'status'               => 'confirmed',
                'confirmed_at'         => now(),
                'confirmed_by_user_id' => $actor?->id,
                'paid_at'              => now(),
            ])->save();

            // وسم الشحنات يمنع دخولها كشفاً ثانياً
            $shipmentIds = $settlement->lines()->pluck('shipment_id');

            Shipment::whereIn('id', $shipmentIds)->update([
                'courier_settlement_id' => $settlement->id,
                'courier_settled_at'    => now(),
            ]);

            foreach ($shipmentIds as $shipmentId) {
                ShipmentEvent::create([
                    'shipment_id' => $shipmentId,
                    'from_status' => null,
                    'to_status'   => 'settled_with_courier',
                    'event_type'  => 'money',
                    'actor_type'  => $actor ? 'user' : 'system',
                    'actor_id'    => $actor?->id,
                    'actor_name'  => $actor?->name,
                    'note'        => "دخلت كشف المندوب {$settlement->code}",
                ]);
            }

            $this->ledger->recordCourierHandover($settlement, $actor);
            $this->recordInCashBox($settlement, $actor);

            return $settlement->refresh();
        });
    }

    /**
     * الدرج يستقبل ما سلّمه المندوب ويدفع عمولته — حركتان لا واحدة
     * صافية، لأن «كم جلب المندوبون اليوم» و«كم دفعنا عمولات» سؤالان.
     * بلا صندوق مفعّل لا حركة: الدفتر المحاسبي كامل على أي حال.
     */
    protected function recordInCashBox(CourierSettlement $settlement, ?User $actor): void
    {
        $box = CashBox::forBranch($settlement->branch_id);

        if (! $box) {
            return;
        }

        $this->cash->in(
            box: $box,
            category: 'courier_handover',
            amount: $settlement->cod_total + $settlement->deductions,
            description: "تسليم نقد المندوب {$settlement->courier?->name} — كشف {$settlement->code}",
            actor: $actor,
            referenceType: 'courier_settlement',
            referenceId: $settlement->id,
        );

        $this->cash->out(
            box: $box,
            category: 'commission_paid',
            amount: $settlement->commission_total,
            description: "عمولة المندوب {$settlement->courier?->name} — كشف {$settlement->code}",
            actor: $actor,
            referenceType: 'courier_settlement',
            referenceId: $settlement->id,
        );
    }
}

<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\CourierSettlementShipment;
use App\Models\MerchantSettlementShipment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تأكيد مبلغ الوصل — خطوة لا رجعة فيها.
 *
 * المبلغ المكتوب على الوصل والمبلغ المقبوض فعلاً لا يتطابقان دائماً:
 * الزبون فاصل، أو استلم قطعة وردّ أخرى. الخلاف المالي لا يبدأ من هذا
 * الفرق بل من تعديله بعد أن بُنيت عليه تسوية. فهنا يُراجَع الرقم مرّة
 * واحدة ويُقفَل، ويُقيَّد فرقه في الدفتر بحركة تُقرأ.
 */
class ConfirmAmount
{
    public function __construct(protected Ledger $ledger) {}

    /**
     * هل الشحنة في كشف تسوية؟
     *
     * الكشف يُجمّد المبلغ في سطره ساعةَ يُبنى لا ساعةَ يُقفَل، ولا يوسم
     * الشحنة إلا عند الإقفال. فالنظر إلى الوسم وحده يترك ثغرة المسوّدة:
     * كشف بُني بخمسين ألفاً، صُحّح المبلغ إلى خمسة وأربعين، ثم وقّع
     * المندوب على الخمسين.
     */
    protected function onSettlementSheet(Shipment $shipment): bool
    {
        if ($shipment->courier_settlement_id || $shipment->merchant_settlement_id) {
            return true;
        }

        $open = fn (string $model) => $model::where('shipment_id', $shipment->id)
            ->whereHas('settlement', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->exists();

        return $open(CourierSettlementShipment::class) || $open(MerchantSettlementShipment::class);
    }

    public function handle(Shipment $shipment, int $amount, ?User $actor = null, ?string $note = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $amount, $actor, $note) {
            /*
            | الحال من القاعدة بعد القفل، والفرقُ من المبلغ الذي فيها.
            |
            | كان «مؤكَّد سابقاً» يُفحَص على النسخة التي بيد المستدعي، والفرق
            | يُحسب من مبلغها القديم — فتأكيدان متزامنان يمرّان ويُطبّقان
            | التصحيح مرّتين: ٥ آلاف تصير عشرة في حساب التاجر.
            */
            $shipment->setRawAttributes(
                Shipment::query()->lockForUpdate()->findOrFail($shipment->id)->getAttributes(),
                true,
            );

            return $this->confirm($shipment, $amount, $actor, $note);
        });
    }

    protected function confirm(Shipment $shipment, int $amount, ?User $actor, ?string $note): Shipment
    {
        if ($shipment->amount_confirmed) {
            throw ValidationException::withMessages([
                'amount' => 'مبلغ هذا الوصل مؤكَّد سابقاً، ولا يُعدَّل بعد التأكيد.',
            ]);
        }

        if (! in_array($shipment->status, [ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered], true)) {
            throw ValidationException::withMessages([
                'amount' => 'يُؤكَّد المبلغ بعد تسليم الشحنة فقط.',
            ]);
        }

        if ($this->onSettlementSheet($shipment)) {
            throw ValidationException::withMessages([
                'amount' => 'دخلت هذه الشحنة كشف تسوية. التصحيح يكون بحركة على الحساب لا بتعديل الوصل.',
            ]);
        }

        if ($amount < 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ لا يكون سالباً.']);
        }

        return DB::transaction(function () use ($shipment, $amount, $actor, $note) {
            $before = (int) $shipment->collected_amount;
            $delta = $amount - $before;

            $shipment->forceFill([
                'collected_amount'           => $amount,
                'merchant_due'               => $shipment->merchant_due + $delta,
                'amount_confirmed'           => true,
                'amount_confirmed_at'        => now(),
                'amount_confirmed_by_user_id' => $actor?->id,
            ])->save();

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => $shipment->status->value,
                'to_status'   => $shipment->status->value,
                'event_type'  => 'amount_confirmed',
                'actor_type'  => $actor ? 'user' : 'system',
                'actor_id'    => $actor?->id,
                'actor_name'  => $actor?->name,
                'courier_id'  => $shipment->delivery_courier_id,
                'amount'      => $amount,
                'note'        => $delta === 0
                    ? ($note ?: 'تأكيد المبلغ بلا تغيير')
                    : trim('تصحيح المبلغ من '.number_format($before).' إلى '.number_format($amount).'. '.$note),
                'ip'          => request()->ip(),
            ]);

            $this->ledger->recordAmountCorrection($shipment->refresh(), $delta, $actor, $note);

            return $shipment->refresh();
        });
    }
}

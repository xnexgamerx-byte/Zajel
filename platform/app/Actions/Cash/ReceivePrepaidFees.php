<?php

namespace App\Actions\Cash;

use App\Enums\ShipmentStatus;
use App\Models\CashBox;
use App\Models\Merchant;
use App\Models\PrepaidReceipt;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\CashBook;
use App\Services\SequenceGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «استلام أجور مدفوعة مقدّماً» (docs/plan/22 §٣): تاجرٌ يدفع أجور شحناته حين
 * يُرسلها فلا تُخصم من مبالغها عند التسليم.
 *
 * المقبوض يدخل الصندوق الآن بإيصال، ويُكتب على كل شحنةٍ ما دُفع عنها
 * (prepaid_amount) فيدخل مستحقّها — ويُقيَّد في حساب التاجر حين تُسلَّم أو ترجع
 * (Ledger::postPrepaid)، فتدفعه تسويته المبنيّة على الشحنات مع مستحقّها.
 *
 * ويُقبض لما بأيدينا ولم يُحسم بعد: قبل الاستلام قد تُلغى الشحنة أو تُمسح فلا
 * يُردّ للتاجر شيء، وبعد التسليم خُصمت أجرتها من مبلغها كالعادة. وما قُبضت
 * أجرته لا يُلغى (ChangeShipmentStatus) — يرجع، فيُحسب له ما دفع بعد أجرة الراجع.
 */
class ReceivePrepaidFees
{
    /** بأيدينا ولم يتحرّك مالها بعد */
    public const OPEN = [
        ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery,
        ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed, ShipmentStatus::Returning,
    ];

    public function __construct(
        protected CashBook $cash,
        protected SequenceGenerator $sequences,
    ) {}

    /** ما ينتظر القبض: مُعلَّمة «مدفوعة التوصيل مقدّماً»، أجرتها على التاجر، ولم يُقبض عنها شيء */
    public static function pending(Builder $query): Builder
    {
        return $query->where('shipments.fee_prepaid', true)
            ->whereNull('shipments.prepaid_receipt_id')
            ->where('shipments.fees_paid_by', 'merchant')
            ->where('shipments.total_fees', '>', 0)
            ->whereIn('shipments.status', array_map(fn (ShipmentStatus $s) => $s->value, self::OPEN));
    }

    /** @param  list<int>  $shipmentIds */
    public function handle(Merchant $merchant, array $shipmentIds, CashBox $box, User $actor, ?string $note = null): PrepaidReceipt
    {
        return DB::transaction(function () use ($merchant, $shipmentIds, $box, $actor, $note) {
            // الفحص بعد القفل: إيصالان متزامنان لا يقبضان أجرة الشحنة نفسها مرّتين
            $shipments = static::pending(Shipment::query())
                ->where('shipments.merchant_id', $merchant->id)
                ->whereIn('shipments.id', $shipmentIds)
                ->orderBy('shipments.id')
                ->lockForUpdate()
                ->get();

            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages([
                    'shipment_ids' => 'لا شحنة من المختارة تنتظر قبض أجرتها: قُبضت، أو حُسمت، أو ليست مدفوعة التوصيل مقدّماً.',
                ]);
            }

            $amount = (int) $shipments->sum('total_fees');

            $receipt = PrepaidReceipt::create([
                'branch_id'          => $merchant->branch_id,
                'number'             => $this->sequences->next('prepaid_receipt'),
                'merchant_id'        => $merchant->id,
                'cash_box_id'        => $box->id,
                'amount'             => $amount,
                'shipments_count'    => $shipments->count(),
                'note'               => $note,
                'created_by_user_id' => $actor->id,
            ]);

            foreach ($shipments as $shipment) {
                $fees = (int) $shipment->total_fees;

                $shipment->forceFill([
                    'prepaid_amount'     => $fees,
                    'prepaid_receipt_id' => $receipt->id,
                    // المستحقّ المقدَّر يزيد بما دفع: أجرةٌ دُفعت لا تُخصم ثانيةً
                    'merchant_due'       => (int) $shipment->merchant_due + $fees,
                ])->save();

                ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => $shipment->status->value,
                    'to_status'   => $shipment->status->value,
                    'event_type'  => 'prepaid_fee',
                    'actor_type'  => 'user',
                    'actor_id'    => $actor->id,
                    'actor_name'  => $actor->name,
                    'amount'      => $fees,
                    'note'        => 'قُبضت أجرتها مقدّماً: '.number_format($fees).' — إيصال '.$receipt->number,
                    'ip'          => request()->ip(),
                ]);
            }

            $this->cash->in(
                box: $box,
                category: 'prepaid_fee',
                amount: $amount,
                description: "أجور مدفوعة مقدّماً — {$merchant->business_name} — {$receipt->number}",
                actor: $actor,
                referenceType: 'prepaid_receipt',
                referenceId: $receipt->id,
            );

            return $receipt;
        });
    }
}

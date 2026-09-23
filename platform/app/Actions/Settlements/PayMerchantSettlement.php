<?php

namespace App\Actions\Settlements;

use App\Models\CashBox;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * خطوتان لا واحدة:
 *   confirm = اتّفقنا على الرقم وأُقفِل الكشف
 *   pay     = انتقل المال فعلاً
 *
 * دمجهما يعني كشفاً "مدفوعاً" قبل أن تصل الحوالة — وهذا أسوأ خطأ
 * محاسبي ممكن في علاقة مع تاجر.
 */
class PayMerchantSettlement
{
    public function __construct(protected Ledger $ledger, protected CashBook $cash) {}

    public function confirm(MerchantSettlement $settlement, ?User $actor = null, ?string $notes = null): MerchantSettlement
    {
        return DB::transaction(function () use ($settlement, $actor, $notes) {
            // الحال من القاعدة بعد القفل، لا من النسخة التي بيد المستدعي
            $this->claim($settlement);

            if ($settlement->isLocked()) {
                throw ValidationException::withMessages(['status' => 'هذا الكشف مُقفَل بالفعل.']);
            }

            $settlement->forceFill([
                'status'               => 'confirmed',
                'notes'                => $notes,
                'confirmed_at'         => now(),
                'confirmed_by_user_id' => $actor?->id,
            ])->save();

            $shipmentIds = $settlement->lines()->pluck('shipment_id');

            Shipment::whereIn('id', $shipmentIds)->update([
                'merchant_settlement_id' => $settlement->id,
                'merchant_settled_at'    => now(),
            ]);

            foreach ($shipmentIds as $shipmentId) {
                ShipmentEvent::create([
                    'shipment_id' => $shipmentId,
                    'from_status' => null,
                    'to_status'   => 'settled_with_merchant',
                    'event_type'  => 'money',
                    'actor_type'  => $actor ? 'user' : 'system',
                    'actor_id'    => $actor?->id,
                    'actor_name'  => $actor?->name,
                    'note'        => "دخلت كشف التاجر {$settlement->code}",
                ]);
            }

            return $settlement->refresh();
        });
    }

    public function pay(
        MerchantSettlement $settlement,
        ?User $actor = null,
        ?string $method = null,
        ?string $reference = null,
    ): MerchantSettlement {
        return DB::transaction(function () use ($settlement, $actor, $method, $reference) {
            /*
            | الفحص بعد القفل لا قبله.
            |
            | كان يُقرأ على النسخة التي بيد المستدعي خارج المعاملة، فضغطتان
            | على «دفعت» تمرّان معاً: قيد دفعٍ مرّتين في حساب التاجر، ونقدٌ
            | يخرج من الدرج مرّتين. جُرّب بنسختين فوقع.
            */
            $this->claim($settlement);

            if ($settlement->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'status' => $settlement->status === 'paid'
                        ? "الكشف {$settlement->code} مدفوعٌ سلفاً."
                        : 'أقفِل الكشف أولاً قبل تسجيل الدفع.',
                ]);
            }

            $settlement->forceFill([
                'status'           => 'paid',
                'payout_method'    => $method ?? $settlement->payout_method,
                'payout_reference' => $reference,
                'paid_at'          => now(),
                'paid_by_user_id'  => $actor?->id,
            ])->save();

            $this->ledger->recordMerchantPayout($settlement, $actor);
            $this->recordInCashBox($settlement, $actor);

            return $settlement->refresh();
        });
    }

    /**
     * يقفل صفّ الكشف ويملأ نسخة المستدعي بحاله في القاعدة الآن — فتبقى
     * نسخته هي التي تُحفَظ وتُعاد، ولا تفترق عن الصفّ.
     */
    protected function claim(MerchantSettlement $settlement): void
    {
        $settlement->setRawAttributes(
            MerchantSettlement::query()->lockForUpdate()->findOrFail($settlement->id)->getAttributes(),
            true,
        );
    }

    /**
     * حوالة زين كاش لا تُفرّغ الدرج. الصندوق يتحرّك بالنقد وحده، وإلّا
     * صار رصيده رقماً لا يُقارَن بعدّ اليد آخر اليوم.
     */
    protected function recordInCashBox(MerchantSettlement $settlement, ?User $actor): void
    {
        if ($settlement->payout_method !== 'cash') {
            return;
        }

        $box = CashBox::forBranch($settlement->branch_id);

        if (! $box) {
            return;
        }

        $this->cash->out(
            box: $box,
            category: 'merchant_payout',
            amount: (int) $settlement->net_amount,
            description: "دفع للتاجر {$settlement->merchant?->business_name} — كشف {$settlement->code}",
            actor: $actor,
            referenceType: 'merchant_settlement',
            referenceId: $settlement->id,
        );
    }
}

<?php

namespace App\Actions\Settlements;

use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
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
    public function __construct(protected Ledger $ledger) {}

    public function confirm(MerchantSettlement $settlement, ?User $actor = null, ?string $notes = null): MerchantSettlement
    {
        if ($settlement->isLocked()) {
            throw ValidationException::withMessages(['status' => 'هذا الكشف مُقفَل بالفعل.']);
        }

        return DB::transaction(function () use ($settlement, $actor, $notes) {
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
        if ($settlement->status !== 'confirmed') {
            throw ValidationException::withMessages([
                'status' => 'أقفِل الكشف أولاً قبل تسجيل الدفع.',
            ]);
        }

        return DB::transaction(function () use ($settlement, $actor, $method, $reference) {
            $settlement->forceFill([
                'status'           => 'paid',
                'payout_method'    => $method ?? $settlement->payout_method,
                'payout_reference' => $reference,
                'paid_at'          => now(),
                'paid_by_user_id'  => $actor?->id,
            ])->save();

            $this->ledger->recordMerchantPayout($settlement, $actor);

            return $settlement->refresh();
        });
    }
}

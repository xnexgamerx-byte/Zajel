<?php

namespace App\Actions\Settlements;

use App\Enums\ShipmentStatus;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\MerchantSettlementShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * كشف حساب التاجر: المسلَّم يزيده، والراجع يُنقصه بأجرته.
 *
 * الصافي قد يكون سالباً — تاجر رجعت أكثر شحناته يدين للشركة لا العكس،
 * وإخفاء ذلك يعني كشفاً لا يُصدَّق.
 */
class BuildMerchantSettlement
{
    public function __construct(protected SequenceGenerator $sequences) {}

    public function handle(Merchant $merchant, ?User $actor = null, array $options = []): MerchantSettlement
    {
        return DB::transaction(function () use ($merchant, $actor, $options) {
            $shipments = $this->eligible($merchant, $options);

            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages([
                    'merchant_id' => "لا توجد شحنات غير مسوّاة للتاجر {$merchant->business_name}.",
                ]);
            }

            $settlement = MerchantSettlement::create([
                'merchant_id'        => $merchant->id,
                'branch_id'          => $merchant->branch_id,
                'code'               => $this->sequences->next('merchant_settlement'),
                'from_date'          => $options['from'] ?? $shipments->min('status_changed_at')?->toDateString(),
                'to_date'            => $options['to'] ?? now()->toDateString(),
                'status'             => 'draft',
                'payout_method'      => $merchant->payout_method,
                'created_by_user_id' => $actor?->id,
            ]);

            $returned = 0;

            foreach ($shipments as $shipment) {
                $isReturn = $shipment->status === ShipmentStatus::Returned;
                $returned += $isReturn ? 1 : 0;

                MerchantSettlementShipment::create([
                    'merchant_settlement_id' => $settlement->id,
                    'shipment_id'            => $shipment->id,
                    'shipment_status'        => $shipment->status->value,
                    'collected_amount'       => $isReturn ? 0 : $shipment->collected_amount,
                    'delivery_fee'           => $isReturn ? 0 : $shipment->delivery_fee + $shipment->extra_fee,
                    'return_fee'             => $isReturn ? $shipment->return_fee : 0,
                    'cod_fee'                => $isReturn ? 0 : $shipment->cod_fee,
                    'net_amount'             => $shipment->merchant_due,
                ]);
            }

            $delivered = $shipments->reject(fn (Shipment $s) => $s->status === ShipmentStatus::Returned);

            $settlement->forceFill([
                'shipments_count'     => $shipments->count(),
                'returned_count'      => $returned,
                'cod_total'           => (int) $delivered->sum('collected_amount'),
                'delivery_fees_total' => (int) $delivered->sum(fn (Shipment $s) => $s->delivery_fee + $s->extra_fee),
                'cod_fees_total'      => (int) $delivered->sum('cod_fee'),
                'return_fees_total'   => (int) $shipments
                    ->filter(fn (Shipment $s) => $s->status === ShipmentStatus::Returned)
                    ->sum('return_fee'),
                'net_amount'          => (int) $shipments->sum('merchant_due'),
            ])->save();

            return $settlement;
        });
    }

    /** المسلَّم والراجع الذي لم يدخل كشفاً بعد. */
    public function eligible(Merchant $merchant, array $options = [])
    {
        return Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->whereNull('merchant_settlement_id')
            ->whereIn('status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
            ])
            ->when($options['from'] ?? null, fn ($q, $from) => $q->whereFromDate('status_changed_at', $from))
            ->when($options['to'] ?? null, fn ($q, $to) => $q->whereUntilDate('status_changed_at', $to))
            ->orderBy('id')
            ->get();
    }
}

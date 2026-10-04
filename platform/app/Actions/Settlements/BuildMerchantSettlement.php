<?php

namespace App\Actions\Settlements;

use App\Enums\ShipmentStatus;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\MerchantSettlement;
use App\Models\MerchantSettlementShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SequenceGenerator;
use Illuminate\Database\Eloquent\Builder;
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
            /*
            | كشف مفتوح واحد لكل طرف.
            |
            | الشحنات لا تُوسَم إلّا عند الإقفال، فبناء كشف ثانٍ قبل إقفال
            | الأول يلتقط الشحنات نفسها: كشفان بالمبلغ نفسه، وإقفالهما
            | يدفع مرّتين. وُجد بالتجربة على ١٦٣٧٦ شحنة و٩٧٣ مليوناً.
            */
            $open = MerchantSettlement::where('merchant_id', $merchant->id)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($open) {
                throw ValidationException::withMessages([
                    'merchant_id' => "للتاجر {$merchant->business_name} كشفٌ مسودّة ({$open->code}). أقفِله أو احذفه من صفحته قبل بناء كشف جديد.",
                ]);
            }

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

            foreach ($shipments as $shipment) {
                MerchantSettlementShipment::create(static::line($settlement, $shipment));
            }

            static::refreshTotals($settlement);

            // طلب الدفع المفتوح يُجاب بهذا الكشف: يُغلق ويُربط به
            MerchantRequest::query()
                ->where('merchant_id', $merchant->id)
                ->ofType('payment')
                ->open()
                ->update([
                    'status'                 => 'handled',
                    'handled_at'             => now(),
                    'handled_by_user_id'     => $actor?->id,
                    'merchant_settlement_id' => $settlement->id,
                ]);

            return $settlement;
        });
    }

    /**
     * سطر الكشف: لقطةٌ مجمَّدة من الشحنة ساعةَ تدخله — عند البناء، وحين تُضاف إلى المسودّة.
     * باقي الواصل الجزئي سطرُ تسليمٍ بما حُصِّل وأجوره كاملةً، لا سطرُ راجع.
     */
    public static function line(MerchantSettlement $settlement, Shipment $shipment): array
    {
        $isReturn = $shipment->isPlainReturn();

        return [
            'merchant_settlement_id' => $settlement->id,
            'shipment_id'            => $shipment->id,
            'shipment_status'        => $shipment->wasDelivered() && $shipment->status !== ShipmentStatus::Delivered
                ? ShipmentStatus::PartiallyDelivered->value
                : $shipment->status->value,
            'collected_amount'       => $isReturn ? 0 : $shipment->collected_amount,
            'delivery_fee'           => $isReturn ? 0 : $shipment->delivery_fee + $shipment->extra_fee,
            'return_fee'             => $isReturn ? $shipment->return_fee : 0,
            'cod_fee'                => $isReturn ? 0 : $shipment->cod_fee,
            'net_amount'             => $shipment->merchant_due,
        ];
    }

    /**
     * مجاميع الكشف من سطوره: عند البناء، وبعد كل إخراجٍ أو إضافةٍ في المسودّة. والراجع
     * سطرُه «راجع» بأجرة رجوعه وحدها، فالمسلَّم ما سواه (line).
     */
    public static function refreshTotals(MerchantSettlement $settlement): void
    {
        $sums = $settlement->lines()->toBase()->selectRaw("count(*) as n,
            coalesce(sum(case when shipment_status = 'returned' then 1 else 0 end), 0) as returned,
            coalesce(sum(collected_amount), 0) as cod,
            coalesce(sum(delivery_fee), 0) as delivery,
            coalesce(sum(cod_fee), 0) as cod_fees,
            coalesce(sum(return_fee), 0) as return_fees,
            coalesce(sum(net_amount), 0) as net")->first();

        $settlement->forceFill([
            'shipments_count'     => (int) $sums->n,
            'returned_count'      => (int) $sums->returned,
            'cod_total'           => (int) $sums->cod,
            'delivery_fees_total' => (int) $sums->delivery,
            'cod_fees_total'      => (int) $sums->cod_fees,
            'return_fees_total'   => (int) $sums->return_fees,
            'net_amount'          => (int) $sums->net,
        ])->save();
    }

    /** المسلَّم والراجع الذي لم يدخل كشفاً بعد — والواصل الجزئي منذ تسليمه، وباقيه في طريقه. */
    public function eligible(Merchant $merchant, array $options = [])
    {
        return $this->eligibleQuery($merchant, $options)->get();
    }

    /** @return Builder<Shipment> */
    public function eligibleQuery(Merchant $merchant, array $options = []): Builder
    {
        return Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->whereNull('merchant_settlement_id')
            ->where(fn ($q) => $q->whereIn('status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
            ])->orWhereNotNull('delivered_at'))
            ->when($options['from'] ?? null, fn ($q, $from) => $q->whereFromDate('status_changed_at', $from))
            ->when($options['to'] ?? null, fn ($q, $to) => $q->whereUntilDate('status_changed_at', $to))
            ->orderBy('id');
    }
}

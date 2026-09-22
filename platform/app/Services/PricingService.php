<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PriceListRule;

/**
 * تسعير شحنة واحدة.
 *
 * تُختار القاعدة الأكثر تحديداً: منطقة > محافظة > قاعدة عامة،
 * ضمن مدى وزن مطابق. وإن لم توجد قاعدة، تُعاد أصفار ويُنبَّه المستخدم
 * بدل أن يُخترَع سعر من العدم.
 */
class PricingService
{
    /**
     * @return array{
     *   delivery_fee:int, extra_fee:int, cod_fee:int, total_fees:int,
     *   merchant_due:int, rule_id:?int, matched:bool
     * }
     */
    public function quote(
        Merchant $merchant,
        int $toGovernorateId,
        ?int $toCityId = null,
        int $weightGrams = 0,
        int $codAmount = 0,
        string $feesPaidBy = 'merchant',
        int $extraFee = 0,
        int $discount = 0,
    ): array {
        $priceList = $merchant->effectivePriceList();

        $rule = $priceList
            ? $this->resolveRule($priceList->id, $merchant->governorate_id, $toGovernorateId, $toCityId, $weightGrams)
            : null;

        $deliveryFee = $rule?->delivery_fee ?? 0;

        // كل كغم فوق الحد الأعلى للقاعدة يُحتسب إضافياً
        if ($rule && $rule->extra_kg_fee > 0 && $weightGrams > $rule->weight_to_grams) {
            $extraKg = (int) ceil(($weightGrams - $rule->weight_to_grams) / 1000);
            $extraFee += $extraKg * $rule->extra_kg_fee;
        }

        $codFee = 0;
        if ($rule && $codAmount > 0) {
            $codFee = (int) round($codAmount * ($rule->cod_fee_percent / 100)) + $rule->cod_fee_flat;
        }

        $totalFees = max(0, $deliveryFee + $extraFee + $codFee - $discount);

        $merchantDue = $feesPaidBy === 'customer'
            ? $codAmount - $codFee + $discount
            : $codAmount - $totalFees;

        return [
            'delivery_fee' => $deliveryFee,
            'extra_fee'    => $extraFee,
            'cod_fee'      => $codFee,
            'total_fees'   => $totalFees,
            'merchant_due' => $merchantDue,
            'rule_id'      => $rule?->id,
            'matched'      => $rule !== null,
        ];
    }

    protected function resolveRule(
        int $priceListId,
        ?int $fromGovernorateId,
        int $toGovernorateId,
        ?int $toCityId,
        int $weightGrams,
    ): ?PriceListRule {
        $candidates = PriceListRule::query()
            ->where('price_list_id', $priceListId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('to_governorate_id')->orWhere('to_governorate_id', $toGovernorateId))
            ->where(fn ($q) => $q->whereNull('to_city_id')->orWhere('to_city_id', $toCityId))
            ->where(fn ($q) => $q->whereNull('from_governorate_id')->orWhere('from_governorate_id', $fromGovernorateId))
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $inBand = $candidates->filter(
            fn (PriceListRule $r) => $weightGrams >= $r->weight_from_grams && $weightGrams <= $r->weight_to_grams
        );

        // شحنة أثقل من كل الشرائح لا تُسعَّر بصفر: تُؤخذ أعلى شريحة أساساً
        // ويُحتسب الفائض بأجرة الكيلو الزائد.
        if ($inBand->isEmpty()) {
            $maxBand = $candidates->max('weight_to_grams');
            $inBand = $candidates->where('weight_to_grams', $maxBand);
        }

        return $inBand
            ->sortByDesc(fn (PriceListRule $r) => [$r->priority, $r->specificity()])
            ->first();
    }
}

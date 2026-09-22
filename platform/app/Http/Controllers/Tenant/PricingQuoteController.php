<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسعير فوري أثناء ملء نموذج الشحنة — الموظّف يرى الأجرة
 * ومستحقّ التاجر قبل الحفظ لا بعده.
 */
class PricingQuoteController extends Controller
{
    public function __invoke(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'merchant_id'    => ['required', 'integer'],
            'governorate_id' => ['required', 'integer'],
            'city_id'        => ['nullable', 'integer'],
            'weight_grams'   => ['nullable', 'integer', 'min:0'],
            'cod_amount'     => ['nullable', 'integer', 'min:0'],
            'fees_paid_by'   => ['nullable', 'in:merchant,customer'],
            'extra_fee'      => ['nullable', 'integer', 'min:0'],
            'discount'       => ['nullable', 'integer', 'min:0'],
        ]);

        $merchant = Merchant::find($data['merchant_id']);

        if (! $merchant) {
            return response()->json(['message' => 'التاجر غير موجود.'], 404);
        }

        return response()->json($pricing->quote(
            merchant: $merchant,
            toGovernorateId: (int) $data['governorate_id'],
            toCityId: $data['city_id'] ?? null,
            weightGrams: (int) ($data['weight_grams'] ?? 0),
            codAmount: (int) ($data['cod_amount'] ?? 0),
            feesPaidBy: $data['fees_paid_by'] ?? 'merchant',
            extraFee: (int) ($data['extra_fee'] ?? 0),
            discount: (int) ($data['discount'] ?? 0),
        ));
    }
}

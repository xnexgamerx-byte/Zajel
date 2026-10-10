<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Merchants\SubmitMerchantRequest;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\MerchantSettlement;
use App\Models\Transaction;
use App\Support\MerchantBalance;
use App\Support\PayoutMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «المالية» في تطبيق التاجر (docs/plan/54): حسابه بالأرقام نفسها التي في البوابة وعند الشركة
 * (MerchantBalance)، وكشوفه ودفعاتها وتأكيد استلامها، وحركات حسابه، وطلب المحاسبة بطريقة دفعه.
 */
class FinanceController extends Controller
{
    private const SETTLEMENT_STATUS = [
        'draft' => 'قيد الإعداد',
        'confirmed' => 'بانتظار الدفع',
        'paid' => 'مدفوع',
        'cancelled' => 'ملغى',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $balance = MerchantBalance::of($merchant);
        $open = MerchantRequest::where('merchant_id', $merchant->id)->ofType('payment')->open()->latest('id')->first();

        return response()->json([
            'balance' => $balance->toArray(),
            'request' => $open ? [
                'number' => $open->number,
                'amount' => (int) $open->amount,
                'method' => Merchant::PAYOUT_METHODS[$open->payout_method] ?? $open->payout_method,
                'at' => $open->created_at->toIso8601String(),
            ] : null,
            'methods' => collect(PayoutMethods::offered())->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
                'details' => PayoutMethods::needsDetails($value),
                'hint' => PayoutMethods::DETAILS_HINT[$value] ?? null,
            ])->values(),
            'method' => $merchant->payout_method,
            // المحفوظ عنده لا يُعاد كاملاً: آخره ليعرفه
            'account' => filled($merchant->payout_account) ? '…'.mb_substr((string) $merchant->payout_account, -4) : null,
            'statements' => MerchantSettlement::where('merchant_id', $merchant->id)
                ->where('status', '!=', 'cancelled')
                ->latest('id')->limit(10)->get()
                ->map(fn (MerchantSettlement $s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'status' => self::SETTLEMENT_STATUS[$s->status] ?? $s->status,
                    'paid' => $s->status === 'paid',
                    'net' => (int) $s->net_amount,
                    'count' => (int) $s->shipments_count,
                    'advance' => (int) $s->advance_deduction,
                    'at' => ($s->paid_at ?? $s->confirmed_at ?? $s->created_at)?->toIso8601String(),
                    'reference' => $s->payout_reference,
                    'confirmed' => $s->merchant_confirmed_at !== null,
                ])->values(),
            'movements' => Transaction::forAccount('merchant', $merchant->id)
                ->with('shipment:id,number')
                ->latest('id')->limit(30)->get()
                ->map(fn (Transaction $t) => [
                    'label' => $t->description ?: $t->categoryLabel(),
                    'amount' => $t->signedAmount(),
                    'shipment' => $t->shipment?->number,
                    'at' => $t->created_at->toIso8601String(),
                ])->values(),
        ]);
    }

    /** «اطلب محاسبة»: بقواعد البوابة نفسها — المتاح للسحب وحده، وطريقةٌ تعرضها الشركة، وطلبٌ مفتوح واحد */
    public function request(Request $request, SubmitMerchantRequest $submit): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        $data = $request->validate([
            'payout_method' => ['nullable', Rule::in(array_keys(PayoutMethods::offered()))],
            'payout_details' => ['nullable', 'string', 'max:255'],
            'via_pickup_courier' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['payout_method.in' => 'طريقة الدفع هذه لا تتعامل بها الشركة — اختر غيرها.'],
            ['payout_method' => 'طريقة الدفع', 'payout_details' => 'تفاصيل الدفع', 'note' => 'الملاحظة']);

        $method = $data['payout_method'] ?? $merchant->payout_method;

        if (PayoutMethods::needsDetails($method) && blank($data['payout_details'] ?? null) && blank($merchant->payout_account)) {
            throw ValidationException::withMessages(['payout_details' => 'اكتب رقم البطاقة أو المحفظة واسم صاحبها.']);
        }

        $made = $submit->handle($merchant, 'payment', $data, $request->user());

        return response()->json([
            'number' => $made->number,
            'amount' => (int) $made->amount,
            'method' => Merchant::PAYOUT_METHODS[$made->payout_method] ?? $made->payout_method,
            'at' => $made->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * تفاصيل الكشف (docs/plan/61): شحناته سطراً سطراً — المحصَّل والأجور وما له — ومجاميعه، كما
     * طُبعت له. كشف تاجرٍ آخر «غير موجود».
     */
    public function statement(Request $request, MerchantSettlement $settlement): JsonResponse
    {
        abort_unless((int) $settlement->merchant_id === (int) $request->attributes->get('merchant')->id, 404);
        abort_if($settlement->status === 'cancelled', 404);

        $lines = $settlement->lines()->with('shipment:id,number,recipient_name,recipient_phone,status,delivered_at')
            ->orderBy('id')->limit(1000)->get();

        return response()->json([
            'id' => $settlement->id,
            'code' => $settlement->code,
            'status' => self::SETTLEMENT_STATUS[$settlement->status] ?? $settlement->status,
            'paid' => $settlement->status === 'paid',
            'confirmed' => $settlement->merchant_confirmed_at !== null,
            'at' => ($settlement->paid_at ?? $settlement->confirmed_at ?? $settlement->created_at)?->toIso8601String(),
            'reference' => $settlement->payout_reference,
            'totals' => [
                'count' => (int) $settlement->shipments_count,
                'collected' => (int) $settlement->cod_total,
                'delivery' => (int) $settlement->delivery_fees_total,
                'returns' => (int) $settlement->return_fees_total,
                'advance' => (int) $settlement->advance_deduction,
                'net' => (int) $settlement->net_amount,
            ],
            'lines' => $lines->map(fn ($line) => [
                'shipment_id' => $line->shipment_id,
                'number' => $line->shipment?->number,
                'name' => $line->shipment?->recipient_name ?: $line->shipment?->recipient_phone,
                'returned' => $line->shipment && ! $line->shipment->wasDelivered(),
                'collected' => (int) $line->collected_amount,
                'delivery' => (int) $line->delivery_fee,
                'return_fee' => (int) $line->return_fee,
                'net' => (int) $line->net_amount,
            ])->values(),
        ]);
    }

    /** «استلمتُها»: يؤكّد أن دفعة الكشف وصلته */
    public function confirm(Request $request, MerchantSettlement $settlement): JsonResponse
    {
        abort_unless((int) $settlement->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        $confirmed = MerchantSettlement::whereKey($settlement->id)->where('status', 'paid')->whereNull('merchant_confirmed_at')
            ->update(['merchant_confirmed_at' => now()]);

        return $confirmed
            ? response()->json(['message' => "أكّدت استلام دفعة الكشف {$settlement->code}."])
            : response()->json(['message' => "الكشف {$settlement->code} لم يُدفع بعد أو أكّدته سلفاً."], 422);
    }
}

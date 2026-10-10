<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Waybills\CreateFromWaybill;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\ShipmentController as PortalShipmentController;
use App\Http\Requests\Api\AppShipmentRequest;
use App\Models\City;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\WaybillBook;
use App\Services\Orders\Speech\SpeechToText;
use App\Services\PricingService;
use App\Support\FeatureGate;
use App\Support\ShipmentFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «طلب جديد» في تطبيق التاجر (docs/plan/52): نموذج بوابة التاجر نفسه — حقوله وقواعده
 * وتسعيره وأرقام وصولاته المطبوعة — بطلباتٍ يقرؤها الهاتف.
 */
class CreateShipmentController extends Controller
{
    /** ما يحتاجه النموذج مرّةً: المحافظات، والأحجام والأنواع، وما تُلزِم به الشركة */
    public function form(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $goods = $merchant->goodsTypeLabel();

        return response()->json([
            'governorates' => Governorate::offered()->get(['id', 'name_ar'])
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name_ar])->values(),
            // محافظة التاجر أوّلاً: أغلب طلباته إليها
            'home'     => $merchant->governorate_id,
            'sizes'    => collect(\App\Models\Shipment::SIZES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'types'    => collect(\App\Models\Shipment::TYPES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'required' => ShipmentFields::required(),
            'waybills' => FeatureGate::enabled(Feature::Waybills),
            // بطاقتا «بالذكاء الاصطناعي» و«بالتسجيل الصوتي»: القراءة ميزةٌ للشركة، والسماع مفتاحٌ على الخادم
            'reading'   => $reading = FeatureGate::enabled(Feature::OrderReading),
            'listening' => $reading && app(SpeechToText::class)->available(),
            'goods'    => $goods && ! in_array($merchant->goods_type, ['general', 'other'], true) ? $goods : 'ملابس',
        ]);
    }

    /** مناطق المحافظة للاختيار — بالبحث في التطبيق */
    public function areas(Request $request): JsonResponse
    {
        $governorate = (int) $request->query('governorate');

        return response()->json([
            'areas' => City::where('governorate_id', $governorate)->where('is_active', true)
                ->orderBy('name_ar')->get(['id', 'name_ar'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values(),
        ]);
    }

    /** «يصلك»: أجرة التوصيل بتسعيرته وما يبقى له من السعر — قبل أن يحفظ */
    public function quote(Request $request, PricingService $pricing): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $data = $request->validate([
            'governorate_id' => ['required', 'integer'],
            'city_id'        => ['nullable', 'integer'],
            'cod_amount'     => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'size'           => ['nullable', 'in:'.implode(',', array_keys(\App\Models\Shipment::SIZES))],
        ]);

        if (! Governorate::offered()->whereKey($data['governorate_id'])->exists()) {
            return response()->json(['message' => 'شركتك لا تشحن إلى هذه المحافظة الآن.'], 422);
        }

        $cod = (int) ($data['cod_amount'] ?? 0);
        $quote = $pricing->quote(
            merchant: $merchant,
            toGovernorateId: (int) $data['governorate_id'],
            toCityId: $data['city_id'] ?? null,
            codAmount: $cod,
            size: $data['size'] ?? 'normal',
        );
        $totals = PricingService::totals($cod, 'merchant', $quote['delivery_fee'], $quote['extra_fee'], $quote['cod_fee'], 0);

        return response()->json([
            'delivery_fee' => (int) $quote['delivery_fee'] + (int) $quote['extra_fee'],
            'cod_fee'      => (int) $quote['cod_fee'],
            'fees'         => (int) $totals['total_fees'],
            'due'          => (int) $totals['merchant_due'],
        ]);
    }

    /**
     * الوصل الممسوح بكاميرا الهاتف (الزرّ الأوسط — docs/plan/52): يُقبل قبل كتابة بياناته إن كان
     * من وصولاته المطبوعة ولم يُستعمل — فلا يكتب التاجر طلباً كاملاً ثم يُرفض وصله.
     */
    public function waybill(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $code = WaybillBook::normalise(WaybillBook::fromInput($request->query('code')));

        if ($code === '' || WaybillBook::serialOf($code) === null) {
            return response()->json(['message' => 'هذا ليس رقم وصلٍ مطبوع. امسح الباركود الذي على الوصل.'], 422);
        }

        if ($problem = PortalShipmentController::waybillProblem($code, $merchant->id)) {
            return response()->json(['message' => $problem], 422);
        }

        return response()->json(['code' => $code]);
    }

    public function store(AppShipmentRequest $request, CreateShipment $action, CreateFromWaybill $fromWaybill): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $data = $request->validated() + ['source' => 'merchant_app'];
        $code = FeatureGate::enabled(Feature::Waybills) ? WaybillBook::fromInput($data['waybill'] ?? null) : '';
        unset($data['waybill']);

        if ($code === '') {
            $shipment = $action->handle($data, $request->user());
        } else {
            // وصلٌ مطبوع على الطرد: كما في البوابة تماماً
            if ($problem = PortalShipmentController::waybillProblem($code, $merchant->id)) {
                throw ValidationException::withMessages(['waybill' => $problem]);
            }

            try {
                $shipment = $fromWaybill->handle($code, $data, $request->user());
            } catch (ValidationException) {
                throw ValidationException::withMessages(['waybill' => 'استُعمل هذا الوصل لشحنةٍ أخرى.']);
            }
        }

        $shipment->load(['governorate:id,name_ar', 'city:id,name_ar']);

        return response()->json([
            'shipment' => ShipmentController::row($shipment) + [
                'due'     => (int) $shipment->merchant_due,
                'fees'    => (int) $shipment->total_fees,
                'waybill' => $shipment->waybill_book_id ? $shipment->barcode : null,
            ],
        ], 201);
    }
}

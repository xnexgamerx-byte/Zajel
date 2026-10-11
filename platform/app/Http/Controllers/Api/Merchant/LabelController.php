<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ShipmentLabelController;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * «طباعة الوصولات» في تطبيق التاجر (docs/plan/63): طلباته المدخلة التي لم يستلمها مندوبٌ بعد —
 * يحدّدها كلّها أو بعضها، ويختار المقاس، فيُعطى رابطاً موقَّعاً لساعة يُطبع من متصفّح الهاتف.
 */
class LabelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shipments = Shipment::query()
            ->where('merchant_id', $request->attributes->get('merchant')->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, ShipmentLabelController::ENTERED))
            ->with(['governorate:id,name_ar', 'city:id,name_ar'])
            ->latest('id')
            ->limit(ShipmentLabelController::MAX)
            ->get();

        return response()->json([
            'shipments' => $shipments->map(fn (Shipment $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'name' => $s->recipient_name ?: $s->recipient_phone,
                'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
                'amount' => (int) $s->cod_amount,
                'at' => $s->created_at?->toIso8601String(),
            ])->values(),
            'sizes' => collect(ShipmentLabelController::SIZES)
                ->map(fn (array $size, string $key) => ['key' => $key, 'label' => $size[0]])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.ShipmentLabelController::MAX],
            'ids.*' => ['integer'],
            'size' => ['required', Rule::in(array_keys(ShipmentLabelController::SIZES))],
        ], [], ['ids' => 'الشحنات', 'size' => 'المقاس']);

        $merchant = $request->attributes->get('merchant');
        // شحناته وحدها: رقمٌ لغيره لا يدخل الرابط
        $ids = Shipment::query()->where('merchant_id', $merchant->id)->whereIn('id', $data['ids'])->orderBy('id')->pluck('id');

        abort_if($ids->isEmpty(), 422, 'اختر من شحناتك ما يُطبع.');

        return response()->json([
            'count' => $ids->count(),
            'url' => URL::temporarySignedRoute('app.labels', now()->addHour(), [
                'merchant' => $merchant->id, 'ids' => $ids->all(), 'size' => $data['size'],
            ]),
        ], 201);
    }
}

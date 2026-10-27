<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ShipmentLabelController;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * «الطابعة» في تطبيق التاجر (docs/plan/63، 79): طلباته الجديدة التي لم يستلمها مندوبٌ بعد، مفروزةً
 * «للطباعة» و«مطبوعة» — يحدّدها كلّها أو بعضها، ويختار المقاس، فيُعطى رابطاً موقَّعاً لساعة يطبعه
 * التطبيق نفسه، فتنتقل إلى «مطبوعة».
 */
class LabelController extends Controller
{
    /** «الطابعة»: الجديدة «للطباعة» ثم «مطبوعة» — ولكلٍّ عدّها */
    public const STATES = ['to_print', 'printed'];

    public function index(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $state = in_array($request->query('state'), self::STATES, true) ? (string) $request->query('state') : 'to_print';

        $shipments = self::entered($merchant->id)
            ->when($state === 'printed', fn ($q) => $q->whereNotNull('printed_at'), fn ($q) => $q->whereNull('printed_at'))
            ->with(['governorate:id,name_ar', 'city:id,name_ar'])
            ->when($state === 'printed', fn ($q) => $q->orderByDesc('printed_at'))
            ->latest('id')
            ->limit(ShipmentLabelController::MAX)
            ->get();

        return response()->json([
            'state' => $state,
            'counts' => self::counts($merchant->id),
            'shipments' => $shipments->map(fn (Shipment $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'name' => $s->recipient_name ?: $s->recipient_phone,
                'phone' => $s->recipient_phone,
                'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
                'amount' => (int) $s->cod_amount,
                'at' => $s->created_at?->toIso8601String(),
                'printed_at' => $s->printed_at?->toIso8601String(),
            ])->values(),
            // مقاسا التطبيق (docs/plan/87)، بعرض الملصق وطوله بالمليمتر
            'sizes' => collect(ShipmentLabelController::APP_SIZES)->map(fn (string $key) => [
                'key' => $key,
                'label' => ShipmentLabelController::SIZES[$key][0],
                'width' => ShipmentLabelController::SIZES[$key][1],
                'height' => ShipmentLabelController::SIZES[$key][2],
            ])->values(),
        ]);
    }

    /** الجديدة التي لم يستلمها مندوبٌ بعد — هي ما في «الطابعة» */
    public static function entered(int $merchantId): Builder
    {
        return Shipment::query()->where('merchant_id', $merchantId)->whereIn('status', ShipmentStatus::enteredValues());
    }

    /** @return array{to_print: int, printed: int} */
    public static function counts(int $merchantId): array
    {
        $printed = self::entered($merchantId)->whereNotNull('printed_at')->count();

        return ['to_print' => self::entered($merchantId)->count() - $printed, 'printed' => $printed];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.ShipmentLabelController::MAX],
            'ids.*' => ['integer'],
            'size' => ['required', Rule::in(ShipmentLabelController::APP_SIZES)],
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

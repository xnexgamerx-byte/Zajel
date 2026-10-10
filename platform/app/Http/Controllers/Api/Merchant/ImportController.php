<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Shipments\ImportShipments;
use App\Http\Controllers\Concerns\ImportsShipments;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Services\Import\ShipmentSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * «رفع شحنات من ملف» في تطبيق التاجر (docs/plan/57): استيراد البوابة نفسه — يُرفع الملف فيُعاين
 * (الصحيح والخاطئ بسببه) ولا يُنشأ شيء، ثم يؤكّد فتُنشأ — بالقراءة والتحقّق نفسيهما.
 */
class ImportController extends Controller
{
    use ImportsShipments;

    public function index(): JsonResponse
    {
        return response()->json([
            'columns'  => collect(ShipmentSheet::COLUMNS)->map(fn ($label, $field) => [
                'label' => $label, 'required' => in_array($field, ShipmentSheet::REQUIRED, true),
            ])->values(),
            // القالب يُنزَّل في متصفّح الهاتف برابطٍ موقَّعٍ لساعة
            'template' => URL::temporarySignedRoute('app.import.template', now()->addHour()),
        ]);
    }

    public function store(Request $request, ShipmentSheet $sheet, ImportShipments $import): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        return response()->json($this->summary($this->preview($request, $this->storeUpload($request), $merchant, $sheet, $import)));
    }

    public function confirm(Request $request, ShipmentSheet $sheet, ImportShipments $import): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $data = $request->validate(['path' => ['required', 'string'], 'skip_errors' => ['nullable', 'boolean']]);

        $rows = $this->preview($request, $data['path'], $merchant, $sheet, $import)['rows'];
        $failed = $rows->filter(fn ($row) => ! empty($row['errors']));

        if ($failed->isNotEmpty() && ! $request->boolean('skip_errors')) {
            return response()->json(['message' => "في الملف صفوف بأخطاء، عددها {$failed->count()}. صحّحه أو استورد الصفوف الصحيحة وحدها."], 422);
        }
        if ($failed->count() === $rows->count()) {
            return response()->json(['message' => 'لا صفّ صحيحاً في الملف — صحّحه وارفعه من جديد.'], 422);
        }

        $created = $import->handle($rows, $merchant, $request->user(), 'merchant_app');
        $this->forget($data['path']);

        $message = "أُنشئت شحناتك، عددها {$created->count()}. اطلب استلاماً متى جهّزت الطرود.";
        if ($failed->isNotEmpty()) {
            $message .= " وتُخطّيت صفوف بأخطاء، عددها {$failed->count()}.";
        }

        return response()->json(['created' => $created->count(), 'message' => $message], 201);
    }

    /** المعاينة للهاتف: كم صحّ وكم أخطأ، والخاطئ بسببه، وأوّل عشرين صحيحاً */
    private function summary(array $preview): array
    {
        $rows = $preview['rows'];
        [$bad, $good] = $rows->partition(fn ($row) => ! empty($row['errors']));
        $governorates = Governorate::whereIn('id', $good->pluck('data.governorate_id')->filter()->unique())->pluck('name_ar', 'id');
        $cities = City::whereIn('id', $good->pluck('data.city_id')->filter()->unique())->pluck('name_ar', 'id');

        return [
            'path'  => $preview['path'],
            'total' => $rows->count(),
            'good'  => $good->count(),
            'bad'   => $bad->values()->map(fn ($row) => [
                'row'    => $row['row'],
                'name'   => $row['data']['recipient_name'] ?? null ?: null,
                'errors' => array_values($row['errors']),
            ]),
            'rows' => $good->take(20)->values()->map(fn ($row) => [
                'row'    => $row['row'],
                'name'   => $row['data']['recipient_name'] ?? null ?: null,
                'phone'  => $row['data']['recipient_phone'] ?? null,
                'place'  => collect([
                    $governorates[$row['data']['governorate_id'] ?? 0] ?? null,
                    $cities[$row['data']['city_id'] ?? 0] ?? null,
                ])->filter()->implode(' · '),
                'amount' => (int) ($row['data']['cod_amount'] ?? 0),
            ]),
        ];
    }
}

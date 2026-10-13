<?php

namespace App\Http\Controllers\Api\Partner;

use App\Actions\Partners\PartnerStatusUpdate;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\PartnerKey;
use App\Models\PartnerReport;
use App\Models\Shipment;
use App\Support\Partners\PartnerPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * واجهة الشريك (docs/plan/64): يقرأ الطرود المسلَّمة له ويسجّل حالاتها — وهذا كل شيء.
 * لا مبالغ ولا أجور ولا تعديل على الطلب ولا تاجر ولا شيء من كشوفنا.
 */
class PartnerApiController extends Controller
{
    /** ما يُقبل من الحقول في طلب تحديث الحالة، والباقي يُرفض لا يُتجاهل */
    private const STATUS_FIELDS = ['status', 'note', 'lat', 'lng'];

    /** حالاتٌ تُرفق بسبب مكتوب: التأجيل والفشل والرجوع */
    private const NEEDS_NOTE = ['failed_attempt', 'postponed', 'returning'];

    public function me(Request $request): JsonResponse
    {
        /** @var PartnerKey $key */
        $key = $request->attributes->get('partner_key');

        $held = $this->held($key)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'partner' => $key->partner->name,
            'key' => $key->name,
            'abilities' => $key->abilities,
            'expires_at' => $key->expires_at->toIso8601String(),
            'held' => [
                'total' => (int) $held->sum(),
                'by_status' => $held,
            ],
            'pending_reports' => PartnerReport::query()->where('partner_key_id', $key->id)->pending()->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var PartnerKey $key */
        $key = $request->attributes->get('partner_key');
        $data = $request->validate([
            'barcode' => ['nullable', 'string', 'max:40'],
            'number' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $query = $this->held($key)->with(['governorate:id,name_ar', 'city:id,name_ar'])->orderByDesc('status_changed_at');

        if (filled($data['barcode'] ?? null)) {
            $query->where('barcode', $data['barcode']);
        }
        if (filled($data['number'] ?? null)) {
            $query->where('number', $data['number']);
        }
        if (filled($data['status'] ?? null)) {
            $status = PartnerPolicy::parse($data['status']);
            $query->where('status', $status?->value ?? '__none__');
        }

        $page = $query->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Shipment $s) => $this->resource($s))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        /** @var PartnerKey $key */
        $key = $request->attributes->get('partner_key');

        $shipment = $this->held($key)->with(['governorate:id,name_ar', 'city:id,name_ar'])->where('number', $number)->first();

        if ($shipment === null) {
            return response()->json(['message' => 'الشحنة غير موجودة عندكم.'], 404);
        }

        return response()->json(['data' => $this->resource($shipment)]);
    }

    public function status(Request $request, string $number, PartnerStatusUpdate $update): JsonResponse
    {
        /** @var PartnerKey $key */
        $key = $request->attributes->get('partner_key');

        $unknown = array_diff(array_keys($request->all()), self::STATUS_FIELDS);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fields' => 'حقولٌ غير مسموحة: '.implode('، ', $unknown).'. المسموح: الحالة والملاحظة والموقع.',
            ]);
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if (in_array($data['status'], self::NEEDS_NOTE, true) && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'اكتب سبب هذه الحالة في الملاحظة.']);
        }

        try {
            $result = $update->handle($key, $number, $data);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'الشحنة غير موجودة عندكم.'], 404);
        }

        if ($result['report'] !== null) {
            return response()->json([
                'message' => 'أُرسل التسليم لمراجعتنا، ولا يُحسب قبل موافقتنا.',
                'report' => ['id' => $result['report']->id, 'status' => 'pending'],
                'data' => $this->resource($result['shipment']->refresh()),
            ], 202);
        }

        return response()->json([
            'message' => 'سُجّلت الحالة.',
            'data' => $this->resource($result['shipment']->refresh()),
        ]);
    }

    /** طرودٌ عند شريك هذا المفتاح الآن، لا كل ما مرّ عليه */
    private function held(PartnerKey $key)
    {
        return Shipment::query()
            ->where('partner_id', $key->partner_id)
            ->whereIn('status', array_map(fn (ShipmentStatus $s) => $s->value, PartnerPolicy::HELD));
    }

    /** ما يراه الشريك من الطرد: هويته، والمستلم للتوصيل، وأين هو، وما ينتقل إليه من نظامه */
    private function resource(Shipment $shipment): array
    {
        $status = $shipment->status;

        return [
            'number' => $shipment->number,
            'barcode' => $shipment->barcode,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_changed_at' => $shipment->status_changed_at?->toIso8601String(),
            'recipient' => [
                'name' => $shipment->recipient_name,
                'phone' => $shipment->recipient_phone,
                'phone_alt' => $shipment->recipient_phone_alt,
            ],
            'address' => [
                'governorate' => $shipment->governorate?->name_ar,
                'city' => $shipment->city?->name_ar,
                'text' => $shipment->address,
                'landmark' => $shipment->landmark,
            ],
            'pieces' => (int) $shipment->pieces_count,
            'next_statuses' => array_map(fn (ShipmentStatus $s) => $s->value, PartnerPolicy::next($status)),
        ];
    }
}

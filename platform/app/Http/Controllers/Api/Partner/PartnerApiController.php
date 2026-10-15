<?php

namespace App\Http\Controllers\Api\Partner;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CourierOutcomeRules;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierApiKey;
use App\Models\FailureReason;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * واجهة الشركة الوسيطة (docs/plan/64): الوسيط مندوبٌ عندنا، ونظامه يفعل ما يفعله المندوب
 * بشحنةٍ بيده — يقرأها، ويسجّل ما جرى عند الباب، ويكتب أين وصلت — ولا شيء غيره.
 *
 * لا يرى التاجر ولا الأجور ولا الكشوف، ولا يغيّر المبلغ ولا العنوان ولا يُرجع شحنة: الإرجاع
 * قرار الكول سنتر بعد المحاولة الفاشلة، كما هو مع كل مندوب.
 */
class PartnerApiController extends Controller
{
    /**
     * ما يسجّله الوسيط على شحنته، وما الحالة التي يجب أن تكون فيها قبله.
     * «خرجت للتوصيل» أوّل خطوة: تصله «بالطريق» من عندنا، ثم يخرجها مندوبه فيقوله نظامه.
     */
    private const ACTIONS = [
        'out_for_delivery' => ['to' => ShipmentStatus::OutForDelivery, 'from' => ShipmentStatus::InTransit],
        'delivered' => ['to' => ShipmentStatus::Delivered, 'from' => ShipmentStatus::OutForDelivery],
        'failed_attempt' => ['to' => ShipmentStatus::FailedAttempt, 'from' => ShipmentStatus::OutForDelivery],
        'postponed' => ['to' => ShipmentStatus::Postponed, 'from' => ShipmentStatus::OutForDelivery],
    ];

    /** ما يُسجَّل على الشحنة في كل حالة */
    private const ACTIONS_BY_STATUS = [
        'in_transit' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered', 'failed_attempt', 'postponed'],
    ];

    /** ما يُقبل من الحقول في كل طلب، والباقي يُرفض لا يُتجاهل: لا مبلغ ولا عنوان ولا حالة غير هذه */
    private const STATUS_FIELDS = ['action', 'reason_id', 'note', 'delivery_code', 'lat', 'lng'];

    private const TRACK_FIELDS = ['note', 'lat', 'lng'];

    public function __construct(protected ChangeShipmentStatus $change) {}

    public function me(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $key = $this->key($request);

        $counts = $this->held($courier)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $count = fn (ShipmentStatus ...$s) => (int) collect($s)->sum(fn ($x) => $counts[$x->value] ?? 0);

        return response()->json([
            'courier' => ['name' => $courier->name, 'code' => $courier->code],
            'key' => ['name' => $key->name, 'abilities' => $key->abilities, 'expires_at' => $key->expires_at->toIso8601String()],
            'held' => [
                'total' => (int) $counts->sum(),
                'to_dispatch' => $count(ShipmentStatus::InTransit),
                'to_deliver' => $count(ShipmentStatus::OutForDelivery),
                'awaiting_our_decision' => $count(ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed),
                'to_return' => $count(ShipmentStatus::Returning, ShipmentStatus::PartiallyDelivered),
            ],
        ]);
    }

    /** أسباب عدم التسليم: نظام الوسيط يربط أسبابه بأرقامها */
    public function reasons(Request $request): JsonResponse
    {
        $reasons = FailureReason::availableFor($this->courier($request)->company_id)
            ->get(['id', 'name_ar', 'requires_note', 'allows_reschedule']);

        return response()->json(['data' => $reasons->map(fn (FailureReason $r) => [
            'id' => $r->id,
            'name' => $r->name_ar,
            'requires_note' => (bool) $r->requires_note,
            'for_postponed' => (bool) $r->allows_reschedule,
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:40'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'max:30'],
            'changed_since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $query = $this->held($this->courier($request))
            ->with(['governorate:id,name_ar', 'city:id,name_ar'])
            ->orderByDesc('status_changed_at')->orderByDesc('id');

        if (filled($data['number'] ?? null)) {
            $query->where('number', $data['number']);
        }
        if (filled($data['barcode'] ?? null)) {
            $query->where('barcode', $data['barcode']);
        }
        if (filled($data['status'] ?? null)) {
            $query->where('status', $data['status']);
        }
        if (filled($data['changed_since'] ?? null)) {
            $query->where('status_changed_at', '>=', $data['changed_since']);
        }

        $page = $query->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Shipment $s) => $this->resource($s))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $shipment = $this->held($this->courier($request))
            ->with(['governorate:id,name_ar', 'city:id,name_ar'])
            ->where('number', $number)->first();

        return $shipment
            ? response()->json(['data' => $this->resource($shipment)])
            : $this->notHere();
    }

    /** ما جرى عند الباب: واصل، أو لم يُسلَّم، أو تأجّل — بشروط المندوب نفسها */
    public function status(Request $request, string $number): JsonResponse
    {
        $this->rejectUnknown($request, self::STATUS_FIELDS);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(array_keys(self::ACTIONS))],
            'reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'delivery_code' => ['nullable', 'string', 'max:10'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $courier = $this->courier($request);
        $key = $this->key($request);
        $action = self::ACTIONS[$data['action']];
        $to = $action['to'];

        $shipment = DB::transaction(function () use ($number, $courier, $key, $to, $action, $data) {
            $shipment = Shipment::query()->where('number', $number)
                ->where('delivery_courier_id', $courier->id)->lockForUpdate()->first();

            if ($shipment === null) {
                return null;
            }

            if ($shipment->status !== $action['from']) {
                throw ValidationException::withMessages([
                    'action' => "الشحنة «{$shipment->status->label()}» الآن، ولا يُسجَّل عليها هذا من نظامكم.",
                ]);
            }

            $errors = CourierOutcomeRules::errors($shipment, $to, [
                'failure_reason_id' => $data['reason_id'] ?? null,
                'note' => $data['note'] ?? null,
                'delivery_code' => $data['delivery_code'] ?? null,
            ], null);

            if ($errors) {
                throw ValidationException::withMessages(self::apiFields($errors));
            }

            return $this->change->handle($shipment, $to, null, array_filter([
                'actor_type' => 'courier',
                'actor_name' => $this->actorName($courier, $key),
                'courier_id' => $courier->id,
                'failure_reason_id' => $to === ShipmentStatus::Delivered ? null : ($data['reason_id'] ?? null),
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ], fn ($v) => $v !== null));
        });

        if ($shipment === null) {
            return $this->notHere();
        }

        return response()->json([
            'message' => "سُجِّلت الشحنة {$shipment->number}: {$shipment->status->label()}.",
            'data' => $this->resource($shipment->load(['governorate:id,name_ar', 'city:id,name_ar'])),
        ]);
    }

    /** أين وصلت الشحنة عندكم (فرعٌ، مخزن، خطّ نقل) — بلا تغيير حالة، ويراه التاجر في مسارها */
    public function track(Request $request, string $number): JsonResponse
    {
        $this->rejectUnknown($request, self::TRACK_FIELDS);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $courier = $this->courier($request);
        $shipment = $this->held($courier)->where('number', $number)->first();

        if ($shipment === null) {
            return $this->notHere();
        }

        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'from_status' => $shipment->status->value,
            'to_status' => $shipment->status->value,
            'event_type' => 'tracking',
            'actor_type' => 'courier',
            'actor_name' => $this->actorName($courier, $this->key($request)),
            'courier_id' => $courier->id,
            'note' => trim($data['note']),
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'سُجِّل موقع الشحنة.'], 201);
    }

    /**
     * ما بيد هذا المندوب الوسيط الآن: ما وصله «بالطريق» وينتظر خروجه، وما يوصّله، وما ينتظر قرارنا
     * بعد محاولته، وراجعٌ لم يُعِده إلينا بعد. لا ما سُلِّم وانتهى، ولا شحنات غيره.
     */
    private function held(Courier $courier): Builder
    {
        return Shipment::query()
            ->where('delivery_courier_id', $courier->id)
            ->where(fn (Builder $q) => $q
                ->whereIn('status', [
                    ShipmentStatus::InTransit->value,
                    ShipmentStatus::OutForDelivery->value,
                    ShipmentStatus::FailedAttempt->value,
                    ShipmentStatus::Postponed->value,
                ])
                ->orWhere(fn (Builder $r) => $r
                    ->whereIn('status', [ShipmentStatus::Returning->value, ShipmentStatus::PartiallyDelivered->value])
                    ->whereNull('return_received_at')));
    }

    /** ما يراه الوسيط من الشحنة: ما يراه المندوب في شاشته، بلا تاجرٍ ولا أجور */
    private function resource(Shipment $shipment): array
    {
        $status = $shipment->status;
        $toReturn = in_array($status, [ShipmentStatus::Returning, ShipmentStatus::PartiallyDelivered], true);

        return [
            'number' => $shipment->number,
            'barcode' => $shipment->barcode,
            'type' => $shipment->type,
            'status' => $status->value,
            'status_label' => $shipment->statusLabel(),
            'status_changed_at' => $shipment->status_changed_at?->toIso8601String(),
            // المبلغ الذي يُقبض من الزبون عند التسليم، كما على الوصل
            'amount_to_collect' => $toReturn ? 0 : (int) $shipment->cod_amount,
            'needs_delivery_code' => filled($shipment->delivery_code),
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
            'description' => $shipment->description,
            'notes' => $shipment->notes,
            'is_fragile' => (bool) $shipment->is_fragile,
            'allow_open' => (bool) $shipment->allow_open,
            'return_to_us' => $toReturn,
            'actions' => self::ACTIONS_BY_STATUS[$status->value] ?? [],
        ];
    }

    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fields' => 'حقولٌ غير مسموحة: '.implode('، ', $unknown).'. المسموح: '.implode('، ', $allowed).'.',
            ]);
        }
    }

    /** أسماء حقول المندوب بأسماء حقول الواجهة */
    private static function apiFields(array $errors): array
    {
        return collect($errors)->mapWithKeys(fn ($message, $field) => [
            ['failure_reason_id' => 'reason_id', 'collected_amount' => 'action'][$field] ?? $field => $message,
        ])->all();
    }

    private function actorName(Courier $courier, CourierApiKey $key): string
    {
        return $courier->name.' — ربط آلي ('.$key->name.')';
    }

    private function notHere(): JsonResponse
    {
        // لا نكشف إن كانت الشحنة موجودةً عند غيركم
        return response()->json(['message' => 'الشحنة ليست عندكم.'], 404);
    }

    private function courier(Request $request): Courier
    {
        return $request->attributes->get('partner_courier');
    }

    private function key(Request $request): CourierApiKey
    {
        return $request->attributes->get('partner_key');
    }
}

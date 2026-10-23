<?php

namespace App\Http\Controllers\Api\Courier;

use App\Actions\Couriers\RecordCourierOutcome;
use App\Actions\Couriers\ScanParcel;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ShipmentTickets;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * الشحنة في تطبيق المندوب (docs/plan/66): ما تعرضه شاشته في الموقع، وما يسجّله عند الباب
 * بالقواعد نفسها (RecordCourierOutcome). كود التسليم لا يُعاد أبداً: يُطلب من الزبون فقط.
 */
class ShipmentController extends Controller
{
    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $courier = $this->courier($request);
        $this->ensureMine($shipment, $courier);

        return response()->json(['data' => self::detail($shipment, $courier)]);
    }

    /** الماسح والبحث: رقم الوصل أو باركوده أو رابط QR — يفتح شحنته إن كانت بيده */
    public function find(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $term = trim((string) $request->query('q'));

        $shipment = $term === '' ? null : Shipment::query()
            ->where(fn ($q) => $q->where('delivery_courier_id', $courier->id)->orWhere('pickup_courier_id', $courier->id))
            ->search($term)
            ->first(['shipments.id', 'shipments.number']);

        return $shipment
            ? response()->json(['data' => ['id' => $shipment->id, 'number' => $shipment->number]])
            : response()->json(['message' => "لا توجد شحنة بيدك بالرقم «{$term}»."], 404);
    }

    public function act(Request $request, Shipment $shipment, RecordCourierOutcome $outcome): JsonResponse
    {
        $courier = $this->courier($request);
        $this->ensureMine($shipment, $courier, delivery: true);

        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(RecordCourierOutcome::ACTIONS))],
            // المبلغ ليس للمندوب: لا يُرسَل أصلاً، فالواصل بمبلغ الشحنة والجزئي بما اعتُمد
            'collected_amount' => ['prohibited'],
            'failure_reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'delivery_code' => ['nullable', 'string', 'max:10'],
            // الطرد ممسوحٌ في يده (docs/plan/72)
            'scan' => ['nullable', 'string', 'max:300'],
            // موعد المؤجّلة (docs/plan/75): يُفحص في CourierOutcomeRules
            'until' => ['nullable', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ], [
            'collected_amount.prohibited' => 'المبلغ لا يتغيّر عند الباب. اطلب تغييره من الكول سنتر.',
        ], [
            'failure_reason_id' => 'السبب',
            'note' => 'الملاحظة',
            'until' => 'الموعد',
        ]);

        $done = $outcome->handle($courier, $request->user(), $shipment, $data['action'], $data);

        return response()->json([
            'message' => RecordCourierOutcome::message($done),
            'data' => self::detail($done, $courier),
        ]);
    }

    /**
     * «أوصلها الآن» (docs/plan/75): المؤجّلة بيده يُخرجها للتوصيل في موعدها — أو قبله إن طلب الزبون —
     * فتعود إلى «إعادة التوصيل» ويسجّل عليها عند الباب كغيرها.
     */
    public function resume(Request $request, Shipment $shipment, ChangeShipmentStatus $change): JsonResponse
    {
        $courier = $this->courier($request);
        $this->ensureMine($shipment, $courier, delivery: true);

        if ($shipment->status !== ShipmentStatus::Postponed) {
            throw ValidationException::withMessages(['action' => "الشحنة {$shipment->number} «{$shipment->statusLabel()}»: ليست مؤجّلة بيدك."]);
        }

        $done = $change->handle($shipment, ShipmentStatus::OutForDelivery, $request->user(), [
            'actor_type' => 'courier',
            'courier_id' => $courier->id,
            'redelivery' => true,
            'note' => 'أخرجها المندوب للتوصيل في موعدها',
        ]);

        return response()->json([
            'message' => "الشحنة {$done->number} معك للتوصيل الآن.",
            'data' => self::detail($done, $courier),
        ]);
    }

    /** «الزبون يريد يدفع غير المبلغ؟» — يُطلب من الكول سنتر ولا يُغيَّر عند الباب */
    public function openTicket(Request $request, Shipment $shipment, ShipmentTickets $tickets): JsonResponse
    {
        $courier = $this->courier($request);
        $this->ensureMine($shipment, $courier, delivery: true);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ShipmentTicket::KINDS))],
            'requested_amount' => ['required', 'integer', 'min:0', 'max:'.ShipmentTickets::MAX_AMOUNT],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'kind.required' => 'اختر: السعر تغيّر، أم أخذ الزبون جزءاً من الطلب؟',
            'reason.required' => 'اكتب ما قاله الزبون — به تقرّر الكول سنتر.',
        ], [
            'kind' => 'السبب', 'requested_amount' => 'المبلغ الذي سيدفعه الزبون', 'reason' => 'ما قاله الزبون',
        ]);

        // الحالة الافتراضية من قاعدة البيانات: تُقرأ بعد الإنشاء
        $ticket = $tickets->open($shipment, $courier, $request->user(), $data['kind'], (int) $data['requested_amount'], $data['reason'])->refresh();

        return response()->json([
            'message' => "أُرسل طلبك {$ticket->number} إلى الكول سنتر. لا تسلّم بالمبلغ الجديد حتى يُعتمد.",
            'data' => self::ticketData($ticket),
        ], 201);
    }

    /** حال طلب تغيير المبلغ في خرجته هذه: التطبيق يسأل وهو ينتظر */
    public function ticket(Request $request, Shipment $shipment): JsonResponse
    {
        $courier = $this->courier($request);
        $this->ensureMine($shipment, $courier, delivery: true);

        $ticket = self::currentTicket($shipment, $courier);

        return response()->json(['data' => $ticket ? self::ticketData($ticket) : null]);
    }

    /** أسباب عدم التسليم والتأجيل، بترتيب الشركة */
    public function reasons(Request $request): JsonResponse
    {
        $reasons = FailureReason::availableFor($request->user()->company_id)
            ->get(['id', 'name_ar', 'requires_note', 'allows_reschedule']);

        return response()->json(['data' => $reasons->map(fn (FailureReason $r) => [
            'id' => $r->id,
            'name' => $r->name_ar,
            'requires_note' => (bool) $r->requires_note,
            'for_postponed' => (bool) $r->allows_reschedule,
        ])->values()]);
    }

    /** سطر الشحنة في القوائم: ما يكفي لاختيارها من الشارع */
    public static function row(Shipment $shipment, ?ShipmentTicket $ticket = null): array
    {
        $returning = in_array($shipment->status, [ShipmentStatus::Returning, ShipmentStatus::PartiallyDelivered], true);

        return [
            'id' => $shipment->id,
            'number' => $shipment->number,
            'type' => $shipment->type,
            'status' => $shipment->status->value,
            'status_label' => $shipment->statusLabel(),
            'recipient' => $shipment->recipient_name,
            'city' => $shipment->city?->name_ar,
            'landmark' => $shipment->landmark,
            'amount' => $returning ? 0 : (int) $shipment->cod_amount,
            'needs_code' => filled($shipment->delivery_code),
            'fragile' => (bool) $shipment->is_fragile,
            'has_note' => filled($shipment->notes),
            'ticket' => $ticket ? ['status' => $ticket->status, 'label' => $ticket->statusLabel()] : null,
            'failure' => $shipment->lastFailureReason?->name_ar,
            // المؤجّلة بموعدها، وما لم يُسلَّم ينتظر قرار المعالجة، وما أُعيد إليه بعد قرار (docs/plan/75)
            'until' => $shipment->status === ShipmentStatus::Postponed ? $shipment->scheduled_at?->toIso8601String() : null,
            'waiting' => $shipment->status === ShipmentStatus::FailedAttempt,
            'redelivery' => $shipment->status === ShipmentStatus::OutForDelivery && $shipment->redelivery_at !== null,
        ];
    }

    /** الشحنة كاملةً كما في شاشة المندوب — بلا كود التسليم */
    public static function detail(Shipment $shipment, Courier $courier): array
    {
        $shipment->loadMissing(['governorate:id,name_ar', 'city:id,name_ar', 'merchant:id,business_name,phone', 'lastFailureReason:id,name_ar']);

        $canAct = $shipment->status === ShipmentStatus::OutForDelivery && (int) $shipment->delivery_courier_id === $courier->id;
        $ticket = $canAct ? self::currentTicket($shipment, $courier) : null;
        $partial = $canAct ? RecordCourierOutcome::approvedPartial($shipment, $courier) : null;

        return [
            'id' => $shipment->id,
            'number' => $shipment->number,
            'barcode' => $shipment->barcode,
            'type' => $shipment->type,
            'status' => $shipment->status->value,
            'status_label' => $shipment->statusLabel(),
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
            'location' => $shipment->lat !== null && $shipment->lng !== null
                ? ['lat' => (float) $shipment->lat, 'lng' => (float) $shipment->lng] : null,
            'merchant' => ['name' => $shipment->merchant?->business_name, 'phone' => $shipment->merchant?->phone],
            'amount' => (int) $shipment->cod_amount,
            // يُمسح الطرد قبل أيّ تسجيل (docs/plan/72)
            'needs_scan' => $canAct && ScanParcel::required(),
            'needs_code' => filled($shipment->delivery_code),
            'pieces' => (int) $shipment->pieces_count,
            'description' => $shipment->description,
            'notes' => $shipment->notes,
            'fragile' => (bool) $shipment->is_fragile,
            'allow_open' => (bool) $shipment->allow_open,
            'attempts' => (int) $shipment->attempts_count,
            'failure' => $shipment->lastFailureReason?->name_ar,
            'ticket' => $ticket ? self::ticketData($ticket) : null,
            // ما يُسجَّل عليها الآن: الجزئي بتذكرة اعتُمدت وحدها، ولا شيء إن لم تعد بيده
            'actions' => $canAct ? array_values(array_filter(
                array_keys(RecordCourierOutcome::ACTIONS),
                fn ($a) => $a !== 'partially_delivered' || $partial !== null,
            )) : [],
            'partial_amount' => $partial?->approved_amount,
            'until' => $shipment->status === ShipmentStatus::Postponed ? $shipment->scheduled_at?->toIso8601String() : null,
            'can_resume' => $shipment->status === ShipmentStatus::Postponed && (int) $shipment->delivery_courier_id === $courier->id,
            'waiting' => $shipment->status === ShipmentStatus::FailedAttempt,
        ];
    }

    /** آخر طلب تغيير مبلغٍ له في خرجته هذه: ينتظر، أو اعتُمد، أو رُفض */
    public static function currentTicket(Shipment $shipment, Courier $courier): ?ShipmentTicket
    {
        return $shipment->tickets()->where('courier_id', $courier->id)->where('status', '!=', 'closed')
            ->where('created_at', '>=', $shipment->assigned_at ?? $shipment->status_changed_at)
            ->latest('id')->first();
    }

    public static function ticketData(ShipmentTicket $ticket): array
    {
        return [
            'number' => $ticket->number,
            'kind' => $ticket->kind,
            'kind_label' => $ticket->kindLabel(),
            'status' => $ticket->status,
            'status_label' => $ticket->statusLabel(),
            'requested_amount' => (int) $ticket->requested_amount,
            'approved_amount' => $ticket->approved_amount,
        ];
    }

    /** شحنته وحدها — والجواب لغيرها كجواب غير الموجودة */
    private function ensureMine(Shipment $shipment, Courier $courier, bool $delivery = false): void
    {
        $mine = (int) $shipment->delivery_courier_id === $courier->id
            || (! $delivery && (int) $shipment->pickup_courier_id === $courier->id);

        abort_unless($mine, 404, 'الشحنة ليست بيدك.');
    }

    private function courier(Request $request): Courier
    {
        return $request->attributes->get('courier');
    }
}

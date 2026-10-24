<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\StaffStatusUpdate;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CallCenterStatusRequest;
use App\Http\Requests\ChangeStatusRequest;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «الزبون اتّصل بخصوص الوصل كذا» (docs/plan/73): البحث برقم الوصل أو هاتف الزبون أو اسمه، أو مسح
 * باركوده — والشحنة بسجلّها كما تراها الموظّفة في الموقع. ومنها تحدّث حالتها، وتعيد للتوصيل راجعاً
 * مؤكَّداً اقتنع زبونه بالاستلام (docs/plan/80).
 */
class ShipmentController extends Controller
{
    /** لا تُحدَّث من التطبيق: الراجع للتاجر بإيصاله، والمفقود والتالف قرارٌ في الموقع */
    private const NOT_FROM_APP = [ShipmentStatus::Returned, ShipmentStatus::Lost, ShipmentStatus::Damaged];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('shipments.view'), 403);
        $term = trim((string) $request->query('q'));

        $found = $term === '' ? collect() : Shipment::query()->visibleTo($request->user())->search($term)
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->latest('shipments.id')->limit(30)->get();

        return response()->json(['data' => $found->map(fn (Shipment $s) => [
            'id' => $s->id,
            'number' => $s->number,
            'recipient' => $s->recipient_name,
            'phone' => $s->recipient_phone,
            'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
            'merchant' => $s->merchant?->business_name,
            'status' => $s->status->value,
            'status_label' => $s->statusLabel(),
            'amount' => (int) $s->cod_amount,
        ])->values()]);
    }

    public function show(Request $request, int $shipment): JsonResponse
    {
        abort_unless($request->user()->can('shipments.view'), 403);
        $s = $this->find($request, $shipment);
        $user = $request->user();
        $transitions = $user->can('shipments.status') ? self::transitions($s) : [];
        $needs = collect($transitions)->pluck('needs')->flatten()->unique();

        return response()->json(['data' => [
            'id' => $s->id,
            'number' => $s->number,
            'status' => $s->status->value,
            'status_label' => $s->statusLabel(),
            'recipient' => $s->recipient_name,
            'phone' => $s->recipient_phone,
            'phone_alt' => $s->recipient_phone_alt,
            'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
            'address' => $s->address,
            'landmark' => $s->landmark,
            'amount' => (int) $s->cod_amount,
            'pieces' => (int) $s->pieces_count,
            'description' => $s->description,
            'notes' => $s->notes,
            'reason' => $s->lastFailureReason?->name_ar,
            // التوضيح الذي كُتب مع السبب (docs/plan/80): «تغيير عنوان — إلى الكرادة قرب…»
            'reason_note' => self::reasonNote($s),
            'until' => $s->status === ShipmentStatus::Postponed ? $s->scheduled_at?->toIso8601String() : null,
            'attempts' => (int) $s->attempts_count,
            'created_at' => $s->created_at?->toIso8601String(),
            'merchant' => ['name' => $s->merchant?->business_name, 'phone' => $s->merchant?->phone],
            'courier' => ['name' => $s->deliveryCourier?->name, 'phone' => $s->deliveryCourier?->phone],
            // في المعالجة الآن: تُفتح منها
            'processing' => $s->status === ShipmentStatus::FailedAttempt,
            'transitions' => $transitions,
            'reasons' => $needs->contains('reason')
                ? FailureReason::availableFor($user->company_id)->get(['id', 'name_ar', 'requires_note'])
                    ->map(fn (FailureReason $r) => ['id' => $r->id, 'name' => $r->name_ar, 'note' => (bool) $r->requires_note])->values()
                : [],
            'couriers' => $needs->contains('courier')
                ? Courier::delivering()->active()->visibleTo($user)->orderBy('name')->limit(300)->get(['id', 'name'])
                    ->map(fn (Courier $c) => ['id' => $c->id, 'name' => $c->name])->values()
                : [],
            'can_reopen' => $user->can('shipments.status') && self::reopenable($s),
            'events' => ShipmentEvent::query()->where('shipment_id', $s->id)->latest('id')->limit(30)->get()
                ->map(fn (ShipmentEvent $e) => [
                    // الحالة لما يغيّرها، ونوع الحدث لغيره: «تعديل بيانات»، «طلب تغيير المبلغ»…
                    'status' => in_array($e->event_type, ['status_change', 'forced_status', 'processed'], true)
                        ? (ShipmentStatus::tryFrom((string) $e->to_status)?->label() ?? $e->to_status)
                        : (ShipmentEvent::TYPES[$e->event_type] ?? $e->event_type),
                    'type' => $e->event_type,
                    'note' => $e->note,
                    'by' => $e->actor_name,
                    'at' => $e->created_at?->toIso8601String(),
                ])->values(),
        ]]);
    }

    /** «حدّثي الحالة»: قواعد صفحة الشحنة في الموقع وطريقها نفسه (StaffStatusUpdate) */
    public function status(CallCenterStatusRequest $request, StaffStatusUpdate $update): JsonResponse
    {
        $s = $request->shipment();
        $to = $request->validated('status');

        abort_if(in_array(ShipmentStatus::tryFrom((string) $to), self::NOT_FROM_APP, true) || $request->boolean('force')
            || $to === ChangeStatusRequest::FORCE_DELIVERED, 422, 'هذا التحديث من الموقع وحده.');

        return response()->json(['message' => $update->handle($s, $request->validated(), $request->user(), $request->ip())]);
    }

    /**
     * راجعٌ مؤكَّد يعود للتوصيل (docs/plan/80): اقتنع الزبون، أو اتّفق التاجر معه. ما زال بيد مندوبه
     * فيعود معه «إعادة توصيل»، وما استُلم في المخزن يعود إليه ينتظر الإسناد. والسبب إلزاميّ، ويُكتب
     * معالجةً باسمها كقرار «إعادة توصيل».
     */
    public function reopen(Request $request, int $shipment, ChangeShipmentStatus $change): JsonResponse
    {
        abort_unless($request->user()->can('shipments.status'), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:255']], [
            'note.required' => 'اكتبي لماذا تعود للتوصيل: من اتّفق مع الزبون وعلى ماذا.',
        ], ['note' => 'السبب']);
        $s = $this->find($request, $shipment);
        $user = $request->user();

        if (! self::reopenable($s)) {
            throw ValidationException::withMessages(['note' => "الشحنة {$s->number} «{$s->statusLabel()}»: لا تعود للتوصيل من هنا."]);
        }

        $withCourier = $s->return_received_at === null;
        $note = 'إعادة توصيل بعد راجع مؤكد — '.$data['note'];

        DB::transaction(function () use ($s, $user, $change, $withCourier, $note, $data, $request) {
            $to = $change->handle($s, $withCourier ? ShipmentStatus::OutForDelivery : ShipmentStatus::AtHub, $user, [
                'retry' => true,
                'note' => $note,
                ...($withCourier ? ['courier_id' => $s->delivery_courier_id] : []),
            ])->status;

            // قرارٌ كقرار المعالجة: يُعدّ في «أنجزتِ اليوم» وفي أداء الموظّفين
            ShipmentEvent::create([
                'shipment_id' => $s->id,
                'from_status' => ShipmentStatus::Returning->value,
                'to_status' => $to->value,
                'event_type' => 'processed',
                'actor_type' => 'user',
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'courier_id' => $s->delivery_courier_id,
                'note' => $note,
                'meta' => ['action' => 'redeliver', 'reopened' => true, 'by' => 'staff', 'said' => $data['note']],
                'ip' => $request->ip(),
            ]);
        });

        return response()->json(['message' => $withCourier
            ? "عادت {$s->number} للتوصيل مع مندوبها — «إعادة توصيل»."
            : "عادت {$s->number} للمخزن تنتظر مندوب توصيل."]);
    }

    /** راجعٌ مؤكَّد لم يُسلَّم منه شيء، وليس عند شركةٍ وسيطة — تعود للتوصيل بقرار */
    public static function reopenable(Shipment $s): bool
    {
        return $s->status === ShipmentStatus::Returning
            && $s->return_confirmed_at !== null
            && ! $s->wasDelivered()
            && ($s->return_received_at !== null
                || ($s->delivery_courier_id !== null && ! $s->deliveryCourier?->is_intermediary));
    }

    /**
     * ما تحدّث إليه الموظّفة من التطبيق: الانتقالات المسموحة من حالها، و«إعادة توصيل» حيث تجوز —
     * ولكلٍّ ما يحتاجه: سبب (لم تُسلَّم)، أو مبلغ (واصل جزئي)، أو مندوب (يخرج للتوصيل بلا مندوب).
     *
     * @return list<array{value: string, label: string, needs: list<string>}>
     */
    public static function transitions(Shipment $s): array
    {
        $courier = $s->delivery_courier_id !== null;
        $options = [];

        if (ChangeStatusRequest::canRedeliver($s)) {
            $options[] = ['value' => ChangeStatusRequest::REDELIVER, 'label' => 'إعادة توصيل',
                'needs' => $s->status !== ShipmentStatus::FailedAttempt && ! $courier ? ['courier'] : []];
        }

        foreach ($s->status->allowedNext() as $to) {
            // الراجع يعود للتوصيل بـ«أعيديها للتوصيل» وسببه، لا بتغيير حالة
            if (in_array($to, self::NOT_FROM_APP, true) || ($s->status === ShipmentStatus::Returning && $to === ShipmentStatus::AtHub)) {
                continue;
            }

            $options[] = [
                'value' => $to->value,
                'label' => $to === ShipmentStatus::Returning && $s->status !== ShipmentStatus::PartiallyDelivered ? 'راجع مؤكد' : $to->label(),
                'needs' => match ($to) {
                    ShipmentStatus::FailedAttempt => ['reason'],
                    ShipmentStatus::PartiallyDelivered => ['amount'],
                    ShipmentStatus::OutForDelivery => $courier ? [] : ['courier'],
                    default => [],
                },
            ];
        }

        return $options;
    }

    /** التوضيح المكتوب مع سبب آخر محاولةٍ فاشلةٍ أو تأجيل — من المندوب أو الموظّف */
    public static function reasonNote(Shipment $s): ?string
    {
        if (! in_array($s->status, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true) && $s->last_failure_reason_id === null) {
            return null;
        }

        $note = ShipmentEvent::query()->where('shipment_id', $s->id)
            ->whereIn('to_status', [ShipmentStatus::FailedAttempt->value, ShipmentStatus::Postponed->value])
            ->whereIn('event_type', ['status_change', 'forced_status'])
            ->latest('id')->value('note');

        return filled($note) ? (string) $note : null;
    }

    private function find(Request $request, int $id): Shipment
    {
        return Shipment::query()->visibleTo($request->user())
            ->with(['merchant:id,business_name,phone', 'governorate:id,name_ar', 'city:id,name_ar',
                'deliveryCourier:id,name,phone,is_intermediary', 'lastFailureReason:id,name_ar'])
            ->findOrFail($id);
    }
}

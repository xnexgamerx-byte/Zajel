<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Actions\Support\Claims;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentFollowUp;
use App\Support\FeatureGate;
use App\Support\PostponedUntil;
use App\Support\StaffNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «شحنات للمعالجة» في تطبيق الكول سنتر (docs/plan/38، 73): المحاولة الفاشلة تنتظر قراراً — تتّصل
 * بالزبون، وتكتب للتاجر أو المندوب عنها، ثم تقرّر. من فتحتها تقرّر فيها وحدها، بـProcessFailedAttempt
 * نفسه، وفي محافظات اختصاصها وحدها.
 */
class ProcessingController extends Controller
{
    use Concerns;

    private const WITH = ['merchant:id,business_name,owner_name,phone', 'governorate:id,name_ar', 'city:id,name_ar',
        'deliveryCourier:id,name,phone,is_intermediary,hidden_from_merchants', 'lastFailureReason:id,name_ar'];

    /** ينتظر ردّنا: تاجرٌ أو مندوبٌ كتب عنها، أو تذكرةٌ مفتوحة لها — يُقدَّم */
    private const WAITING_ON_US = "(exists (select 1 from conversations c where c.shipment_id = shipments.id and c.staff_unread = 1)
        or exists (select 1 from courier_messages m join courier_threads t on t.id = m.courier_thread_id
            where m.shipment_id = shipments.id and m.author = 'courier' and t.staff_unread = 1)
        or exists (select 1 from shipment_tickets k where k.shipment_id = shipments.id and k.status = 'open'))";

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('shipments.status'), 403);
        $term = trim((string) $request->query('q'));

        $shipments = self::filtered($this->pending($request), $request)
            ->when($term !== '', fn ($q) => $q->search($term))
            ->select('shipments.*')->selectRaw(self::WAITING_ON_US.' as waiting_on_us')
            ->with(self::WITH)
            ->orderByDesc('waiting_on_us')->orderBy('shipments.status_changed_at')
            ->limit(100)->get();
        $claims = Claims::holders(Claims::PROCESSING, $shipments->pluck('id'));

        return response()->json([
            'data' => $shipments->map(fn (Shipment $s) => $this->row($s, $claims[$s->id] ?? null, $request))->values(),
            // شرائح الفرز بأعدادها في المعالجة كلّها (docs/plan/93)
            'facets' => self::facets($this->pending($request)),
        ]);
    }

    public function show(Request $request, int $shipment): JsonResponse
    {
        $s = $this->find($request, $shipment);
        $note = ShipmentFollowUp::query()->where('shipment_id', $s->id)->where('user_id', $request->user()->id)->value('note');

        return response()->json(['data' => $this->row($s, Claims::holder(Claims::PROCESSING, $s->id), $request) + [
            'address' => $s->address,
            'landmark' => $s->landmark,
            'phone_alt' => $s->recipient_phone_alt,
            'notes' => $s->notes,
            // التوضيح المكتوب مع السبب (docs/plan/80)
            'reason_note' => ShipmentController::reasonNote($s),
            'description' => $s->description,
            'pieces' => (int) $s->pieces_count,
            'my_note' => $note,
            'can_ask' => FeatureGate::enabled(Feature::Conversations) && $s->merchant !== null,
            'actions' => ProcessFailedAttempt::ACTIONS,
        ] + self::shipmentChats($s, $request->user())]);
    }

    /** «أعالجها»: تتّصل بالزبون وهي وحدها — وملاحظتها تُحفظ لها */
    public function claim(Request $request, int $shipment): JsonResponse
    {
        $s = $this->find($request, $shipment);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']], [], ['note' => 'ملاحظتك']);
        Claims::take(Claims::PROCESSING, $s->id, $request->user());

        // «بدأت المتابعة» كما في الموقع: اسمها عليها، وملاحظتها لها وحدها
        $row = ShipmentFollowUp::firstOrNew(['shipment_id' => $s->id, 'user_id' => $request->user()->id]);
        $fresh = ! $row->exists || ! $row->isCurrent($s);
        $row->forceFill([
            'started_at' => $fresh ? now() : $row->started_at,
            'note' => $request->has('note') ? (trim((string) ($data['note'] ?? '')) ?: null) : ($fresh ? null : $row->note),
        ])->save();

        return $this->show($request, $s->id);
    }

    public function release(Request $request, int $shipment): JsonResponse
    {
        $s = $this->find($request, $shipment);
        Claims::release(Claims::PROCESSING, $s->id, $request->user());

        return response()->json(['message' => 'أفلتّها — تعالجها أيّ زميلة.']);
    }

    /** القرار: إعادة توصيل، أو تأجيل إلى يومٍ اتُّفق عليه، أو راجعٌ مؤكَّد */
    public function decide(Request $request, int $shipment, ProcessFailedAttempt $process): JsonResponse
    {
        $s = $this->find($request, $shipment);
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(ProcessFailedAttempt::ACTIONS))],
            'until' => ['nullable', 'required_if:action,postpone', 'date', 'after_or_equal:today', 'before:+60 days'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'until.required_if' => 'التأجيل إلى متى؟ اختر اليوم الذي اتُّفق عليه مع الزبون.',
            'until.after_or_equal' => 'موعد التأجيل اليوم أو بعده.',
        ], ['until' => 'موعد التأجيل', 'note' => 'ما قاله الزبون']);

        $process->handle($s, $data['action'], $request->user(), 'staff', $data['until'] ?? null, $data['note'] ?? null, $request->ip());

        return response()->json(['message' => "عولجت {$s->number}: ".ProcessFailedAttempt::ACTIONS[$data['action']]
            .($data['action'] === 'postpone' ? ' إلى '.PostponedUntil::label(PostponedUntil::parse($data['until'])) : '').'.']);
    }

    /** ردٌّ على التاجر عن الشحنة: في محادثتها، أو محادثةٌ جديدة عنها */
    public function merchant(Request $request, int $shipment): JsonResponse
    {
        $s = $this->find($request, $shipment);
        abort_unless($s->merchant && FeatureGate::enabled(Feature::Conversations) && $request->user()->can('support.reply'), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'الرسالة']);
        Claims::take(Claims::PROCESSING, $s->id, $request->user());

        self::writeMerchant($s, $data['body'], $request->user());

        return response()->json(['message' => 'وصلت التاجر.'], 201);
    }

    /** ردٌّ على مندوبها: في محادثته، والسطر يحمل رقم الشحنة */
    public function courier(Request $request, int $shipment): JsonResponse
    {
        $s = $this->find($request, $shipment);
        abort_unless($s->deliveryCourier && FeatureGate::enabled(Feature::Conversations) && $request->user()->can('support.reply'), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'الرسالة']);
        Claims::take(Claims::PROCESSING, $s->id, $request->user());

        self::writeCourier($s, $data['body'], $request->user());

        return response()->json(['message' => 'وصلت المندوب.'], 201);
    }

    private function pending(Request $request)
    {
        return Shipment::query()->visibleTo($request->user())->inGovernoratesOf($request->user())
            ->where('shipments.status', ShipmentStatus::FailedAttempt->value);
    }

    private function find(Request $request, int $id): Shipment
    {
        abort_unless($request->user()->can('shipments.status'), 403);

        return $this->pending($request)->with(self::WITH)->findOrFail($id);
    }

    private function row(Shipment $s, $claim, Request $request): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'recipient' => $s->recipient_name,
            'phone' => $s->recipient_phone,
            'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
            'amount' => (int) $s->cod_amount,
            'reason' => $s->lastFailureReason?->name_ar,
            'attempts' => (int) $s->attempts_count,
            'waiting_minutes' => self::minutesSince($s->status_changed_at),
            'waiting_on_us' => (bool) ($s->waiting_on_us ?? false),
            'merchant' => ['name' => $s->merchant?->business_name, 'phone' => $s->merchant?->phone],
            'courier' => ['name' => $s->deliveryCourier?->name, 'phone' => $s->deliveryCourier?->phone],
            'claim' => self::claimJson($claim, $request->user()),
            // ملاحظة للموظّفين، كـ«تعالجها أنت» في السطر نفسه (docs/plan/83)
            'staff_note' => StaffNote::json($s),
        ];
    }
}

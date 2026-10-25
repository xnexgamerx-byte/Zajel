<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\StaffNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «حالة الشحنات» في تطبيق الكول سنتر (docs/plan/80): أربع خاناتٍ مرتّبة في محافظات اختصاصها —
 * قيد التوصيل، والمؤجل، وإعادة التوصيل (ما عولج فعاد للتوصيل)، والراجع المؤكد — ولكلٍّ قائمتها
 * وحدها. و«أنجزتِ اليوم» بتفاصيله: ما عالجته بقراره، وتذاكر المبلغ التي حسمتها.
 */
class BoardController extends Controller
{
    use Concerns;

    /** الخانات بترتيبها في التطبيق */
    public const KINDS = [
        'in_delivery' => 'قيد التوصيل',
        'postponed' => 'مؤجل',
        'redelivery' => 'إعادة توصيل',
        'returns' => 'راجع مؤكد',
    ];

    private const LIMIT = 100;

    /** عدد كلّ خانة */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('shipments.view'), 403);

        return response()->json(['data' => collect(self::KINDS)->map(fn ($label, $kind) => [
            'kind' => $kind,
            'label' => $label,
            'count' => self::of($user, $kind)->count(),
        ])->values()]);
    }

    /** ما في الخانة، بحثاً بالرقم أو الهاتف أو الاسم */
    public function show(Request $request, string $kind): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('shipments.view') && array_key_exists($kind, self::KINDS), 404);
        $term = trim((string) $request->query('q'));

        $query = self::of($user, $kind)
            ->when($term !== '', fn ($q) => $q->search($term))
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar',
                'deliveryCourier:id,name', 'lastFailureReason:id,name_ar']);

        match ($kind) {
            // المؤجّلة بموعدها: ما فات أو اليوم أوّلاً
            'postponed' => $query->orderByRaw('shipments.scheduled_at is null')->orderBy('shipments.scheduled_at'),
            // الراجع ما زال بيد المندوب أوّلاً: هو ما يُلحَق قبل أن يُسلَّم للمخزن
            'returns' => $query->orderByRaw('shipments.return_received_at is not null')->orderBy('shipments.status_changed_at'),
            'redelivery' => $query->orderBy('shipments.redelivery_at'),
            default => $query->orderByDesc('shipments.status_changed_at'),
        };

        return response()->json([
            'kind' => $kind,
            'label' => self::KINDS[$kind],
            'count' => self::of($user, $kind)->count(),
            'data' => $query->limit(self::LIMIT)->get()->map(fn (Shipment $s) => self::row($s))->values(),
        ]);
    }

    /**
     * «أنجزتِ اليوم» بتفاصيله: processed — ما عالجته بقرارٍ، كلٌّ بقراره وما قاله الزبون؛ tickets — تذاكر
     * تغيير المبلغ التي اعتمدتها أو رفضتها للمناديب.
     */
    public function done(Request $request): JsonResponse
    {
        $user = $request->user();
        $kind = $request->query('kind') === 'tickets' ? 'tickets' : 'processed';

        if ($kind === 'tickets') {
            $tickets = ShipmentTicket::query()->where('handled_by_user_id', $user->id)->whereOnDate('handled_at', today())
                ->with(['shipment:id,number,recipient_name', 'courier:id,name'])->latest('handled_at')->get();

            return response()->json(['kind' => $kind, 'data' => $tickets->map(fn (ShipmentTicket $t) => [
                'id' => $t->id,
                'shipment_id' => $t->shipment_id,
                'number' => $t->shipment?->number,
                'recipient' => $t->shipment?->recipient_name,
                'title' => "تذكرة {$t->number} — {$t->kindLabel()}",
                'action' => $t->status,
                'label' => $t->statusLabel(),
                'note' => $t->status === 'approved'
                    ? number_format($t->current_amount).' ← '.number_format((int) $t->approved_amount).' د.ع'
                    : $t->reply,
                'by' => $t->courier?->name,
                'at' => $t->handled_at?->toIso8601String(),
            ])->values()]);
        }

        $events = self::processedToday($user)->latest('id')->get();
        $shipments = Shipment::query()->whereIn('id', $events->pluck('shipment_id'))
            ->get(['id', 'number', 'recipient_name'])->keyBy('id');

        return response()->json(['kind' => $kind, 'data' => $events->map(fn (ShipmentEvent $e) => [
            'id' => $e->id,
            'shipment_id' => $e->shipment_id,
            'number' => $shipments->get($e->shipment_id)?->number,
            'recipient' => $shipments->get($e->shipment_id)?->recipient_name,
            'title' => ProcessFailedAttempt::ACTIONS[$e->meta['action'] ?? ''] ?? 'معالجة',
            'action' => $e->meta['action'] ?? null,
            'label' => ProcessFailedAttempt::ACTIONS[$e->meta['action'] ?? ''] ?? 'معالجة',
            'note' => filled($e->meta['said'] ?? null) ? (string) $e->meta['said'] : null,
            'by' => null,
            'at' => $e->created_at?->toIso8601String(),
        ])->values()]);
    }

    /** قرارات المعالجة التي اتّخذتها اليوم — ومنها عدّ «أنجزتِ اليوم» بأنواعه */
    public static function processedToday(User $user): Builder
    {
        return ShipmentEvent::query()->where('event_type', 'processed')->where('actor_type', 'user')
            ->where('actor_id', $user->id)->whereOnDate('created_at', today());
    }

    /** ما في كلّ خانة — شرطٌ واحد للقائمة ولعددها، في محافظات اختصاص الموظّفة */
    public static function of(User $user, string $kind): Builder
    {
        $query = Shipment::query()->visibleTo($user)->inGovernoratesOf($user);

        return match ($kind) {
            'postponed' => $query->where('shipments.status', ShipmentStatus::Postponed->value),
            'redelivery' => $query->whereNotNull('shipments.redelivery_at')
                ->whereIn('shipments.status', [ShipmentStatus::OutForDelivery->value, ShipmentStatus::AtHub->value]),
            'returns' => $query->where('shipments.status', ShipmentStatus::Returning->value)
                ->whereNotNull('shipments.return_confirmed_at'),
            default => $query->where('shipments.status', ShipmentStatus::OutForDelivery->value)
                ->whereNull('shipments.redelivery_at'),
        };
    }

    public static function row(Shipment $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'recipient' => $s->recipient_name,
            'phone' => $s->recipient_phone,
            'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
            'merchant' => $s->merchant?->business_name,
            'status' => $s->status->value,
            'status_label' => $s->statusLabel(),
            'amount' => (int) $s->cod_amount,
            'courier' => $s->deliveryCourier?->name,
            'reason' => $s->lastFailureReason?->name_ar,
            'until' => $s->status === ShipmentStatus::Postponed ? $s->scheduled_at?->toIso8601String() : null,
            // الراجع: بيد المندوب بعد، أو استُلم في المخزن
            'with_courier' => $s->status === ShipmentStatus::Returning ? $s->return_received_at === null : null,
            'since' => $s->status_changed_at?->toIso8601String(),
            // من اتّصل بها قبلك وماذا قيل (docs/plan/83)
            'staff_note' => StaffNote::json($s),
        ];
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Actions\Support\Converse;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CourierMessage;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SqlDate;
use App\Support\Arabic;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * «أداء الموظّفين» (docs/plan/74): ما أنجزه كلّ موظّفٍ في المدّة وما هو بالضبط — تذاكر المبلغ التي
 * اعتمدها أو رفضها وبعد كم، والمحاولات الفاشلة التي عالجها وبأيّ قرار، وردوده على التجّار
 * ورسائله للمناديب. ومعها عدد التذاكر والمعالجات أسبوعاً بأسبوع أو شهراً بشهر أو سنةً بسنة.
 *
 * كلّه من سجلّات العمل نفسها (التذكرة بمن حسمها، وحدث «processed» في سجلّ الشحنة، وسطر المحادثة
 * بكاتبه) — فلا عدّاد يُحفظ وحده فيختلف عن الحقيقة. والموظّف يرى ما في محافظاته وفرعه وحده.
 */
class StaffPerformanceController extends Controller
{
    public const BUCKETS = ['week' => 'أسبوعياً', 'month' => 'شهرياً', 'year' => 'سنوياً'];

    public const RANGES = ['today' => 'اليوم', 'week' => 'هذا الأسبوع', 'month' => 'هذا الشهر', 'year' => 'هذه السنة'];

    /** أسبوع العراق يبدأ السبت */
    protected const WEEK_START = Carbon::SATURDAY;

    public function index(Request $request): View
    {
        $period = $this->period($request);
        [$from, $to] = $period->bounds();
        $viewer = $request->user();
        $by = array_key_exists((string) $request->query('by'), self::BUCKETS)
            ? (string) $request->query('by')
            : ($period->days() > 400 ? 'year' : ($period->days() > 70 ? 'month' : 'week'));

        $shipments = Shipment::query()->visibleTo($viewer)->select('shipments.id');
        $conversations = Conversation::query()->visibleTo($viewer)->select('conversations.id');

        // تذاكر المبلغ بمن حسمها: الاعتماد بمبلغه (ولو أُغلقت بعده)، والرفض
        $tickets = ShipmentTicket::query()->visibleTo($viewer)
            ->whereNotNull('handled_by_user_id')->whereBetween('handled_at', [$from, $to])
            ->get(['id', 'handled_by_user_id', 'approved_amount', 'status', 'created_at', 'handled_at'])
            ->groupBy('handled_by_user_id');

        // المعالجات بمن اتّخذها وبأيّ قرار
        $processed = ShipmentEvent::query()->where('event_type', 'processed')->where('actor_type', 'user')
            ->whereBetween('created_at', [$from, $to])->whereIn('shipment_id', $shipments)
            ->get(['id', 'actor_id', 'actor_name', 'meta'])
            ->groupBy('actor_id');

        $replies = ConversationMessage::query()->where('author', Converse::STAFF)->whereNotNull('user_id')
            ->whereBetween('created_at', [$from, $to])->whereIn('conversation_id', $conversations)
            ->selectRaw('user_id, count(*) as total, count(distinct conversation_id) as threads')
            ->groupBy('user_id')->toBase()->get()->keyBy('user_id');

        $courier = CourierMessage::query()->where('author', 'staff')->whereNotNull('user_id')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')->toBase()->get()->keyBy('user_id');

        // كلّ من عمل شيئاً، وموظّفو خدمة العملاء ولو لم يعملوا — صفرهم يُرى
        $ids = collect([$tickets->keys(), $processed->keys(), $replies->keys(), $courier->keys()])->flatten()
            ->map(fn ($id) => (int) $id)->filter()->unique();
        $users = User::query()
            ->where(fn ($q) => $q->whereIn('id', $ids)
                ->orWhere(fn ($w) => $w->where('role', UserRole::CustomerService->value)->where('is_active', true)))
            ->get(['id', 'name', 'role']);

        $people = $users->map(function (User $u) use ($tickets, $processed, $replies, $courier) {
            $t = $tickets->get($u->id, collect());
            $p = $processed->get($u->id, collect());
            $approved = $t->whereNotNull('approved_amount')->count();
            $rejected = $t->where('status', 'rejected')->count();

            return (object) [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role?->label(),
                'approved' => $approved,
                'rejected' => $rejected,
                'ticket_minutes' => (int) round($t->avg(fn ($x) => $x->created_at?->diffInMinutes($x->handled_at)) ?? 0),
                'processed' => $p->count(),
                'actions' => $p->countBy(fn ($e) => $e->meta['action'] ?? '—')->all(),
                'wait' => (int) round($p->avg(fn ($e) => (int) ($e->meta['waited_minutes'] ?? 0)) ?? 0),
                'replies' => (int) ($replies[$u->id]->total ?? 0),
                'threads' => (int) ($replies[$u->id]->threads ?? 0),
                'courier' => (int) ($courier[$u->id]->total ?? 0),
            ];
        })->each(fn ($row) => $row->total = $row->approved + $row->rejected + $row->processed + $row->replies + $row->courier)
            ->sortByDesc('total')->values();

        $person = $request->integer('user') ? $people->firstWhere('id', $request->integer('user')) : null;

        return view('tenant.reports.staff', [
            'period' => $period,
            'range' => $request->query('range'),
            'by' => $by,
            'people' => $people,
            'totals' => (object) [
                'opened' => ShipmentTicket::query()->visibleTo($viewer)->whereBetween('created_at', [$from, $to])->count(),
                'approved' => $people->sum('approved'),
                'rejected' => $people->sum('rejected'),
                'processed' => $people->sum('processed'),
                'replies' => $people->sum('replies'),
                'courier' => $people->sum('courier'),
            ],
            'trend' => $this->trend($viewer, $period, $by, $shipments, $conversations),
            'actions' => ProcessFailedAttempt::ACTIONS,
            'person' => $person,
            'log' => $person ? $this->log($person->id, $viewer, $from, $to, $shipments, $conversations) : collect(),
        ]);
    }

    /** المدّة: «اليوم · هذا الأسبوع · هذا الشهر · هذه السنة» بضغطة، أو من–إلى */
    protected function period(Request $request): ReportPeriod
    {
        $now = now();

        return match ($request->query('range')) {
            'today' => new ReportPeriod($now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'week' => new ReportPeriod($now->copy()->startOfWeek(self::WEEK_START), $now->copy()->endOfDay()),
            'month' => new ReportPeriod($now->copy()->startOfMonth(), $now->copy()->endOfDay()),
            'year' => new ReportPeriod($now->copy()->startOfYear(), $now->copy()->endOfDay()),
            default => ReportPeriod::fromRequest($request),
        };
    }

    /**
     * العدد في كلّ أسبوعٍ أو شهرٍ أو سنة: يُعدّ يوماً بيومٍ في قاعدة البيانات (date() في المحرّكين)
     * ثم تُجمع الأيام في خاناتها هنا — فلا تعبير أسبوعٍ يختلف بين SQLite وMySQL.
     *
     * @return Collection<int, object>
     */
    protected function trend(User $viewer, ReportPeriod $period, string $by, $shipments, $conversations): Collection
    {
        [$from, $to] = $period->bounds();
        $daily = fn ($query, string $column) => $query->whereBetween($column, [$from, $to])
            ->selectRaw(SqlDate::day($column).' as day, count(*) as n')->groupBy('day')->toBase()->pluck('n', 'day');

        $series = [
            'opened' => $daily(ShipmentTicket::query()->visibleTo($viewer), 'shipment_tickets.created_at'),
            'approved' => $daily(ShipmentTicket::query()->visibleTo($viewer)->whereNotNull('approved_amount')
                ->whereNotNull('handled_by_user_id'), 'shipment_tickets.handled_at'),
            'rejected' => $daily(ShipmentTicket::query()->visibleTo($viewer)->where('shipment_tickets.status', 'rejected'), 'shipment_tickets.handled_at'),
            'processed' => $daily(ShipmentEvent::query()->where('event_type', 'processed')->where('actor_type', 'user')
                ->whereIn('shipment_id', $shipments), 'shipment_events.created_at'),
            'replies' => $daily(ConversationMessage::query()->where('author', Converse::STAFF)
                ->whereIn('conversation_id', $conversations), 'conversation_messages.created_at'),
        ];

        $buckets = [];
        foreach ($series as $name => $days) {
            foreach ($days as $day => $n) {
                [$key, $label] = $this->bucket(Carbon::parse($day), $by);
                $buckets[$key] ??= (object) ['label' => $label, 'opened' => 0, 'approved' => 0, 'rejected' => 0, 'processed' => 0, 'replies' => 0];
                $buckets[$key]->{$name} += (int) $n;
            }
        }
        krsort($buckets);

        return collect(array_values($buckets));
    }

    /** @return array{string, string} مفتاحٌ يُرتَّب به، واسمٌ يُقرأ */
    protected function bucket(Carbon $day, string $by): array
    {
        return match ($by) {
            'year' => [$day->format('Y'), $day->format('Y')],
            'month' => [$day->format('Y-m'), $day->format('Y-m')],
            default => (function () use ($day) {
                $start = $day->copy()->startOfWeek(self::WEEK_START);

                return [$start->format('Y-m-d'), $start->format('Y-m-d').' — '.$start->copy()->addDays(6)->format('m-d')];
            })(),
        };
    }

    /**
     * «شنو عالج بالضبط»: كلّ ما فعله الموظّف في المدّة، الأحدث أوّلاً — التذكرة ورقمها وقراره، والشحنة
     * وقرار معالجتها، والمحادثة وردّه، ورسالته للمندوب.
     *
     * @return Collection<int, object>
     */
    protected function log(int $userId, User $viewer, string $from, string $to, $shipments, $conversations): Collection
    {
        $limit = 300;

        $tickets = ShipmentTicket::query()->visibleTo($viewer)->with('shipment:id,number')
            ->where('handled_by_user_id', $userId)->whereBetween('handled_at', [$from, $to])
            ->latest('handled_at')->limit($limit)->get()
            ->map(fn (ShipmentTicket $t) => (object) [
                'at' => $t->handled_at,
                'kind' => 'ticket',
                'what' => $t->approved_amount !== null ? 'اعتمد تذكرة' : 'رفض تذكرة',
                'good' => $t->approved_amount !== null,
                'ref' => $t->number,
                'shipment' => $t->shipment,
                'detail' => $t->kindLabel().' — '.number_format((int) $t->current_amount).' ← '
                    .number_format((int) ($t->approved_amount ?? $t->requested_amount)).' د.ع'
                    .($t->reply ? ' · '.$t->reply : ''),
            ]);

        $processed = ShipmentEvent::query()->with('shipment:id,number')
            ->where('event_type', 'processed')->where('actor_type', 'user')->where('actor_id', $userId)
            ->whereBetween('created_at', [$from, $to])->whereIn('shipment_id', $shipments)
            ->latest('id')->limit($limit)->get()
            ->map(fn (ShipmentEvent $e) => (object) [
                'at' => $e->created_at,
                'kind' => 'processing',
                'what' => 'عالج: '.(ProcessFailedAttempt::ACTIONS[$e->meta['action'] ?? ''] ?? '—'),
                'good' => ($e->meta['action'] ?? '') !== 'return',
                'ref' => null,
                'shipment' => $e->shipment,
                'detail' => trim(($e->meta['said'] ?? '').' · بعد انتظار '
                    .Arabic::duration((int) ($e->meta['waited_minutes'] ?? 0)), ' ·'),
            ]);

        $replies = ConversationMessage::query()->with('conversation:id,subject,merchant_id', 'conversation.merchant:id,business_name')
            ->where('author', Converse::STAFF)->where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])->whereIn('conversation_id', $conversations)
            ->latest('id')->limit($limit)->get()
            ->map(fn (ConversationMessage $m) => (object) [
                'at' => $m->created_at,
                'kind' => 'reply',
                'what' => 'ردّ على '.($m->conversation?->merchant?->business_name ?? 'تاجر'),
                'good' => true,
                'ref' => $m->conversation?->subject,
                'shipment' => null,
                'detail' => Str::limit((string) $m->body, 140),
            ]);

        $courier = CourierMessage::query()->with('shipment:id,number')
            ->where('author', 'staff')->where('user_id', $userId)->whereBetween('created_at', [$from, $to])
            ->latest('id')->limit($limit)->get()
            ->map(fn (CourierMessage $m) => (object) [
                'at' => $m->created_at,
                'kind' => 'courier',
                'what' => 'كتب للمندوب',
                'good' => true,
                'ref' => null,
                'shipment' => $m->shipment,
                'detail' => Str::limit((string) $m->body, 140),
            ]);

        return $tickets->concat($processed)->concat($replies)->concat($courier)
            ->sortByDesc(fn ($row) => $row->at?->getTimestamp() ?? 0)->take($limit)->values();
    }
}

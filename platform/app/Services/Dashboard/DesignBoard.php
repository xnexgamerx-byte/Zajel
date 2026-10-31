<?php

namespace App\Services\Dashboard;

use App\Actions\Support\StaffChat;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\Conversation;
use App\Models\CourierThread;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\FeatureGate;
use App\Support\HomeLayout;
use Illuminate\Support\Carbon;

/**
 * أرقام رئيسيّتي التصميمين «بطاقات فاتحة» و«زجاجيّ» (docs/plan/100): كل بطاقةٍ في الصورة برقمٍ من النظام،
 * لما يراه الموظّف وحده (visibleTo)، والمال لمن يرى المال.
 */
final class DesignBoard
{
    public const MONTHS = [
        1 => 'كانون الثاني', 2 => 'شباط', 3 => 'آذار', 4 => 'نيسان', 5 => 'أيار', 6 => 'حزيران',
        7 => 'تموز', 8 => 'آب', 9 => 'أيلول', 10 => 'تشرين الأول', 11 => 'تشرين الثاني', 12 => 'كانون الأول',
    ];

    public const DAYS = [0 => 'أحد', 1 => 'اثنين', 2 => 'ثلاثاء', 3 => 'أربعاء', 4 => 'خميس', 5 => 'جمعة', 6 => 'سبت'];

    /** أوقات الاستلام في جدول «مواعيد الاستلام»: [الاسم، من ساعة، إلى ساعة] */
    public const SLOTS = [['9 ص', 0, 12], ['12 م', 12, 16], ['5 م', 16, 24]];

    public function __construct(private readonly User $user) {}

    /** «بطاقات فاتحة»: البحث والأرقام الثلاثة، وأداء التوصيل، وللمعالجة، والملاحظة، والمحافظة، والأشهر، ومواعيد الاستلام */
    public function soft(): array
    {
        $month = now()->startOfMonth();
        // الشهر الماضي بالأيام نفسها: «حتى اليوم» لا الشهر كاملاً، فلا تبدو كل بداية شهرٍ هبوطاً
        $lastMonth = $month->copy()->subMonthNoOverflow();
        $lastMonthUntil = $lastMonth->copy()->addDays(now()->day - 1)->endOfDay()->min($lastMonth->copy()->endOfMonth());

        $created = fn (Carbon $from, Carbon $to) => $this->shipments()->whereBetween('created_at', [$from, $to])->count();
        $merchants = fn (Carbon $from, Carbon $to) => $this->shipments()->whereBetween('created_at', [$from, $to])
            ->distinct()->count('merchant_id');

        return [
            'stats' => [
                'month' => self::change($created($month, now()), $created($lastMonth, $lastMonthUntil)),
                'merchants' => self::change($merchants($month, now()), $merchants($lastMonth, $lastMonthUntil)),
                'on_road' => ['value' => $this->of(ShipmentStatus::OutForDelivery), 'change' => null],
            ],
            'performance' => $this->performance(),
            'channels' => $this->channels(),
            'processing' => $this->of(ShipmentStatus::FailedAttempt),
            'note' => $this->latestNote(),
            'governorate' => $this->topGovernorate(),
            'months' => $this->deliveredByMonth(8),
            'pickups' => $this->pickupWeek(),
        ];
    }

    /** «زجاجيّ»: العنوان وأرقامه، والمبالغ المستلمة، والمناديب على الطريق، وتتبّع شحنة، والشهر */
    public function glass(): array
    {
        $yesterday = today()->subDay();
        $closedYesterday = $this->shipments()->whereBetween('status_changed_at', [$yesterday, $yesterday->copy()->endOfDay()])
            ->whereIn('status', [ShipmentStatus::FailedAttempt->value, ShipmentStatus::Postponed->value, ShipmentStatus::Returning->value])->count();
        $deliveredYesterday = $this->shipments()->whereBetween('delivered_at', [$yesterday, $yesterday->copy()->endOfDay()])->count();

        return [
            'yesterday_rate' => $deliveredYesterday + $closedYesterday > 0
                ? (int) round(100 * $deliveredYesterday / ($deliveredYesterday + $closedYesterday)) : null,
            'today' => $this->shipments()->whereOnDate('created_at', today())->count(),
            'rate' => $this->performance()['rate'],
            'on_road' => $this->of(ShipmentStatus::OutForDelivery),
            'money' => HomeLayout::allowsSection($this->user, 'money') ? $this->collectedThisWeek() : null,
            'delivered_week' => $this->shipments()->where('delivered_at', '>=', now()->startOfWeek())->count(),
            'couriers_on_road' => $this->shipments()->where('status', ShipmentStatus::OutForDelivery->value)
                ->whereNotNull('delivery_courier_id')->distinct()->count('delivery_courier_id'),
            'tracking' => $this->tracking(),
            'days' => $this->deliveredByDays(30, 3),
        ];
    }

    private function shipments()
    {
        return Shipment::query()->visibleTo($this->user);
    }

    private function of(ShipmentStatus $status): int
    {
        return $this->shipments()->where('status', $status->value)->count();
    }

    /** @return array{value: int, change: ?int} والتغيّر بالمئة، ولا تغيّر بلا أساسٍ يُقاس عليه */
    private static function change(int $now, int $before): array
    {
        return ['value' => $now, 'change' => $before > 0 ? (int) round(100 * ($now - $before) / $before) : null];
    }

    /**
     * ثلاثون يوماً: نسبة التسليم من الشحنات التي حُسمت فيها (سُلّمت، أو رجعت أو تعثّرت أو أُلغيت)،
     * ومتوسّط ما بين إنشاء الشحنة وتسليمها بالساعات من آخر ألف مسلَّمة.
     *
     * @return array{rate: ?int, hours: ?int}
     */
    private function performance(): array
    {
        $from = now()->subDays(30);
        $delivered = $this->shipments()->where('delivered_at', '>=', $from)->count();
        $failed = $this->shipments()->where('status_changed_at', '>=', $from)->whereIn('status', [
            ShipmentStatus::Returning->value, ShipmentStatus::Returned->value, ShipmentStatus::FailedAttempt->value,
            ShipmentStatus::Cancelled->value, ShipmentStatus::Lost->value, ShipmentStatus::Damaged->value,
        ])->count();

        $durations = $this->shipments()->where('delivered_at', '>=', $from)->latest('delivered_at')->limit(1000)
            ->get(['created_at', 'delivered_at'])
            ->map(fn (Shipment $s) => $s->created_at->diffInMinutes($s->delivered_at))
            ->filter(fn ($m) => $m >= 0);

        return [
            'rate' => $delivered + $failed > 0 ? (int) round(100 * $delivered / ($delivered + $failed)) : null,
            'hours' => $durations->isEmpty() ? null : (int) round($durations->avg() / 60),
        ];
    }

    /** ما ينتظر ردّنا: تذاكر المناديب، ومحادثات التجّار، ورسائل الموظّفين، ورسائل المناديب */
    private function channels(): array
    {
        $talks = $this->user->can('support.reply') && FeatureGate::enabled(Feature::Conversations);

        return [
            'tickets' => $this->user->can('tickets.handle') ? ShipmentTicket::visibleTo($this->user)->open()->count() : null,
            'merchants' => $talks ? Conversation::visibleTo($this->user)->where('status', 'open')->where('last_author', 'merchant')->count() : null,
            'staff' => app(StaffChat::class)->unreadCount($this->user),
            'couriers' => $talks ? CourierThread::visibleTo($this->user)->where('staff_unread', true)->count() : null,
        ];
    }

    /** آخر ملاحظةٍ للموظّفين على شحنة (docs/plan/83) */
    private function latestNote(): ?array
    {
        $s = $this->shipments()->whereNotNull('staff_note')->where('staff_note', '!=', '')
            ->with('merchant:id,business_name')->latest('staff_note_at')->first();

        return $s ? [
            'text' => $s->staff_note,
            'at' => $s->staff_note_at,
            'merchant' => $s->merchant?->business_name,
            'number' => $s->number,
            'url' => route('shipments.show', $s),
        ] : null;
    }

    /** المحافظة الأكثر شحناتٍ مفتوحة، ونصيبها منها */
    private function topGovernorate(): ?array
    {
        $rows = $this->shipments()->whereIn('shipments.status', ShipmentStatus::openValues())
            ->join('governorates', 'governorates.id', '=', 'shipments.governorate_id')
            ->selectRaw('governorates.name_ar as name, count(*) as c')
            ->groupBy('governorates.name_ar')->orderByDesc('c')->toBase()->get();
        $total = (int) $rows->sum('c');

        return $total > 0 ? [
            'name' => $rows->first()->name,
            'share' => (int) round(100 * $rows->first()->c / $total),
            'count' => (int) $rows->first()->c,
        ] : null;
    }

    /** @return list<array{label: string, count: int}> المسلَّمة في كل شهرٍ من آخر $n أشهر، أقدمها أوّلاً */
    private function deliveredByMonth(int $n): array
    {
        $from = now()->startOfMonth()->subMonthsNoOverflow($n - 1);
        $perDay = $this->shipments()->where('delivered_at', '>=', $from)
            ->selectRaw('date(delivered_at) as day, count(*) as c')->groupBy('day')->toBase()->pluck('c', 'day');

        return collect(range($n - 1, 0))->map(function (int $ago) use ($perDay) {
            $m = now()->startOfMonth()->subMonthsNoOverflow($ago);

            return [
                'label' => self::MONTHS[$m->month],
                'count' => (int) $perDay->filter(fn ($c, $day) => str_starts_with((string) $day, $m->format('Y-m')))->sum(),
            ];
        })->all();
    }

    /** @return list<array{label: string, count: int}> المسلَّمة في آخر $days يوماً، كل $step أيامٍ عمود، أقدمها أوّلاً */
    private function deliveredByDays(int $days, int $step): array
    {
        $from = today()->subDays($days - 1);
        $perDay = $this->shipments()->where('delivered_at', '>=', $from)
            ->selectRaw('date(delivered_at) as day, count(*) as c')->groupBy('day')->toBase()->pluck('c', 'day');

        return collect(range(0, intdiv($days, $step) - 1))->map(function (int $i) use ($from, $step, $perDay) {
            $start = $from->copy()->addDays($i * $step);
            $end = $start->copy()->addDays($step - 1);
            $count = 0;
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $count += (int) ($perDay[$d->toDateString()] ?? 0);
            }

            return ['label' => (string) $end->day, 'count' => $count];
        })->all();
    }

    /**
     * أسبوع الاستلام: يومان قبل اليوم وخمسةٌ بعده، وفي كلٍّ ثلاثة أوقات — فيها طلبٌ أو لا. والقادم أوّلاً
     * من اليوم بتفاصيله.
     */
    private function pickupWeek(): array
    {
        $from = today()->subDays(2);
        $to = today()->addDays(5)->endOfDay();
        $when = fn (PickupRequest $p) => $p->scheduled_at ?? $p->requested_at ?? $p->created_at;

        $pickups = PickupRequest::visibleTo($this->user)
            ->whereIn('status', ['pending', 'assigned', 'in_progress', 'completed'])
            ->where(fn ($q) => $q->whereBetween('scheduled_at', [$from, $to])
                ->orWhere(fn ($w) => $w->whereNull('scheduled_at')->whereBetween('created_at', [$from, $to])))
            ->with(['merchant:id,business_name', 'courier:id,name'])
            ->get();

        $next = $pickups->filter(fn (PickupRequest $p) => in_array($p->status, ['pending', 'assigned', 'in_progress'], true)
            && $when($p)->gte(now()->startOfDay()))->sortBy(fn (PickupRequest $p) => $when($p)->timestamp)->first();

        $days = collect(range(0, 7))->map(function (int $i) use ($from, $pickups, $when, $next) {
            $day = $from->copy()->addDays($i);

            return [
                'label' => self::DAYS[$day->dayOfWeek].' '.$day->day,
                'today' => $day->isToday(),
                'slots' => collect(self::SLOTS)->map(function (array $slot) use ($day, $pickups, $when, $next) {
                    $in = $pickups->filter(fn (PickupRequest $p) => $when($p)->isSameDay($day)
                        && $when($p)->hour >= $slot[1] && $when($p)->hour < $slot[2]);

                    return match (true) {
                        $next && $in->contains('id', $next->id) => 'next',
                        $in->isNotEmpty() => 'busy',
                        default => 'free',
                    };
                })->all(),
            ];
        })->all();

        return [
            'days' => $days,
            'next' => $next ? [
                'merchant' => $next->merchant?->business_name,
                'at' => $when($next),
                'count' => (int) $next->expected_count,
                'courier' => $next->courier?->name,
                'url' => route('pickups.index'),
            ] : null,
        ];
    }

    /** المبالغ المستلمة هذا الأسبوع، وتغيّرها عن الأسبوع الماضي بالأيام نفسها */
    private function collectedThisWeek(): array
    {
        $week = now()->startOfWeek();
        $last = $week->copy()->subWeek();
        $sum = fn (Carbon $from, Carbon $to) => (int) $this->shipments()->whereBetween('delivered_at', [$from, $to])->sum('collected_amount');
        $now = $sum($week, now());
        $before = $sum($last, $last->copy()->addSeconds(now()->diffInSeconds($week)));

        return [
            'value' => $now,
            'change' => $before > 0 ? round(100 * ($now - $before) / $before, 2) : null,
            // المقياس: هذا الأسبوع إلى أكبر الأسبوعين
            'fill' => max($now, $before) > 0 ? $now / max($now, $before) : 0,
        ];
    }

    /** آخر شحنةٍ خرجت مع مندوب، بآخر ثلاث حركاتٍ عليها */
    private function tracking(): ?array
    {
        $s = $this->shipments()->where('status', ShipmentStatus::OutForDelivery->value)
            ->with(['governorate:id,name_ar', 'deliveryCourier:id,name'])
            ->latest('status_changed_at')->first();

        if (! $s) {
            return null;
        }

        $events = $s->events()->reorder()->latest('id')->limit(3)->get();

        return [
            'number' => $s->number,
            'governorate' => $s->governorate?->name_ar,
            'courier' => $s->deliveryCourier?->name,
            'url' => route('shipments.show', $s),
            'steps' => $events->map(fn ($e) => [
                'status' => $e->toLabel(),
                // ما كُتب على الحركة («خرجت مع المندوب علي») أوضح من اسم الحالة، وإلّا عنوانها
                'text' => filled($e->note) ? $e->note : $e->headline(),
                'at' => $e->created_at,
            ])->all(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\PickupPayout;
use App\Models\PriceList;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SqlDate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * تقارير المعتاد التي لم تكن عندنا، كلٌّ بسؤاله:
 *
 * كم أُدخل ومن أدخله وفي أيّ ساعة · ما رفعه التجّار من بواباتهم وكم استُلم ·
 * من عالج المحاولات الفاشلة وبعد كم، ومن أجاز المعلّق للمراجعة · ما علق في
 * مرحلته أكثر من ساعات · ما حوسب عليه المندوب فوق أجرته · الربح بالتاجر ·
 * التجّار بأسعارٍ خاصّة · ومن لم يؤكّد استلام دفعاته · وما أُرسل من إشعارات ومن قرأها.
 */
class ReferenceReportController extends Controller
{
    public const SOURCES = [
        'web'             => 'إدخال موظّف',
        'import'          => 'رفع ملف',
        'merchant_portal' => 'بوابة التاجر',
        'api'             => 'واجهة برمجية',
    ];

    /** تطبيق كل فئة: الإشعار يصل التاجرَ في بوابته والمندوبَ في تطبيقه */
    public const NOTIFICATION_APPS = [
        'merchants'         => 'بوابة التاجر',
        'delivery_couriers' => 'تطبيق المندوب',
        'pickup_couriers'   => 'تطبيق المندوب',
    ];

    /** «عدد الشحنات المُدخلة»: بمن أدخلها وقناتها، وساعةً بساعة ببغداد */
    public function entries(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $hour = SqlDate::hour('shipments.created_at');

        $base = fn () => Shipment::query()->visibleTo($request->user())->whereBetween('shipments.created_at', [$from, $to]);

        $rows = $base()
            ->selectRaw("shipments.created_by_user_id as user_id, shipments.source as source, {$hour} as hour, count(*) as total")
            ->groupBy('shipments.created_by_user_id', 'shipments.source', 'hour')
            ->toBase()
            ->get();

        $people = $rows->groupBy(fn ($r) => $r->user_id.'|'.$r->source)
            ->map(fn (Collection $lines) => (object) [
                'user_id' => $lines->first()->user_id,
                'source'  => $lines->first()->source,
                'total'   => (int) $lines->sum('total'),
                'hours'   => $lines->mapWithKeys(fn ($r) => [(int) $r->hour => (int) $r->total])->all(),
            ])
            ->sortByDesc('total')
            ->values();

        return view('tenant.reports.reference.entries', [
            'period'  => $period,
            'people'  => $people,
            'names'   => User::whereIn('id', $people->pluck('user_id')->filter())->pluck('name', 'id'),
            'byHour'  => $rows->groupBy(fn ($r) => (int) $r->hour)->map(fn ($g) => (int) $g->sum('total')),
            'total'   => (int) $rows->sum('total'),
        ]);
    }

    /** «المُدخلة عبر تطبيق العميل» و«رفعها العملاء واستُلمت»: يوماً بيوم */
    public function portal(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $merchantId = $request->integer('merchant_id') ?: null;
        $day = SqlDate::day('shipments.created_at');

        $days = Shipment::query()->visibleTo($request->user())
            ->where('shipments.source', 'merchant_portal')
            ->whereBetween('shipments.created_at', [$from, $to])
            ->when($merchantId, fn ($q) => $q->where('shipments.merchant_id', $merchantId))
            ->selectRaw("{$day} as day, count(*) as created,
                sum(case when shipments.picked_up_at is not null then 1 else 0 end) as picked,
                sum(case when shipments.status = ? then 1 else 0 end) as cancelled", [ShipmentStatus::Cancelled->value])
            ->groupBy('day')
            ->orderByDesc('day')
            ->toBase()
            ->get();

        return view('tenant.reports.reference.portal', [
            'period'     => $period,
            'days'       => $days,
            'merchantId' => $merchantId,
            'merchants'  => Merchant::visibleTo($request->user())->orderBy('business_name')->get(['id', 'business_name']),
        ]);
    }

    /**
     * «أداء المراجعة» و«موظّفو المتابعة» و«عولجت من العميل أو الموظّف»: من سجلّ
     * الشحنات نفسه — كل قرار معالجةٍ بمن اتّخذه وبعد كم انتظرت الشحنة، وكل
     * إجازةٍ لمعلَّقٍ للمراجعة بمن أجازها وبعد كم.
     */
    public function processing(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $visible = Shipment::query()->visibleTo($request->user())->select('shipments.id');

        $events = ShipmentEvent::query()
            ->where('event_type', 'processed')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('shipment_id', $visible)
            ->get(['id', 'actor_id', 'actor_name', 'actor_type', 'meta']);

        $byPerson = $events->groupBy(fn ($e) => $e->actor_type.'|'.$e->actor_id)->map(function (Collection $group) {
            $first = $group->first();
            $waits = $group->map(fn ($e) => (int) ($e->meta['waited_minutes'] ?? 0));

            return (object) [
                'name'      => $first->actor_name,
                'by'        => $first->actor_type === 'merchant' ? 'merchant' : 'staff',
                'total'     => $group->count(),
                'actions'   => $group->countBy(fn ($e) => $e->meta['action'] ?? '—')->all(),
                'avg_wait'  => (int) round($waits->avg() ?? 0),
            ];
        })->sortByDesc('total')->values();

        $reviews = Shipment::query()->visibleTo($request->user())
            ->whereBetween('shipments.reviewed_at', [$from, $to])
            ->whereNotNull('shipments.review_hold_at')
            ->get(['shipments.id', 'shipments.reviewed_by_user_id', 'shipments.review_hold_at', 'shipments.reviewed_at'])
            ->groupBy('reviewed_by_user_id')
            ->map(fn (Collection $group, $userId) => (object) [
                'user_id'  => $userId,
                'total'    => $group->count(),
                'avg_wait' => (int) round($group->avg(fn ($s) => $s->review_hold_at->diffInMinutes($s->reviewed_at))),
            ])->sortByDesc('total')->values();

        return view('tenant.reports.reference.processing', [
            'period'   => $period,
            'people'   => $byPerson,
            'bySource' => $byPerson->groupBy('by')->map->sum('total'),
            'reviews'  => $reviews,
            'names'    => User::whereIn('id', $reviews->pluck('user_id')->filter())->pluck('name', 'id'),
            'actions'  => \App\Actions\Shipments\ProcessFailedAttempt::ACTIONS,
        ]);
    }

    /**
     * «المعلّقة في جميع المراحل» و«العالقة في فرعي»: ما لم تتحرّك مرحلته منذ
     * ساعاتٍ أكثر من حدٍّ تختاره — بمرحلته ومكانه الآن ومنذ متى.
     */
    public function stuck(Request $request): View
    {
        $hours = max(1, min(24 * 60, $request->integer('hours') ?: 48));
        $status = in_array($request->query('status'), ShipmentStatus::openValues(), true) ? $request->query('status') : null;
        $branchId = $request->integer('branch_id') ?: null;

        $shipments = Shipment::query()->visibleTo($request->user())
            ->whereIn('shipments.status', ShipmentStatus::openValues())
            ->where('shipments.status_changed_at', '<', now()->subHours($hours))
            ->when($status, fn ($q) => $q->where('shipments.status', $status))
            ->when($request->integer('governorate_id'), fn ($q, $id) => $q->where('shipments.governorate_id', $id))
            // «في فرع»: مكانها الآن مركزٌ من مراكزه
            ->when($branchId, fn ($q) => $q->whereIn('shipments.hub_id', \App\Models\Hub::query()->select('id')->where('branch_id', $branchId)))
            ->with(['merchant:id,business_name', 'branch:id,name', 'hub:id,name,branch_id', 'hub.branch:id,name',
                'deliveryCourier:id,name', 'governorate:id,name_ar'])
            ->orderBy('shipments.status_changed_at')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.reports.reference.stuck', [
            'shipments'    => $shipments,
            'hours'        => $hours,
            'status'       => $status,
            'branches'     => Branch::orderBy('name')->get(['id', 'name']),
            'governorates' => Governorate::where('is_active', true)->orderedForCompany()->get(['id', 'name_ar']),
        ]);
    }

    /** «وصولات حوسب عليها المندوب بتكلفة أعلى»: حصّته أكبر من أجرة توصيلها */
    public function courierOvercharge(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $query = fn () => Shipment::query()->visibleTo($request->user())
            ->whereNotNull('shipments.delivered_at')
            ->whereBetween('shipments.status_changed_at', [$from, $to])
            ->whereRaw('shipments.courier_commission > shipments.delivery_fee + shipments.extra_fee')
            ->when($request->integer('courier_id'), fn ($q, $id) => $q->where('shipments.delivery_courier_id', $id))
            ->when($request->query('settled') === 'yes', fn ($q) => $q->whereNotNull('shipments.courier_settled_at'))
            ->when($request->query('settled') === 'no', fn ($q) => $q->whereNull('shipments.courier_settled_at'));

        $totals = $query()->selectRaw('count(*) as shipments, sum(shipments.courier_commission) as commission,
                sum(shipments.delivery_fee + shipments.extra_fee) as fees')->toBase()->first();

        return view('tenant.reports.reference.courier-overcharge', [
            'period'    => $period,
            'shipments' => $query()->with(['deliveryCourier:id,name', 'branch:id,name', 'governorate:id,name_ar'])
                ->orderByDesc('shipments.status_changed_at')->paginate(config('zajel.per_page'))->withQueryString(),
            'totals'    => $totals,
            'couriers'  => Courier::delivering()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** «الأرباح على أساس العملاء»: لكل تاجر ما دخل من أجوره وما خرج عمولاتٍ عليه */
    public function merchantProfit(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $rows = Shipment::query()->visibleTo($request->user())
            ->whereIn('shipments.status', [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value, ShipmentStatus::Returned->value])
            ->whereBetween('shipments.status_changed_at', [$from, $to])
            // باقي الواصل الجزئي مسلَّمٌ بأجوره كاملةً (Shipment::sqlRevenue)
            ->selectRaw('shipments.merchant_id,
                sum(case when '.Shipment::sqlPlainReturn().' then 0 else 1 end) as delivered,
                sum(case when '.Shipment::sqlPlainReturn().' then 1 else 0 end) as returned,
                sum('.Shipment::sqlRevenue().') as revenue,
                sum(shipments.courier_commission) as commission')
            ->groupBy('shipments.merchant_id')
            ->orderByRaw('sum('.Shipment::sqlRevenue().') - sum(shipments.courier_commission) desc')
            ->toBase()
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.reports.reference.merchant-profit', [
            'period' => $period,
            'rows'   => $rows,
            'names'  => Merchant::withTrashed()->whereIn('id', collect($rows->items())->pluck('merchant_id'))->pluck('business_name', 'id'),
        ]);
    }

    /** «الزبائن ذوو الأسعار الخاصّة»: من على تسعيرةٍ غير الافتراضية، وأسعارها */
    public function specialPrices(Request $request): View
    {
        $default = PriceList::where('is_default', true)->value('id');

        $merchants = Merchant::query()
            ->visibleTo($request->user())
            ->whereNotNull('price_list_id')
            ->when($default, fn ($q) => $q->where('price_list_id', '!=', $default))
            ->with(['priceList:id,name', 'priceList.rules' => fn ($q) => $q->whereNull('to_city_id')->where('weight_from_grams', 0)
                ->with('toGovernorate:id,name_ar')])
            ->orderBy('business_name')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.reports.reference.special-prices', ['merchants' => $merchants]);
    }

    /** «عملاء لم يؤكّدوا دفعات» و«مندوبو استلام لم يؤكّدوا دفعات» */
    public function unconfirmed(Request $request): View
    {
        return view('tenant.reports.reference.unconfirmed', [
            'merchants' => MerchantSettlement::query()->visibleTo($request->user())
                ->where('status', 'paid')->whereNull('merchant_confirmed_at')
                ->selectRaw('merchant_id, count(*) as payments, sum(net_amount) as total, max(paid_at) as last_paid')
                ->groupBy('merchant_id')->orderByDesc('last_paid')->toBase()->get(),
            'couriers'  => PickupPayout::query()->visibleTo($request->user())
                ->whereNull('confirmed_at')->where('paid_amount', '>', 0)
                ->selectRaw('courier_id, count(*) as payments, sum(paid_amount) as total, max(created_at) as last_paid')
                ->groupBy('courier_id')->orderByDesc('last_paid')->toBase()->get(),
            'merchantNames' => Merchant::withTrashed()->visibleTo($request->user())->pluck('business_name', 'id'),
            'courierNames'  => Courier::withTrashed()->visibleTo($request->user())->pluck('name', 'id'),
        ]);
    }

    /**
     * «سجلّ الإشعارات»: ما أُرسل في المدّة — لأيّ تطبيق وأيّ فئة، ومن أرسله،
     * وكم قرأه. وباختيار تاجرٍ أو مندوب: ما وُجِّه إلى فئته، وهل قرأه ومتى.
     */
    public function notifications(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $audience = $request->query('audience');
        $audience = is_string($audience) && array_key_exists($audience, Announcement::AUDIENCES) ? $audience : null;

        $viewer = $request->user();
        // تجّار فرعه ومناديبه، وما أرسله فرعه، إن كان مقيَّداً بفرع
        $recipients = User::whereIn('role', [UserRole::Merchant, UserRole::Courier])
            ->when($viewer->isBranchLimited(), fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('merchant', fn ($m) => $m->visibleTo($viewer))
                ->orWhereHas('courier', fn ($c) => $c->visibleTo($viewer))))
            ->orderBy('name')->get(['id', 'name', 'role']);
        $user = $recipients->firstWhere('id', $request->integer('user_id') ?: null);
        $userAudiences = $user ? Announcement::audiencesFor($user) : null;

        $announcements = Announcement::query()
            ->visibleTo($viewer)
            ->with('author:id,name')
            ->withCount('reads')
            ->whereBetween('announcements.created_at', [$from, $to])
            ->when($audience, fn ($q) => $q->where('audience', $audience))
            ->when($user, fn ($q) => $q->whereIn('audience', $userAudiences ?: ['—'])
                ->with(['reads' => fn ($r) => $r->where('user_id', $user->id)]))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        $sent = Announcement::query()
            ->visibleTo($viewer)
            ->whereBetween('announcements.created_at', [$from, $to])
            ->selectRaw('audience, count(*) as total')
            ->groupBy('audience')->pluck('total', 'audience');

        $reads = AnnouncementRead::query()
            ->join('announcements', 'announcements.id', '=', 'announcement_reads.announcement_id')
            ->whereIn('announcement_reads.announcement_id', Announcement::query()->visibleTo($viewer)->select('announcements.id'))
            ->whereBetween('announcements.created_at', [$from, $to])
            ->selectRaw('announcements.audience as audience, count(*) as total')
            ->groupBy('announcements.audience')->pluck('total', 'audience');

        return view('tenant.reports.reference.notifications', [
            'period'        => $period,
            'announcements' => $announcements,
            'audience'      => $audience,
            'audiences'     => Announcement::AUDIENCES,
            'apps'          => self::NOTIFICATION_APPS,
            'sent'          => $sent,
            'reads'         => $reads,
            // البلوغ لكل فئة مرّةً لا لكل إشعار
            'reach'         => collect(Announcement::AUDIENCES)->keys()
                ->mapWithKeys(fn ($a) => [$a => (new Announcement([
                    'audience' => $a, 'branch_id' => $viewer->isBranchLimited() ? $viewer->branch_id : null,
                ]))->reach()]),
            'recipients'    => $recipients,
            'user'          => $user,
            'userAudiences' => $userAudiences,
            'userRead'      => $user
                ? AnnouncementRead::where('user_id', $user->id)
                    ->whereHas('announcement', fn ($a) => $a->visibleTo($viewer)->whereBetween('created_at', [$from, $to])->whereIn('audience', $userAudiences ?: ['—']))
                    ->count()
                : null,
        ]);
    }
}

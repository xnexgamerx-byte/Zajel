<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Merchant;
use App\Models\PickupPayout;
use App\Models\PickupShare;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SqlDate;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * تقارير تتبّع الدفق — من استلام الطرد من التاجر إلى تسوية حسابه، ومن فرعٍ إلى فرع
 * (docs/plan/17 §٨ · المرحلة ٦): ما كان يُجاب عنه من شاشاتٍ متفرّقة صار له تقريره.
 *
 * المستلمة من مندوب الاستلام · أداء مندوبي الاستلام وأرباحهم · الواصل الذي لم
 * يُحاسَب عليه التاجر · ما تغيّرت أسعاره ولم يُحاسَب عليه · توزيع شحنات التجّار
 * بالنتيجة · وشحنات الفروع القادمة والخارجة بالساعة.
 *
 * كل تقرير يقول ما الذي تقيسه مدّته: الاستلام بتاريخ استلام الطرد، والأداء بتاريخ
 * استلامه كذلك (فالنتيجة تُنسب إلى من استلم)، والتوزيع بتاريخ الإنشاء.
 */
class FlowReportController extends Controller
{
    /*
    | تلميح الفهرس (useIndex) في استعلامات المدّة على الشحنات: MySQL يُسيء تقدير
    | فهرس (company_id, number) فيمسح شحنات الشركة كلّها بدل مدى التاريخ — ٣٩٤
    | مللي ثانية لشهرٍ على ١٦٥ ألف شحنة، وبالتلميح ٤٠. وSQLite يتجاهله.
    */

    /** أقصى تعديلات سعرٍ يُحلَّل في تقرير «تغيّرت أسعارها» — فوقها تُضيَّق المدّة */
    public const REPRICED_EVENTS_CAP = 5000;

    /** مجموعات النتيجة في «التوزيع»: لكلٍّ لونها الثابت في التقارير كلّها (اللوحة مُتحقَّقٌ منها) */
    public const OUTCOMES = [
        'delivered' => ['label' => 'وصلت',     'color' => '#047857'],
        'returned'  => ['label' => 'رجعت',     'color' => '#D97706'],
        'open'      => ['label' => 'قيد التنفيذ', 'color' => '#1D4ED8'],
        'other'     => ['label' => 'ملغاة وغيرها', 'color' => '#A8A29E'],
    ];

    /**
     * «المستلمة من مندوب الاستلام»: لكل مندوبٍ كم جمع في المدّة، وكم استلمناه منه،
     * وكم ما زال بيده (قبل أن يصل مخزننا) — وأقدم ما بيده.
     */
    public function pickupReceived(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $picked = ShipmentStatus::PickedUp->value;
        $cancelled = ShipmentStatus::Cancelled->value;

        $rows = Shipment::query()->useIndex('sh_pickup_idx')->visibleTo($request->user())
            ->join('couriers', 'couriers.id', '=', 'shipments.pickup_courier_id')
            ->whereBetween('shipments.picked_up_at', [$from, $to])
            ->when($request->integer('courier_id'), fn ($q, $id) => $q->where('shipments.pickup_courier_id', $id))
            ->selectRaw('couriers.id as courier_id, couriers.name as name,
                count(*) as total,
                sum(case when shipments.status = ? then 1 else 0 end) as remaining,
                sum(case when shipments.status = ? then 1 else 0 end) as cancelled,
                sum(shipments.cod_amount) as total_amount,
                sum(case when shipments.status = ? then shipments.cod_amount else 0 end) as remaining_amount,
                sum(case when shipments.status = ? then shipments.cod_amount else 0 end) as cancelled_amount,
                min(case when shipments.status = ? then shipments.status_changed_at end) as oldest',
                [$picked, $cancelled, $picked, $cancelled, $picked])
            ->groupBy('couriers.id', 'couriers.name')
            ->orderByDesc('remaining')
            ->orderBy('couriers.name')
            ->toBase()
            ->get()
            ->map(function ($row) {
                // المستلَم = ما غادر يده ولم يُلغَ: ما بقي بعد الباقي والملغاة
                $row->received = (int) $row->total - (int) $row->remaining - (int) $row->cancelled;
                $row->received_amount = (int) $row->total_amount - (int) $row->remaining_amount - (int) $row->cancelled_amount;
                $row->oldest = $row->oldest ? \Illuminate\Support\Carbon::parse($row->oldest) : null;

                return $row;
            });

        return view('tenant.reports.flow.pickup-received', [
            'period'   => $period,
            'rows'     => $rows,
            'totals'   => (object) [
                'total'            => (int) $rows->sum('total'),
                'received'         => (int) $rows->sum('received'),
                'remaining'        => (int) $rows->sum('remaining'),
                'cancelled'        => (int) $rows->sum('cancelled'),
                'total_amount'     => (int) $rows->sum('total_amount'),
                'received_amount'  => (int) $rows->sum('received_amount'),
                'remaining_amount' => (int) $rows->sum('remaining_amount'),
            ],
            'couriers' => Courier::picking()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * «أداء مندوبي الاستلام»: مصير ما استلمه كلٌّ في المدّة — وصل، رجع، قيد التوصيل،
     * مؤجَّل — ومعه أرباحه: ما استحقّه عن الاستلام في المدّة وما دُفع له.
     */
    public function pickupPerformance(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $user = $request->user();
        $courierId = $request->integer('courier_id') ?: null;

        $in = fn (ShipmentStatus ...$statuses) => implode(',', array_map(fn (ShipmentStatus $s) => "'{$s->value}'", $statuses));

        // أسماء الحالات من التعداد لا من المستخدم: تُدرَج في الاستعلام نصّاً ثابتاً
        $outcomes = Shipment::query()->useIndex('sh_pickup_idx')->visibleTo($user)
            ->whereBetween('shipments.picked_up_at', [$from, $to])
            ->whereNotNull('shipments.pickup_courier_id')
            ->when($courierId, fn ($q) => $q->where('shipments.pickup_courier_id', $courierId))
            ->selectRaw('shipments.pickup_courier_id as courier_id,
                count(*) as total,
                sum(case when shipments.status in ('.$in(ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered).') then 1 else 0 end) as delivered,
                sum(case when shipments.status in ('.$in(ShipmentStatus::Returning, ShipmentStatus::Returned).') then 1 else 0 end) as returned,
                sum(case when shipments.status = '.$in(ShipmentStatus::OutForDelivery).' then 1 else 0 end) as in_delivery,
                sum(case when shipments.status = '.$in(ShipmentStatus::Postponed).' then 1 else 0 end) as postponed')
            ->groupBy('shipments.pickup_courier_id')
            ->toBase()->get()->keyBy('courier_id');

        // أرباحه لمن يرى حساب مندوبي الاستلام وحده (pickup-agents تحت money.view)
        $money = $user->can('money.view');

        // ما استحقّه: حصصه المحتسَبة في المدّة بما استقرّ عليه الاعتراض
        $earned = ! $money ? collect() : PickupShare::query()->visibleTo($user)
            ->whereBetween('pickup_shares.created_at', [$from, $to])
            ->when($courierId, fn ($q) => $q->where('pickup_shares.courier_id', $courierId))
            ->selectRaw('pickup_shares.courier_id, sum(pickup_shares.amount + pickup_shares.adjustment) as earned')
            ->groupBy('pickup_shares.courier_id')
            ->toBase()->pluck('earned', 'courier_id');

        // وما دُفع له في المدّة
        $paid = ! $money ? collect() : PickupPayout::query()->visibleTo($user)
            ->whereBetween('pickup_payouts.created_at', [$from, $to])
            ->when($courierId, fn ($q) => $q->where('pickup_payouts.courier_id', $courierId))
            ->selectRaw('pickup_payouts.courier_id, sum(pickup_payouts.paid_amount) as paid')
            ->groupBy('pickup_payouts.courier_id')
            ->toBase()->pluck('paid', 'courier_id');

        $ids = $outcomes->keys()->merge($earned->keys())->merge($paid->keys())->unique()->values();
        $names = Courier::withTrashed()->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(fn ($id) => (object) [
            'courier_id'  => (int) $id,
            'name'        => $names[$id] ?? '—',
            'total'       => (int) ($outcomes[$id]->total ?? 0),
            'delivered'   => (int) ($outcomes[$id]->delivered ?? 0),
            'returned'    => (int) ($outcomes[$id]->returned ?? 0),
            'in_delivery' => (int) ($outcomes[$id]->in_delivery ?? 0),
            'postponed'   => (int) ($outcomes[$id]->postponed ?? 0),
            'earned'      => (int) ($earned[$id] ?? 0),
            'paid'        => (int) ($paid[$id] ?? 0),
        ])->sortByDesc('total')->values();

        return view('tenant.reports.flow.pickup-performance', [
            'period'   => $period,
            'money'    => $money,
            'rows'     => $rows,
            'totals'   => (object) [
                'total'       => (int) $rows->sum('total'),
                'delivered'   => (int) $rows->sum('delivered'),
                'returned'    => (int) $rows->sum('returned'),
                'in_delivery' => (int) $rows->sum('in_delivery'),
                'postponed'   => (int) $rows->sum('postponed'),
                'earned'      => (int) $rows->sum('earned'),
                'paid'        => (int) $rows->sum('paid'),
            ],
            'couriers' => Courier::picking()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * «سُلّمت ولم يُحاسَب عليها التجّار»: ما وصل وبقي عندنا من مال التاجر — لكل تاجرٍ
     * عدد وصولاته وما حُصِّل والصافي له بعد الأجور، وأقدمها. حالٌ لا مدّة: ما لم
     * يُسوَّ يبقى هنا مهما قدُم، و«مضى عليه أكثر من» يعزل المتأخّر.
     */
    public function unsettled(Request $request): View
    {
        $days = max(0, min(3650, $request->integer('days')));
        $merchantId = $request->integer('merchant_id') ?: null;

        $base = fn () => Shipment::query()->visibleTo($request->user())
            ->whereIn('shipments.status', [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value])
            ->whereNull('shipments.merchant_settled_at')
            ->when($merchantId, fn ($q) => $q->where('shipments.merchant_id', $merchantId))
            ->when($days, fn ($q) => $q->where('shipments.status_changed_at', '<', now()->subDays($days)));

        $sums = 'count(*) as shipments, sum(shipments.collected_amount) as collected,
            sum(shipments.merchant_due) as net, min(shipments.status_changed_at) as oldest';

        $rows = $base()
            ->selectRaw("shipments.merchant_id, {$sums}")
            ->groupBy('shipments.merchant_id')
            ->orderByRaw('min(shipments.status_changed_at)')
            ->toBase()
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.reports.flow.unsettled', [
            'rows'       => $rows,
            'totals'     => $base()->selectRaw($sums)->toBase()->first(),
            'names'      => Merchant::withTrashed()->whereIn('id', collect($rows->items())->pluck('merchant_id'))->pluck('business_name', 'id'),
            'days'       => $days,
            'merchantId' => $merchantId,
            'merchants'  => Merchant::visibleTo($request->user())->orderBy('business_name')->get(['id', 'business_name']),
        ]);
    }

    /**
     * «تغيّرت أسعارها ولم يُحاسَب التاجر»: شحناتٌ عُدّل سعرها (الأجور) بعد إنشائها في
     * المدّة ولم تدخل تسوية تاجرها بعد — فيُعرف الفرق قبل أن يُدفع. من سجلّ التعديل
     * نفسه: الأجور قبل أوّل تعديلٍ وبعد آخره، ومن عدّل.
     */
    public function repriced(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $merchantId = $request->integer('merchant_id') ?: null;

        $events = ShipmentEvent::query()
            ->where('event_type', 'edited')
            ->whereBetween('created_at', [$from, $to])
            // المفتاح في meta كما يكتبه UpdateShipment: يُنتقى قبل التحميل فلا يُقرأ كل تعديل
            ->where('meta', 'like', '%"total_fees"%')
            ->whereIn('shipment_id', Shipment::query()->visibleTo($request->user())
                ->whereNull('shipments.merchant_settled_at')
                ->when($merchantId, fn ($q) => $q->where('shipments.merchant_id', $merchantId))
                ->select('shipments.id'))
            ->orderByDesc('id')
            ->limit(self::REPRICED_EVENTS_CAP + 1)
            ->get(['id', 'shipment_id', 'meta', 'actor_name', 'created_at']);

        $truncated = $events->count() > self::REPRICED_EVENTS_CAP;
        $events = $events->take(self::REPRICED_EVENTS_CAP)->reverse()->values();

        // لكل شحنةٍ: الأجور قبل أوّل تعديل وبعد آخره. تعديلٌ أعاد السعر كما كان لا أثر له
        $changes = $events->groupBy('shipment_id')->map(function (Collection $list) {
            $steps = $list->map(fn ($event) => $event->meta['changes']['total_fees'] ?? null)->filter();

            if ($steps->isEmpty()) {
                return null;
            }

            $before = (int) $steps->first()['from'];
            $after = (int) $steps->last()['to'];

            return $before === $after ? null : (object) [
                'before' => $before,
                'after'  => $after,
                'diff'   => $after - $before,
                'by'     => $list->pluck('actor_name')->filter()->unique()->implode('، '),
                'at'     => $list->last()->created_at,
                'edits'  => $list->count(),
            ];
        })->filter();

        $shipments = Shipment::query()->whereIn('id', $changes->keys())
            ->with(['merchant:id,business_name'])
            ->get()->keyBy('id');

        $list = $changes->map(fn ($change, $id) => (object) ((array) $change + ['shipment' => $shipments[$id] ?? null]))
            ->filter(fn ($row) => $row->shipment !== null)
            ->sortByDesc('at')
            ->values();

        $byMerchant = $list->groupBy(fn ($row) => $row->shipment->merchant_id)->map(fn (Collection $rows) => (object) [
            'name'      => $rows->first()->shipment->merchant?->business_name ?? '—',
            'shipments' => $rows->count(),
            'diff'      => (int) $rows->sum('diff'),
        ])->sortByDesc(fn ($row) => abs($row->diff))->values();

        $perPage = (int) config('zajel.per_page');
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('tenant.reports.flow.repriced', [
            'period'     => $period,
            'rows'       => new LengthAwarePaginator($list->forPage($page, $perPage), $list->count(), $perPage, $page, [
                'path' => $request->url(), 'query' => $request->query(),
            ]),
            'byMerchant' => $byMerchant,
            'net'        => (int) $list->sum('diff'),
            'truncated'  => $truncated,
            'merchantId' => $merchantId,
            'merchants'  => Merchant::visibleTo($request->user())->orderBy('business_name')->get(['id', 'business_name']),
        ]);
    }

    /**
     * «التوزيع البيانيّ»: كيف توزّعت شحنات المدّة بين ما وصل وما رجع وما زال قيد
     * التنفيذ — لكل تاجرٍ من الأكثر حجماً، أو يوماً بيوم لتاجرٍ بعينه. بتاريخ الإنشاء.
     */
    public function distribution(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $user = $request->user();
        $merchantId = $request->integer('merchant_id') ?: null;
        $merchant = $merchantId ? Merchant::visibleTo($user)->find($merchantId) : null;

        $list = fn (ShipmentStatus ...$statuses) => implode(',', array_map(fn (ShipmentStatus $s) => "'{$s->value}'", $statuses));
        $delivered = $list(ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered);
        $returned = $list(ShipmentStatus::Returning, ShipmentStatus::Returned);
        $other = $list(ShipmentStatus::Cancelled, ShipmentStatus::Lost, ShipmentStatus::Damaged);

        // الحالات من التعداد لا من المستخدم: تُدرَج في الاستعلام نصّاً ثابتاً
        $sums = "count(*) as total,
            sum(case when shipments.status in ({$delivered}) then 1 else 0 end) as delivered,
            sum(case when shipments.status in ({$returned}) then 1 else 0 end) as returned,
            sum(case when shipments.status in ({$other}) then 1 else 0 end) as other";

        // تاجرٌ بعينه يجد فهرس التاجر وحده؛ والكلّ يحتاج تلميحاً إلى فهرس التاريخ (انظر أعلى الصنف)
        $base = fn () => Shipment::query()->when(! $merchant, fn ($q) => $q->useIndex('sh_created_idx'))
            ->visibleTo($user)->whereBetween('shipments.created_at', [$from, $to]);
        $withOpen = fn ($row) => tap($row, fn ($r) => $r->open = (int) $r->total - (int) $r->delivered - (int) $r->returned - (int) $r->other);

        $totals = $withOpen($base()->when($merchant, fn ($q) => $q->where('shipments.merchant_id', $merchant->id))
            ->selectRaw($sums)->toBase()->first());

        if ($merchant) {
            // يوماً بيوم، وشهراً بشهر إن طالت المدّة فلا تضيق الأعمدة عن القراءة
            $monthly = $period->days() > 62;
            $bucket = $monthly ? SqlDate::month('shipments.created_at') : SqlDate::day('shipments.created_at');

            $found = $base()->where('shipments.merchant_id', $merchant->id)
                ->selectRaw("{$bucket} as bucket, {$sums}")
                ->groupBy('bucket')->toBase()->get()->map($withOpen)->keyBy('bucket');

            // الشهريّ يبدأ من أوّل الشهر: addMonth من يوم ٣١ يقفز شهراً قصيراً
            $buckets = collect();
            $start = $monthly ? $period->from->copy()->startOfMonth() : $period->from->copy();
            for ($cursor = $start; $cursor->lte($period->to); $monthly ? $cursor->addMonth() : $cursor->addDay()) {
                $key = $monthly ? $cursor->format('Y-m') : $cursor->toDateString();
                $row = $found[$key] ?? null;

                $buckets->push((object) [
                    'key'       => $key,
                    'label'     => $monthly ? $key : $cursor->format('m-d'),
                    'total'     => (int) ($row->total ?? 0),
                    'delivered' => (int) ($row->delivered ?? 0),
                    'returned'  => (int) ($row->returned ?? 0),
                    'open'      => (int) ($row->open ?? 0),
                    'other'     => (int) ($row->other ?? 0),
                ]);
            }

            $merchants = null;
            $rest = null;
        } else {
            // الأكثر حجماً ثم «باقي التجّار» مجتمعين: لا اثنا عشر تاجراً يُخفون الباقين
            $top = $base()->selectRaw("shipments.merchant_id, {$sums}")
                ->groupBy('shipments.merchant_id')->orderByDesc('total')->limit(12)
                ->toBase()->get()->map($withOpen);

            $restOf = (object) [
                'total'     => (int) $totals->total - (int) $top->sum('total'),
                'delivered' => (int) $totals->delivered - (int) $top->sum('delivered'),
                'returned'  => (int) $totals->returned - (int) $top->sum('returned'),
                'other'     => (int) $totals->other - (int) $top->sum('other'),
            ];
            $restOf->open = $restOf->total - $restOf->delivered - $restOf->returned - $restOf->other;

            $names = Merchant::withTrashed()->whereIn('id', $top->pluck('merchant_id'))->pluck('business_name', 'id');
            $merchants = $top->map(fn ($row) => (object) [
                'name'      => $names[$row->merchant_id] ?? '—',
                'id'        => (int) $row->merchant_id,
                'total'     => (int) $row->total,
                'delivered' => (int) $row->delivered,
                'returned'  => (int) $row->returned,
                'open'      => (int) $row->open,
                'other'     => (int) $row->other,
            ]);
            $rest = $restOf->total > 0 ? $restOf : null;
            $buckets = null;
        }

        return view('tenant.reports.flow.distribution', [
            'period'     => $period,
            'merchant'   => $merchant,
            'totals'     => $totals,
            'buckets'    => $buckets,
            'monthly'    => $merchant ? $period->days() > 62 : false,
            'merchants'  => $merchants,
            'rest'       => $rest,
            'outcomes'   => self::OUTCOMES,
            'choices'    => Merchant::visibleTo($user)->orderBy('business_name')->get(['id', 'business_name']),
        ]);
    }

    /**
     * «شحنات الفروع الأخرى القادمة والخارجة»: ما خرج من فرعٍ على كشوف النقل وما وصل
     * إليه — بالمدّة، وبالساعة من اليوم ببغداد، ومع أيّ فرعٍ. الفرع المقيَّد يرى فرعه
     * وحده؛ والرئيسي يختار فرعاً أو يرى الشركة كلّها بين فروعها.
     */
    public function branchTraffic(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $viewer = $request->user();

        $branchId = $viewer->isBranchLimited() ? (int) $viewer->branch_id : ($request->integer('branch_id') ?: null);
        $branchOf = Hub::query()->pluck('branch_id', 'id');
        $mine = $branchId ? $branchOf->filter(fn ($b) => (int) $b === $branchId)->keys() : null;

        $movement = fn (string $at, string $side) => Manifest::query()->visibleTo($viewer)
            ->whereNotNull("manifests.{$at}")
            ->whereBetween("manifests.{$at}", [$from, $to])
            ->when($mine !== null, fn ($q) => $q->whereIn("manifests.{$side}", $mine->all() ?: [0]))
            ->selectRaw('manifests.from_hub_id, manifests.to_hub_id, '.SqlDate::hour("manifests.{$at}").' as hour,
                count(*) as manifests, sum(manifests.shipments_count) as shipments')
            ->groupBy('manifests.from_hub_id', 'manifests.to_hub_id', 'hour')
            ->toBase()->get();

        $out = $movement('departed_at', 'from_hub_id');
        $in = $movement('arrived_at', 'to_hub_id');

        // الفرع المقابل: لمن خرجت (مركز الوصول) ومن أين وصلت (مركز المغادرة)
        $counterpart = function (Collection $rows, string $theirHub) use ($branchOf) {
            return $rows->groupBy(fn ($r) => (int) ($branchOf[$r->{$theirHub}] ?? 0))->map(fn (Collection $g) => (object) [
                'shipments' => (int) $g->sum('shipments'),
                'manifests' => (int) $g->sum('manifests'),
            ]);
        };
        $byHour = fn (Collection $rows) => $rows->groupBy(fn ($r) => (int) $r->hour)->map(fn (Collection $g) => (int) $g->sum('shipments'));

        $branches = Branch::orderBy('name')->get(['id', 'name'])->keyBy('id');

        // الشركة كلّها: كل فرعٍ بما خرج منه وما وصل إليه
        $overview = null;
        if ($branchId === null) {
            $outBy = $out->groupBy(fn ($r) => (int) ($branchOf[$r->from_hub_id] ?? 0));
            $inBy = $in->groupBy(fn ($r) => (int) ($branchOf[$r->to_hub_id] ?? 0));
            $overview = $branches->map(fn ($b) => (object) [
                'name'         => $b->name,
                'out'          => (int) ($outBy[$b->id] ?? collect())->sum('shipments'),
                'out_manifests' => (int) ($outBy[$b->id] ?? collect())->sum('manifests'),
                'in'           => (int) ($inBy[$b->id] ?? collect())->sum('shipments'),
                'in_manifests' => (int) ($inBy[$b->id] ?? collect())->sum('manifests'),
            ])->filter(fn ($row) => $row->out || $row->in)->values();
        }

        return view('tenant.reports.flow.branch-traffic', [
            'period'      => $period,
            'branch'      => $branchId ? $branches[$branchId] ?? null : null,
            'branches'    => $branches,
            'limited'     => $viewer->isBranchLimited(),
            'overview'    => $overview,
            'outTo'       => $branchId ? $counterpart($out, 'to_hub_id') : null,
            'inFrom'      => $branchId ? $counterpart($in, 'from_hub_id') : null,
            'outByHour'   => $byHour($out),
            'inByHour'    => $byHour($in),
            'outTotal'    => (int) $out->sum('shipments'),
            'inTotal'     => (int) $in->sum('shipments'),
            'outManifests' => (int) $out->sum('manifests'),
            'inManifests' => (int) $in->sum('manifests'),
        ]);
    }
}

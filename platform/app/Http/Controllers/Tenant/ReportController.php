<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SqlDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * عشرة تقارير لا واحد وثلاثون.
 *
 * النظام المرجعي فيه ٣١ تقريراً، والباقي تنويعات على السؤال نفسه.
 * فالمقياس هنا: كل تقرير يُجيب سؤالاً يُتّخذ بعده قرار — ومن لا قرار
 * بعده لا يُبنى. سبعةٌ تقيس الأداء والمال، وثلاثةٌ تسأل عمّا يَفوت
 * بصمت: تاجرٌ توقّف، رصيدٌ سالبٌ يكبر، وتغييرٌ لا يُعرَف مَن أجراه.
 */
class ReportController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.reports.index', ['period' => ReportPeriod::fromRequest($request)]);
    }

    /**
     * ١ — لماذا ترجع شحناتي؟
     *
     * أهمّ تقرير في النظام كلّه. التاجر الذي يعرف أن ١٨٪ من رواجعه
     * «رقم الهاتف خطأ» يُصلح بياناته، والذي لا يعرف يتّهم المندوب.
     */
    public function returns(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $failed = fn () => Shipment::query()
            ->visibleTo($request->user())
            ->whereNotNull('last_failure_reason_id')
            ->whereBetween('status_changed_at', [$from, $to]);

        return view('tenant.reports.returns', [
            'period'  => $period,
            'reasons' => $failed()
                ->join('failure_reasons', 'failure_reasons.id', '=', 'shipments.last_failure_reason_id')
                ->selectRaw('failure_reasons.name_ar as name, failure_reasons.category as category,
                             count(*) as total,
                             sum(case when shipments.status = ? then 1 else 0 end) as returned', [ShipmentStatus::Returned->value])
                ->groupBy('failure_reasons.id', 'failure_reasons.name_ar', 'failure_reasons.category')
                ->orderByDesc('total')
                ->toBase()
                ->get(),

            // التصنيف هو ما يُحوّل القائمة إلى قرار: خلل عند الزبون أم عند العنوان أم عند التاجر
            'byCategory' => $failed()
                ->join('failure_reasons', 'failure_reasons.id', '=', 'shipments.last_failure_reason_id')
                ->selectRaw('failure_reasons.category as category, count(*) as total')
                ->groupBy('failure_reasons.category')
                ->orderByDesc('total')
                ->toBase()
                ->get(),

            // ومَن يتحمّل الأكثر: التاجر الذي تتكرّر رواجعه يحتاج مكالمة لا تقريراً
            'byMerchant' => $failed()
                ->join('merchants', 'merchants.id', '=', 'shipments.merchant_id')
                ->selectRaw('merchants.business_name as name, count(*) as total')
                ->groupBy('merchants.id', 'merchants.business_name')
                ->orderByDesc('total')
                ->limit(10)
                ->toBase()
                ->get(),
        ]);
    }

    /** ٢ — أداء المندوبين: ما أُنجز في المدّة لا ما أُنشئ فيها. */
    public function couriers(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $rows = Shipment::query()
            ->visibleTo($request->user())
            ->join('couriers', 'couriers.id', '=', 'shipments.delivery_courier_id')
            ->whereBetween('shipments.status_changed_at', [$from, $to])
            ->whereIn('shipments.status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
                ShipmentStatus::FailedAttempt->value,
            ])
            ->selectRaw("couriers.name as name, couriers.id as courier_id,
                count(*) as total,
                sum(case when shipments.status in (?, ?) then 1 else 0 end) as delivered,
                sum(case when shipments.status = ? then 1 else 0 end) as returned,
                sum(case when shipments.status = ? then 1 else 0 end) as failed,
                sum(case when shipments.status in (?, ?) then shipments.collected_amount else 0 end) as collected,
                sum(shipments.courier_commission) as commission", [
                ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
                ShipmentStatus::FailedAttempt->value,
                ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value,
            ])
            ->groupBy('couriers.id', 'couriers.name')
            ->orderByDesc('delivered')
            ->toBase()
            ->get();

        return view('tenant.reports.couriers', ['period' => $period, 'rows' => $rows]);
    }

    /** ٣ — أداء التجّار: شحنات المدّة ومصيرها. */
    public function merchants(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $rows = Shipment::query()
            ->visibleTo($request->user())
            ->join('merchants', 'merchants.id', '=', 'shipments.merchant_id')
            ->whereBetween('shipments.created_at', [$from, $to])
            ->selectRaw("merchants.business_name as name, merchants.id as merchant_id,
                count(*) as total,
                sum(case when shipments.status in (?, ?) then 1 else 0 end) as delivered,
                sum(case when shipments.status = ? then 1 else 0 end) as returned,
                sum(case when shipments.status not in (?, ?, ?, ?, ?) then 1 else 0 end) as still_open,
                sum(shipments.cod_amount) as cod,
                sum(shipments.total_fees) as fees", [
                ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
                ...array_map(fn (ShipmentStatus $s) => $s->value, ShipmentStatus::terminal()),
            ])
            ->groupBy('merchants.id', 'merchants.business_name')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        return view('tenant.reports.merchants', ['period' => $period, 'rows' => $rows]);
    }

    /** ٤ — أين ننجح وأين نفشل جغرافياً. */
    public function governorates(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $rows = Shipment::query()
            ->visibleTo($request->user())
            ->join('governorates', 'governorates.id', '=', 'shipments.governorate_id')
            ->whereBetween('shipments.created_at', [$from, $to])
            ->selectRaw("governorates.name_ar as name,
                count(*) as total,
                sum(case when shipments.status in (?, ?) then 1 else 0 end) as delivered,
                sum(case when shipments.status = ? then 1 else 0 end) as returned,
                sum(shipments.delivery_fee) as fees", [
                ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::Returned->value,
            ])
            ->groupBy('governorates.id', 'governorates.name_ar')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        return view('tenant.reports.governorates', ['period' => $period, 'rows' => $rows]);
    }

    /** ٥ — الحركة اليومية: داخل وخارج، يوماً بيوم. */
    public function daily(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $count = fn (string $column, ?string $status = null) => Shipment::query()
            ->visibleTo($request->user())
            ->whereBetween($column, [$from, $to])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->selectRaw(SqlDate::day($column).' as day, count(*) as total')
            ->groupBy('day')
            ->toBase()
            ->pluck('total', 'day');

        $created = $count('created_at');
        $delivered = $count('delivered_at');
        $returned = $count('returned_at');

        $days = collect();

        for ($day = $period->from->copy(); $day->lte($period->to); $day->addDay()) {
            $key = $day->toDateString();

            $days->push([
                'day'       => $key,
                'label'     => $day->format('m-d'),
                'created'   => (int) ($created[$key] ?? 0),
                'delivered' => (int) ($delivered[$key] ?? 0),
                'returned'  => (int) ($returned[$key] ?? 0),
            ]);
        }

        return view('tenant.reports.daily', ['period' => $period, 'days' => $days]);
    }

    /** ٦ — أرباح الشحنات: ما دخل من أجور وما خرج عمولات. */
    public function profit(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $closed = [
            ShipmentStatus::Delivered->value,
            ShipmentStatus::PartiallyDelivered->value,
            ShipmentStatus::Returned->value,
        ];

        $rows = Shipment::query()
            ->visibleTo($request->user())
            ->whereBetween('status_changed_at', [$from, $to])
            ->whereIn('status', $closed)
            ->selectRaw(SqlDate::month('status_changed_at')." as month,
                count(*) as total,
                sum(case when status = ? then return_fee else total_fees end) as revenue,
                sum(courier_commission) as commission", [ShipmentStatus::Returned->value])
            ->groupBy('month')
            ->orderBy('month')
            ->toBase()
            ->get()
            ->map(fn ($row) => (object) [
                'month'      => $row->month,
                'total'      => (int) $row->total,
                'revenue'    => (int) $row->revenue,
                'commission' => (int) $row->commission,
                'net'        => (int) $row->revenue - (int) $row->commission,
            ]);

        return view('tenant.reports.profit', [
            'period' => $period,
            'rows'   => $rows,
            'totals' => (object) [
                'total'      => $rows->sum('total'),
                'revenue'    => $rows->sum('revenue'),
                'commission' => $rows->sum('commission'),
                'net'        => $rows->sum('net'),
            ],
        ]);
    }


    /**
     * ٧ — عملاء منقطعون.
     *
     * التاجر لا يُعلن رحيله: يتوقّف عن الإرسال فحسب. وحين يُلاحَظ بعد
     * ثلاثة أشهر يكون قد استقرّ عند منافس. القائمة هنا مكالمةٌ لا تقرير.
     *
     * والمسار عكسيّ عن قصد: نجمع مَن أرسل داخل المدّة أولاً — وهذا مسحٌ
     * لأيام المدّة وحدها على sh_created_idx — ثم المنقطع هو الباقي. أما
     * MAX(created_at) لكل تاجر فيمسح تاريخ الشركة كلّه بلا فهرس يخدمه.
     */
    public function dormant(Request $request): View
    {
        $days = min(365, max(7, (int) $request->integer('days', 30)));
        $since = now()->subDays($days);

        $activeIds = Shipment::query()
            ->whereFromDate('created_at', $since)
            ->distinct()
            ->toBase()
            ->pluck('merchant_id')
            ->all();

        $merchants = Merchant::query()
            ->where('status', 'active')
            ->when($activeIds, fn ($q) => $q->whereNotIn('id', $activeIds))
            ->orderBy('business_name')
            ->get(['id', 'business_name', 'code', 'phone', 'balance', 'branch_id', 'created_at']);

        /*
        | آخر شحنة وإجماليّها للمنقطعين وحدهم — عشرات الصفوف لا آلافها،
        | فالاستعلام الثاني أرخص من ضمّ (join) يشمل التجّار النشطين كلّهم.
        */
        $history = $merchants->isEmpty() ? collect() : Shipment::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->selectRaw('merchant_id, count(*) as total, max(created_at) as last_at')
            ->groupBy('merchant_id')
            ->toBase()
            ->get()
            ->keyBy('merchant_id');

        $rows = $merchants->map(function (Merchant $merchant) use ($history) {
            $past = $history->get($merchant->id);

            return (object) [
                'id'       => $merchant->id,
                'name'     => $merchant->business_name,
                'code'     => $merchant->code,
                'phone'    => $merchant->phone,
                'balance'  => $merchant->balance,
                'total'    => (int) ($past->total ?? 0),
                'last_at'  => isset($past->last_at) ? Carbon::parse($past->last_at) : null,
                'joined'   => $merchant->created_at,
            ];
        })->sortBy([
            // مَن أرسل يوماً ثم توقّف أَولى بالمكالمة ممّن لم يبدأ أصلاً
            fn ($a, $b) => ($b->total > 0) <=> ($a->total > 0),
            fn ($a, $b) => ($a->last_at?->timestamp ?? 0) <=> ($b->last_at?->timestamp ?? 0),
        ])->values();

        return view('tenant.reports.dormant', ['rows' => $rows, 'days' => $days]);
    }

    /**
     * ٨ — أرصدة مدينة.
     *
     * الرصيد السالب يعني أن التاجر مدينٌ لنا: أجورُ رواجع تراكمت بلا
     * تسليمات تقابلها. وهو يكبر بصمت لأن أحداً لا يفتح كشفاً وهو سالب.
     * وبإزائه نقدٌ عند المندوبين لم يُسلَّم — الدَّين نفسه من الجهة الأخرى.
     */
    public function debtors(Request $request): View
    {
        $merchants = Merchant::query()
            ->where('balance', '<', 0)
            ->orderBy('balance')
            ->get(['id', 'business_name', 'code', 'phone', 'balance', 'deposit_balance', 'status']);

        $couriers = Courier::query()
            ->where('cash_in_hand', '>', 0)
            ->orderByDesc('cash_in_hand')
            ->get(['id', 'name', 'phone', 'cash_in_hand', 'cash_limit', 'commission_balance', 'status']);

        return view('tenant.reports.debtors', [
            'merchants' => $merchants,
            'couriers'  => $couriers,
            'totals'    => (object) [
                // الوديعة تُغطّي جزءاً من المدين: المكشوف هو ما بعدها
                'owed'     => $merchants->sum(fn ($m) => max(0, -$m->balance - $m->deposit_balance)),
                'deposits' => $merchants->sum('deposit_balance'),
                'cash'     => $couriers->sum('cash_in_hand'),
                'over'     => $couriers->filter(fn ($c) => $c->cash_limit > 0 && $c->cash_in_hand > $c->cash_limit)->count(),
            ],
        ]);
    }

    /**
     * ٩ — تتبّع التغييرات.
     *
     * «مَن غيّر هذه الشحنة ومتى» — سؤالٌ يُسأل عند كل خلاف، وكان جوابه
     * يحتاج فتح كل شحنة على حدة. والسجلّ مكتوبٌ منذ اليوم الأول في
     * shipment_events (إضافة فقط، لا تعديل) — ما كان ينقص شاشةٌ تقرؤه.
     *
     * وأحداث النظام مخفيّة افتراضاً لا مطويّة: تسويةٌ واحدة تكتب ٢٩ ألف
     * حدثٍ في ثانية، فتدفن الأربعمئة تغيير التي صنعها بشرٌ وعنها السؤال.
     */
    public function changes(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();
        $actor = (string) $request->query('actor', 'humans');
        $number = trim((string) $request->query('number'));

        $events = ShipmentEvent::query()
            ->with(['shipment:id,number,merchant_id', 'shipment.merchant:id,business_name'])
            ->whereBetween('created_at', [$from, $to])
            ->when($request->query('type'), fn ($q, $type) => $q->where('event_type', $type))
            ->when($actor === 'humans', fn ($q) => $q->where('actor_type', '!=', 'system'))
            ->when(isset(ShipmentEvent::ACTORS[$actor]), fn ($q) => $q->where('actor_type', $actor))
            ->when($number !== '', fn ($q) => $q->whereIn(
                'shipment_id',
                Shipment::where('number', $number)->orWhere('barcode', $number)->toBase()->pluck('id')
            ))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.reports.changes', [
            'period' => $period,
            'events' => $events,
            'actor'  => $actor,
            'number' => $number,
            'types'  => ShipmentEvent::TYPES,
            'actors' => ShipmentEvent::ACTORS,
        ]);
    }

    /**
     * ١٠ — مال الرواجع.
     *
     * الراجع يُكلّف مرّتين ويُحصَّل مرّة: عمولةٌ للمندوب ذهاباً وإياباً،
     * وأجرةٌ على التاجر لا تُقيَّد إلّا عند تسليمه. فالسؤال ثلاثة:
     * ماذا أكسبنا الراجع بعد عمولته؟ ومَن مِن التجّار يُكلّفنا أكثر؟
     * وكم أجرةً معلّقة على رواجع لم تُسلَّم بعد — مالٌ لا يُقيَّد وكلّ
     * يوم يمرّ عليه يجعل المطالبة به أصعب؟
     */
    public function returnsMoney(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        // مؤهَّلة: الاستعلام يُضمّ إليه التجّار، وفيهم status أيضاً
        $returned = fn () => Shipment::query()
            ->visibleTo($request->user())
            ->where('shipments.status', ShipmentStatus::Returned->value)
            ->whereBetween('shipments.returned_at', [$from, $to]);

        $totals = $returned()
            ->selectRaw('count(*) as total,
                         coalesce(sum(shipments.return_fee), 0) as fees,
                         coalesce(sum(shipments.courier_commission), 0) as commission,
                         sum(case when shipments.return_fee = 0 then 1 else 0 end) as free')
            ->toBase()
            ->first();

        /*
        | نسبة الرجوع من المُقفَل في المدّة لا من الإجمالي: الشحنة المفتوحة
        | لم يُعرف مصيرها بعد. والمُقفَل يُقرأ بتاريخ إقفاله — تسليماً أو
        | رجوعاً — لا بتاريخ إنشائه.
        */
        $closed = Shipment::query()
            ->visibleTo($request->user())
            ->where(fn ($q) => $q
                ->where(fn ($d) => $d->whereIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value])
                    ->whereBetween('delivered_at', [$from, $to]))
                ->orWhere(fn ($r) => $r->where('status', ShipmentStatus::Returned->value)
                    ->whereBetween('returned_at', [$from, $to])))
            ->selectRaw('merchant_id, count(*) as closed')
            ->groupBy('merchant_id')
            ->toBase()
            ->pluck('closed', 'merchant_id');

        $merchants = $returned()
            ->join('merchants', 'merchants.id', '=', 'shipments.merchant_id')
            ->selectRaw('shipments.merchant_id, merchants.business_name as name,
                         count(*) as returned,
                         coalesce(sum(shipments.return_fee), 0) as fees,
                         coalesce(sum(shipments.courier_commission), 0) as commission')
            ->groupBy('shipments.merchant_id', 'merchants.business_name')
            ->orderByDesc('returned')
            ->limit(50)
            ->toBase()
            ->get()
            ->map(function ($row) use ($closed) {
                $all = (int) ($closed[$row->merchant_id] ?? $row->returned);

                return (object) [
                    'id'         => (int) $row->merchant_id,
                    'name'       => $row->name,
                    'returned'   => (int) $row->returned,
                    'rate'       => $all > 0 ? (int) round($row->returned / $all * 100) : 0,
                    'fees'       => (int) $row->fees,
                    'commission' => (int) $row->commission,
                    'net'        => (int) $row->fees - (int) $row->commission,
                ];
            });

        // المعلّق الآن، أيّاً كانت المدّة: رواجع لم تُسلَّم فأجرتها لم تُقيَّد
        $pending = Shipment::query()
            ->visibleTo($request->user())
            ->where('status', ShipmentStatus::Returning->value)
            ->selectRaw('count(*) as total,
                         coalesce(sum(return_fee), 0) as fees,
                         sum(case when return_received_at is null then 1 else 0 end) as with_courier,
                         sum(case when return_received_at is not null and current_bag_id is null then 1 else 0 end) as on_shelf,
                         sum(case when current_bag_id is not null then 1 else 0 end) as in_bag,
                         min(status_changed_at) as oldest')
            ->toBase()
            ->first();

        return view('tenant.reports.returns_money', [
            'period'    => $period,
            'totals'    => (object) [
                'total'      => (int) $totals->total,
                'fees'       => (int) $totals->fees,
                'commission' => (int) $totals->commission,
                'net'        => (int) $totals->fees - (int) $totals->commission,
                'free'       => (int) $totals->free,
            ],
            'merchants' => $merchants,
            'pending'   => (object) [
                'total'        => (int) $pending->total,
                'fees'         => (int) $pending->fees,
                'with_courier' => (int) $pending->with_courier,
                'on_shelf'     => (int) $pending->on_shelf,
                'in_bag'       => (int) $pending->in_bag,
                'oldest'       => $pending->oldest ? Carbon::parse($pending->oldest) : null,
            ],
        ]);
    }
}

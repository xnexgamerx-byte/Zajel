<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SqlDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ستّة تقارير لا واحد وثلاثون.
 *
 * النظام المرجعي فيه ٣١ تقريراً، ومن يشغّل شركة يفتح منها ستّة. الباقي
 * تنويعات على السؤال نفسه. فالمقياس هنا: كل تقرير يُجيب سؤالاً يُتّخذ
 * بعده قرار — ومن لا قرار بعده لا يُبنى.
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
}

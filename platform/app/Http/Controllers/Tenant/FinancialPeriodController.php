<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FinancialPeriod;
use App\Models\FinancialPeriodReport;
use App\Services\Finance\PeriodClose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * «إقفال الفترات» (docs/plan/68 §٩): قائمة الفترات، ومراجعة ما قبل الإقفال بأرقامه وفحوصه،
 * والإعداد باستثناءاتٍ موثّقة، والاعتماد، والتقرير بنسخه، والتعديلات بعد الإقفال.
 */
class FinancialPeriodController extends Controller
{
    public function __construct(protected PeriodClose $close) {}

    public function index(): View
    {
        [$from, $to] = $this->close->suggest();

        return view('tenant.finance.periods', [
            'periods' => FinancialPeriod::query()->with(['preparedBy:id,name', 'approvedBy:id,name'])->orderByDesc('to_date')->get(),
            'from'    => $from,
            'to'      => $to,
        ]);
    }

    /** مراجعة ما قبل الإقفال: أرقام الفترة وفحوصها، ونموذج الإعداد */
    public function review(Request $request): View
    {
        [$from, $to] = $this->close->suggest();
        $from = $request->date('from') ?? $from;
        $to = $request->date('to') ?? $to;

        return view('tenant.finance.period-review', [
            'from'    => $from->startOfDay(),
            'to'      => $to->startOfDay(),
            'figures' => $this->close->figures($from, $to),
            'checks'  => $this->close->checks($from, $to),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from'         => ['required', 'date'],
            'to'           => ['required', 'date'],
            'exceptions'   => ['nullable', 'array'],
            'exceptions.*' => ['nullable', 'string', 'max:255'],
        ], [], ['from' => 'من', 'to' => 'إلى']);

        $period = $this->close->prepare(Carbon::parse($data['from'])->startOfDay(), Carbon::parse($data['to'])->startOfDay(),
            array_filter($data['exceptions'] ?? []), $request->user());

        return redirect()->route('finance.periods.show', $period)
            ->with('success', "أُعِدّ إقفال الفترة {$period->number}: يعتمده المخوَّل فتُقفَل.");
    }

    public function show(FinancialPeriod $period): View
    {
        $period->load(['preparedBy:id,name', 'approvedBy:id,name', 'reports.issuedBy:id,name']);

        return view('tenant.finance.period', [
            'period'      => $period,
            'adjustments' => $period->isClosed() ? $this->close->adjustments($period) : null,
        ]);
    }

    public function approve(Request $request, FinancialPeriod $period): RedirectResponse
    {
        $this->close->approve($period, $request->user());

        return back()->with('success', "أُقفلت الفترة {$period->number}: لا يُكتب قيدٌ بتاريخها، وما يمسّها يُكتب في الفترة المفتوحة.");
    }

    public function cancel(Request $request, FinancialPeriod $period): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'السبب']);
        $this->close->cancel($period, $data['reason'], $request->user());

        return redirect()->route('finance.periods.index')->with('success', "أُلغي إعداد الفترة {$period->number}.");
    }

    public function reissue(Request $request, FinancialPeriod $period): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'السبب']);
        $report = $this->close->reissue($period, $data['reason'], $request->user());

        return back()->with('success', "صدرت النسخة {$report->version} من تقرير الفترة {$period->number}، والنسخ قبلها كما هي.");
    }

    /** نسخة التقرير للطباعة أو الحفظ PDF */
    public function report(FinancialPeriod $period, int $version): View
    {
        $report = FinancialPeriodReport::query()->where('financial_period_id', $period->id)->where('version', $version)
            ->with('issuedBy:id,name')->firstOrFail();

        return view('tenant.finance.period-report', ['period' => $period->load('preparedBy:id,name', 'approvedBy:id,name'), 'report' => $report]);
    }
}

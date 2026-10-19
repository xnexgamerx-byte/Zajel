<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Profits\ManageCollectionBatch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\Courier;
use App\Models\Merchant;
use App\Services\Finance\ProfitDashboard;
use App\Services\Money\Profits;
use App\Services\Reports\ReportPeriod;
use App\Support\StreamingXlsx;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «العوائد» تحت «الموقف المالي»: قسم المدير للأرباح — لوحة الأرباح من دفتر القيود، ووجبات
 * التحصيل، وتقريرها، وسحب الأرباح.
 */
class ProfitsController extends Controller
{
    /** «لوحة الأرباح» (docs/plan/68 §٥ و§١٥): البطاقات التسع، والرسوم، وأفضل المناديب، ومقارنة الفروع */
    public function index(Request $request): View
    {
        return view('tenant.profits.index', $this->dashboard($request) + [
            'waiting' => (function () use ($request) {
                $waiting = ManageCollectionBatch::eligible($request->user());

                return ['count' => (clone $waiting)->count(), 'cash' => (int) (clone $waiting)->sum('net_amount')];
            })(),
            'open'    => CollectionBatch::query()->visibleTo($request->user())->whereIn('status', CollectionBatch::OPEN)->count(),
            'differences' => CollectionBatch::query()->visibleTo($request->user())->where('status', 'discrepancy')->count(),
        ]);
    }

    /** اللوحة في Excel: البطاقات بأجزائها، أو مقارنة الفروع، أو المناديب — بفلاتر الشاشة نفسها */
    public function export(Request $request): BinaryFileResponse
    {
        $data = $this->dashboard($request);
        $what = in_array($request->input('what'), ['branches', 'couriers'], true) ? $request->input('what') : 'cards';

        if ($what === 'branches') {
            $sheet = new StreamingXlsx([['الفرع', 18], ['المسلَّمة', 10, 'number'], ['الالتزام بالموعد ٪', 12, 'number'],
                ['إيراد الأجور', 14, 'number'], ['إيرادات أخرى', 12, 'number'], ['التكاليف', 14, 'number'], ['صافي الربح', 14, 'number'],
                ['عمولة داخلية له', 13, 'number'], ['عمولة داخلية عليه', 13, 'number'], ['المحصَّل نقداً', 14, 'number'],
                ['غير المحصَّل', 14, 'number'], ['عهد المناديب', 14, 'number'], ['نقد الصناديق', 14, 'number'],
                ['عجز لم يُعالَج', 13, 'number'], ['مستحقّات التجّار', 14, 'number'], ['مصروفات مستحقّة', 13, 'number']], 'مقارنة الفروع');
            foreach ($data['branches'] as $b) {
                $sheet->add([$b->name, $b->delivered, $b->on_time, $b->revenue, $b->other, $b->costs, $b->net, $b->internal_in,
                    $b->internal_out, $b->realized, $b->unrealized, $b->custody, $b->boxes, $b->open_shortage, $b->payables, $b->expenses_due]);
            }
        } elseif ($what === 'couriers') {
            $sheet = new StreamingXlsx([['المندوب', 20], ['الرمز', 10], ['المسلَّمة', 10, 'number'], ['الالتزام بالموعد ٪', 12, 'number'],
                ['إيراد شحناته', 14, 'number'], ['عمولاته', 13, 'number'], ['مساهمته', 14, 'number'], ['فرق وجباته', 12, 'number']], 'المناديب');
            foreach ($data['couriers'] as $c) {
                $sheet->add([$c->name, $c->code, $c->delivered, $c->on_time, $c->revenue, $c->commissions, $c->contribution, $c->discrepancy]);
            }
        } else {
            $sheet = new StreamingXlsx([['البطاقة', 34], ['البند', 40], ['المبلغ', 16, 'number'], ['المدّة السابقة', 16, 'number']], 'لوحة الأرباح');
            foreach ($this->rows($data) as $row) {
                $sheet->add($row);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'profits-').'.xlsx';
        $sheet->save($path);

        return response()->download($path, "profits-{$what}-".now()->format('Y-m-d-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * أرقام اللوحة بفلاترها: المدّة (اليوم، ٧ أيام، ١٥ يوماً، الشهر، أو ما تحدّده) ومقابلها
     * السابقة بطولها، والفرع والمندوب والتاجر.
     *
     * @return array<string, mixed>
     */
    protected function dashboard(Request $request): array
    {
        $user = $request->user();
        [$range, $period] = $this->range($request);
        $previous = new ReportPeriod($period->from->copy()->subDays($period->days())->startOfDay(), $period->from->copy()->subDay()->endOfDay());

        $board = new ProfitDashboard($user, [
            'branch'   => $request->integer('branch_id') ?: null,
            'courier'  => $request->integer('courier_id') ?: null,
            'merchant' => $request->integer('merchant_id') ?: null,
        ]);
        $company = ! $user->isBranchLimited();
        $available = $company ? $board->available() : null;
        $rank = array_key_exists((string) $request->input('rank'), ProfitDashboard::RANKS) ? (string) $request->input('rank') : 'contribution';
        $branches = $board->scope()['courier'] || $board->scope()['merchant'] ? collect() : $board->branches($period);

        return [
            'range'       => $range,
            'period'      => $period,
            'previous'    => $previous,
            'board'       => $board,
            'scope'       => $board->scope(),
            'initialized' => $board->initialized(),
            'a'           => $board->delivered($period) + ['prev' => $board->delivered($previous)],
            'b'           => $board->revenue($period) + ['prev' => $board->revenue($previous)],
            'c'           => $board->costs($period) + ['prev' => $board->costs($previous)],
            'd'           => $board->netProfit($period) + ['prev' => $board->netProfit($previous)],
            'e'           => $board->realized($period->from, $period->to) + ['prev' => $board->realized($previous->from, $previous->to)],
            'f'           => $board->unrealized(),
            'g'           => $company ? $board->retained($available['free']) : null,
            'h'           => $available,
            'i'           => $board->merchantPayables(),
            'series'      => $board->series($period, $previous),
            'collections' => $board->collections($period),
            'rank'        => $rank,
            'couriers'    => $board->couriers($period, $rank, $request->routeIs('profits.export') ? 1000 : 10),
            'branches'    => $branches,
            'branchList'  => $company ? Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'name']) : collect(),
            'courierList' => Courier::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'merchantList' => Merchant::query()->visibleTo($user)->orderBy('business_name')->get(['id', 'business_name']),
        ];
    }

    /** @return array{string, ReportPeriod} */
    protected function range(Request $request): array
    {
        $range = (string) $request->input('range', 'month');

        return match ($range) {
            'today' => ['today', new ReportPeriod(now()->startOfDay(), now()->endOfDay())],
            '7'     => ['7', new ReportPeriod(now()->subDays(6)->startOfDay(), now()->endOfDay())],
            '15'    => ['15', new ReportPeriod(now()->subDays(14)->startOfDay(), now()->endOfDay())],
            'custom' => ['custom', ReportPeriod::fromRequest($request)],
            default => ['month', new ReportPeriod(now()->startOfMonth(), now()->endOfDay())],
        };
    }

    /** سطور «البطاقات» في Excel: كل جزءٍ بمبلغه ومقابله السابق */
    protected function rows(array $d): array
    {
        $rows = [
            ['أ. الشحنات المسلَّمة', 'المسلَّمة', $d['a']['delivered'], $d['a']['prev']['delivered']],
            ['أ. الشحنات المسلَّمة', 'الراجع المغلق', $d['a']['returned'], $d['a']['prev']['returned']],
            ['ب. إيراد أجور التوصيل', 'أجور المسلَّم (4100)', $d['b']['delivery'], $d['b']['prev']['delivery']],
            ['ب. إيراد أجور التوصيل', 'أجور الراجع (4110)', $d['b']['returns'], $d['b']['prev']['returns']],
            ['ج. التكاليف', 'عمولات المناديب (5100)', $d['c']['commissions'], $d['c']['prev']['commissions']],
            ['ج. التكاليف', 'حصص مندوبي الاستلام (5110)', $d['c']['pickup'], $d['c']['prev']['pickup']],
            ['ج. التكاليف', 'مصروفات تشغيلية (5200)', $d['c']['expenses'], $d['c']['prev']['expenses']],
            ['ج. التكاليف', 'عجز مشطوب (5300)', $d['c']['write_offs'], $d['c']['prev']['write_offs']],
            ['د. صافي الربح المحاسبي', 'الصافي', $d['d']['total'], $d['d']['prev']['total']],
            ['هـ. الربح المحصَّل نقداً', 'أجور تحقّقت بالوجبات', $d['e']['batches'], $d['e']['prev']['batches']],
            ['هـ. الربح المحصَّل نقداً', 'أجور على التجّار دُفعت كشوفهم', $d['e']['merchants'], $d['e']['prev']['merchants']],
            ['هـ. الربح المحصَّل نقداً', 'أجور مدفوعة مقدّماً اكتُسبت', $d['e']['prepaid'], $d['e']['prev']['prepaid']],
            ['هـ. الربح المحصَّل نقداً', 'عمولات وحصص دُفعت', -$d['e']['commissions'], -$d['e']['prev']['commissions']],
            ['هـ. الربح المحصَّل نقداً', 'مصروفات دُفعت', -$d['e']['expenses'], -$d['e']['prev']['expenses']],
            ['هـ. الربح المحصَّل نقداً', 'الصافي', $d['e']['total'], $d['e']['prev']['total']],
            ['و. الأرباح غير المحصَّلة (الآن)', 'بيد المناديب', $d['f']['couriers']['total'], null],
            ['و. الأرباح غير المحصَّلة (الآن)', 'في كشوفٍ لم تدخل وجبةً معتمدة', $d['f']['batches']['total'], null],
            ['و. الأرباح غير المحصَّلة (الآن)', 'على التجّار', $d['f']['merchants']['total'], null],
            ['و. الأرباح غير المحصَّلة (الآن)', 'عجز على المناديب', $d['f']['shortages']['total'], null],
        ];

        if ($d['g']) {
            $rows[] = ['ز. الأرباح المحتجزة (الآن)', 'المحتجزة', $d['g']['total'], null];
            $rows[] = ['ز. الأرباح المحتجزة (الآن)', 'مدعومة بنقد', $d['g']['backed'], null];
            $rows[] = ['ح. المتاح للسحب (الآن)', 'النقد الحرّ', $d['h']['free'], null];
            $rows[] = ['ح. المتاح للسحب (الآن)', 'المتاح', $d['h']['total'], null];
        }

        $rows[] = ['ط. مستحقّات التجّار (الآن)', 'مستحقٌّ الآن', $d['i']['now'], null];
        $rows[] = ['ط. مستحقّات التجّار (الآن)', 'منه متأخّر', $d['i']['late'], null];
        $rows[] = ['ط. مستحقّات التجّار (الآن)', 'مستقبلي (عند المناديب)', $d['i']['future'], null];

        return $rows;
    }

    /** تقرير الأرباح عن أي مدّة، بالفرع أو المندوب */
    public function report(Request $request, Profits $profits): View
    {
        $user = $request->user();
        $period = ReportPeriod::fromRequest($request);
        $branchId = $user->isBranchLimited() ? null : ($request->integer('branch_id') ?: null);
        $courierId = $request->integer('courier_id') ?: null;

        return view('tenant.profits.report', [
            'period'   => $period,
            'report'   => $profits->report($user, $period, $branchId, $courierId, $request->boolean('drafts')),
            'branches' => $user->isBranchLimited() ? collect() : Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'couriers' => Courier::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'branchId' => $branchId,
            'courierId' => $courierId,
        ]);
    }
}

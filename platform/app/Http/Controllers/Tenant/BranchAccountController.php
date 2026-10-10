<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Cash\ManageDeposit;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Merchant;
use App\Models\MerchantDeposit;
use App\Models\Shipment;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * محاسبة الفروع، وديونها، وتأمينات التجّار.
 *
 * الشركة ذات الفرعين تعرف مجموعها ولا تعرف أيّهما يكسب. والفرع الذي
 * يُسلّم شحنة يخصّ تاجر فرعٍ آخر يجمع نقداً ليس له — فيصير على الفرع
 * دَينٌ لا يظهر في أي شاشة حتى يُجرَد آخر السنة.
 */
class BranchAccountController extends Controller
{
    public function __construct(protected ManageDeposit $deposits) {}

    /** محاسبة الفروع: ماذا كسب كل فرع في المدّة. */
    public function index(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $closed = [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value,
                   ShipmentStatus::Returned->value];

        // موظّف الفرع يرى صفّ فرعه وحده، والفرع الرئيسي الفروعَ كلّها
        $mine = $request->user()->isBranchLimited() ? $request->user()->branch_id : null;

        $closedIn = fn () => DB::table('shipments')
            ->where('company_id', $request->user()->company_id)
            ->whereNull('deleted_at')
            ->whereIn('status', $closed)
            ->whereBetween('status_changed_at', [$from, $to]);

        /*
        | فرع الشحنة (فرع تاجرها) يكسب أجورها ويدفع كلفتها: عمولة المندوب — أو عمولةَ الفرع
        | الذي وصّلها إن كان له عمولة، وهو يدفع منها عمولة مندوبه (docs/plan/51).
        */
        $revenue = $closedIn()
            ->when($mine, fn ($q) => $q->where('branch_id', $mine))
            // باقي الواصل الجزئي بأجوره كاملةً لا بأجرة رجوعه (Shipment::sqlRevenue)
            ->selectRaw('branch_id, count(*) as shipments,
                sum('.Shipment::sqlRevenue().') as revenue,
                sum(case when branch_commission > 0 then 0 else courier_commission end) as commission,
                sum(branch_commission) as branch_paid')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        // والفرع الموصِّل: له عمولته عن كلّ طلب، وعليه عمولة مندوبه — والفرق ربحه
        $earned = $closedIn()
            ->when($mine, fn ($q) => $q->where('delivery_branch_id', $mine))
            ->where('branch_commission', '>', 0)
            ->selectRaw('delivery_branch_id, count(*) as shipments,
                sum(branch_commission) as earned,
                sum(courier_commission) as couriers')
            ->groupBy('delivery_branch_id')
            ->get()
            ->keyBy('delivery_branch_id');

        $expenses = DB::table('expenses')
            ->where('company_id', $request->user()->company_id)
            ->when($mine, fn ($q) => $q->where('branch_id', $mine))
            ->where('status', '!=', 'cancelled')
            ->whereBetween('spent_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('branch_id, sum(amount) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        $cash = CashBox::active()->visibleTo($request->user())->selectRaw('branch_id, sum(balance) as total')
            ->groupBy('branch_id')->toBase()->pluck('total', 'branch_id');

        return view('tenant.branch_accounts.index', [
            'period'   => $period,
            'branches' => Branch::when($mine, fn ($q) => $q->whereKey($mine))->orderByDesc('is_main')->orderBy('name')->get(),
            'revenue'  => $revenue,
            'earned'   => $earned,
            'expenses' => $expenses,
            'cash'     => $cash,
            'debts'    => $this->interBranchDebts($request, $from, $to),
        ]);
    }

    /**
     * ديون بين الفروع.
     *
     * فرعٌ يوصّل شحنةً يملكها تاجرُ فرعٍ آخر: النقد عنده والمستحقّ على
     * الفرع الآخر. الرقم يظهر هنا يومياً بدل أن يُكتشف في جرد السنة.
     */
    protected function interBranchDebts(Request $request, string $from, string $to)
    {
        return DB::table('shipments')
            ->join('merchants', 'merchants.id', '=', 'shipments.merchant_id')
            ->where('shipments.company_id', $request->user()->company_id)
            ->whereNull('shipments.deleted_at')
            // ما حُصِّل: سُلِّمت كلّها أو بعضها — كما في «ديون الفروع» (BranchRemittanceController)
            ->whereNotNull('shipments.delivered_at')
            ->whereBetween('shipments.status_changed_at', [$from, $to])
            ->whereNotNull('merchants.branch_id')
            // الجامع فرعُ المندوب الموصِّل، ويحتفظ بعمولته من النقد (docs/plan/51)
            ->whereRaw(BranchRemittanceController::COLLECTOR.' is not null')
            ->whereRaw(BranchRemittanceController::COLLECTOR.' != merchants.branch_id')
            // ما بين فرعه وغيره وحده، إن كان مقيَّداً بفرع
            ->when($request->user()->isBranchLimited(), fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw(BranchRemittanceController::COLLECTOR.' = ?', [$request->user()->branch_id])
                ->orWhere('merchants.branch_id', $request->user()->branch_id)))
            ->selectRaw(BranchRemittanceController::COLLECTOR.' as collector, merchants.branch_id as owner,
                         count(*) as shipments, sum(shipments.collected_amount) as collected,
                         sum(shipments.branch_commission) as commission,
                         sum(shipments.collected_amount - shipments.branch_commission) as amount')
            ->groupByRaw(BranchRemittanceController::COLLECTOR.', merchants.branch_id')
            ->orderByDesc('amount')
            ->get();
    }

    /**
     * كشف حساب الفرع: كل دينارٍ دخل صناديقه أو خرج منها، بالترتيب،
     * برصيدٍ جارٍ من الافتتاحيّ إلى الختاميّ.
     *
     * محاسبة الفروع تقول أيّ فرعٍ يكسب؛ هذا يقول أين ذهب نقده. والكشف
     * لا يُقسَّم صفحات: كشفٌ ناقصٌ يُطبع ويُوقَّع كذبةٌ موقَّعة.
     */
    public function statement(Request $request): View
    {
        return view('tenant.branch_accounts.statement', $this->statementData($request));
    }

    public function statementPrint(Request $request): View
    {
        return view('tenant.branch_accounts.statement_print', $this->statementData($request));
    }

    protected function statementData(Request $request): array
    {
        $user = $request->user();
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $branches = Branch::withTrashed()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'is_main', 'deleted_at']);

        // المقيَّد بفرع يرى فرعه وحده، مهما طلب في الرابط
        $branch = $user->isBranchLimited()
            ? $branches->firstWhere('id', $user->branch_id)
            : ($branches->firstWhere('id', $request->integer('branch_id'))
                ?? $branches->firstWhere('id', $user->branch_id)
                ?? $branches->first());

        // الموقوف والمحذوف من الصناديق باقٍ في التاريخ
        $boxes = $branch
            ? CashBox::where('branch_id', $branch->id)->orderBy('name')->get(['id', 'name', 'code', 'balance', 'is_active'])
            : collect();
        $boxIds = $boxes->pluck('id')->all() ?: [0];

        /*
        | الافتتاحيّ: رصيد كل صندوق بعد آخر حركةٍ قبل المدّة.
        |
        | الدفتر إضافةٌ فقط والمعرّفات تتصاعد مع الزمن، فأكبر معرّفٍ قبل
        | البداية هو آخر حركة — ورصيدها بعدها محفوظ عليها فلا يُعاد حسابه.
        */
        $lastBefore = CashMovement::whereIn('cash_box_id', $boxIds)
            ->where('created_at', '<', $from)
            ->selectRaw('max(id) as id')
            ->groupBy('cash_box_id')
            ->toBase()
            ->pluck('id');
        $opening = (int) CashMovement::whereIn('id', $lastBefore->all() ?: [0])->sum('balance_after');

        $movements = CashMovement::with(['user:id,name', 'cashBox:id,name'])
            ->whereIn('cash_box_id', $boxIds)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get();

        $running = $opening;
        $summary = [];

        foreach ($movements as $movement) {
            $signed = $movement->direction === 'in' ? (int) $movement->amount : -(int) $movement->amount;
            $running += $signed;
            $movement->setAttribute('running', $running);

            // مناقلةٌ بين صندوقين في الفرع نفسه لا تُغيّر نقد الفرع: تُعرَض ولا تُجمَع
            $internal = in_array($movement->category, ['transfer_in', 'transfer_out'], true)
                && in_array((int) $movement->counterpart_box_id, $boxIds, true);
            $movement->setAttribute('internal', $internal);

            if (! $internal) {
                $key = $movement->category;
                $summary[$key] ??= ['label' => $movement->categoryLabel(), 'in' => 0, 'out' => 0, 'count' => 0];
                $summary[$key][$movement->direction] += (int) $movement->amount;
                $summary[$key]['count']++;
            }
        }

        $in = (int) $movements->where('direction', 'in')->reject->internal->sum('amount');
        $out = (int) $movements->where('direction', 'out')->reject->internal->sum('amount');

        return [
            'period'    => $period,
            'branches'  => $user->isBranchLimited() ? $branches->where('id', $user->branch_id) : $branches,
            'branch'    => $branch,
            'boxes'     => $boxes,
            'movements' => $movements,
            'summary'   => collect($summary)->sortByDesc(fn ($row) => $row['in'] + $row['out']),
            'totals'    => (object) [
                'opening' => $opening,
                'in'      => $in,
                'out'     => $out,
                'closing' => $running,
            ],
            /*
            | كشفٌ ينتهي اليوم يُقابَل بما في الصناديق الآن: الختاميّ يجب
            | أن يساوي مجموع أرصدتها. والفرق يعني حركةً لم تمرّ بالدفتر.
            */
            'check'     => $period->to->isToday() || $period->to->isFuture()
                ? (object) ['held' => (int) $boxes->sum('balance'), 'drift' => (int) $boxes->sum('balance') - $running]
                : null,
        ];
    }

    /** تأمينات التجّار. */
    public function deposits(Request $request): View
    {
        return view('tenant.branch_accounts.deposits', [
            'merchants' => Merchant::where('status', '!=', 'closed')->visibleTo($request->user())
                ->orderByDesc('deposit_balance')->orderBy('business_name')
                ->get(['id', 'business_name', 'code', 'deposit_balance', 'balance']),
            'movements' => MerchantDeposit::visibleTo($request->user())->with(['merchant:id,business_name', 'user:id,name'])
                ->latest('id')->limit(30)->get(),
            'boxes'     => CashBox::active()->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'balance']),
            'held'      => (int) Merchant::visibleTo($request->user())->sum('deposit_balance'),
        ]);
    }

    public function storeDeposit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'kind'        => ['required', 'in:deposit,refund,forfeit'],
            'amount'      => ['required', 'integer', 'min:1'],
            'cash_box_id' => ['nullable', 'integer'],
            'reason'      => ['required_if:kind,forfeit', 'nullable', 'string', 'max:255'],
        ], [], ['merchant_id' => 'التاجر', 'amount' => 'المبلغ', 'reason' => 'السبب']);

        $merchant = Merchant::visibleTo($request->user())->find($data['merchant_id']);

        if (! $merchant) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $box = empty($data['cash_box_id']) ? null : CashBox::active()->visibleTo($request->user())->find($data['cash_box_id']);

        $row = match ($data['kind']) {
            'deposit' => $this->deposits->deposit($merchant, $data['amount'], $request->user(), $box, $data['reason'] ?? null),
            'refund'  => $this->deposits->refund($merchant, $data['amount'], $request->user(), $box, $data['reason'] ?? null),
            'forfeit' => $this->deposits->forfeit($merchant, $data['amount'], $data['reason'], $request->user()),
        };

        return back()->with('success', $row->kindLabel().' — '.$merchant->business_name
            .'. الرصيد الآن '.number_format($row->balance_after).' دينار.');
    }
}

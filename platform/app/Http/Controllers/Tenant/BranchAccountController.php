<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Cash\ManageDeposit;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Merchant;
use App\Models\MerchantDeposit;
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

        $revenue = DB::table('shipments')
            ->where('company_id', $request->user()->company_id)
            ->whereNull('deleted_at')
            ->whereIn('status', $closed)
            ->whereBetween('status_changed_at', [$from, $to])
            ->selectRaw('branch_id, count(*) as shipments,
                sum(case when status = ? then return_fee else total_fees end) as revenue,
                sum(courier_commission) as commission', [ShipmentStatus::Returned->value])
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $expenses = DB::table('expenses')
            ->where('company_id', $request->user()->company_id)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('spent_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('branch_id, sum(amount) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        $cash = CashBox::active()->selectRaw('branch_id, sum(balance) as total')
            ->groupBy('branch_id')->toBase()->pluck('total', 'branch_id');

        return view('tenant.branch_accounts.index', [
            'period'   => $period,
            'branches' => Branch::orderByDesc('is_main')->orderBy('name')->get(),
            'revenue'  => $revenue,
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
            ->where('shipments.status', ShipmentStatus::Delivered->value)
            ->whereBetween('shipments.status_changed_at', [$from, $to])
            ->whereNotNull('shipments.branch_id')
            ->whereNotNull('merchants.branch_id')
            ->whereColumn('shipments.branch_id', '!=', 'merchants.branch_id')
            ->selectRaw('shipments.branch_id as collector, merchants.branch_id as owner,
                         count(*) as shipments, sum(shipments.collected_amount) as amount')
            ->groupBy('shipments.branch_id', 'merchants.branch_id')
            ->orderByDesc('amount')
            ->get();
    }

    /** تأمينات التجّار. */
    public function deposits(Request $request): View
    {
        return view('tenant.branch_accounts.deposits', [
            'merchants' => Merchant::where('status', '!=', 'closed')
                ->orderByDesc('deposit_balance')->orderBy('business_name')
                ->get(['id', 'business_name', 'code', 'deposit_balance', 'balance']),
            'movements' => MerchantDeposit::with(['merchant:id,business_name', 'user:id,name'])
                ->latest('id')->limit(30)->get(),
            'boxes'     => CashBox::active()->orderBy('name')->get(['id', 'name', 'balance']),
            'held'      => (int) Merchant::sum('deposit_balance'),
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

        $merchant = Merchant::find($data['merchant_id']);

        if (! $merchant) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $box = empty($data['cash_box_id']) ? null : CashBox::active()->find($data['cash_box_id']);

        $row = match ($data['kind']) {
            'deposit' => $this->deposits->deposit($merchant, $data['amount'], $request->user(), $box, $data['reason'] ?? null),
            'refund'  => $this->deposits->refund($merchant, $data['amount'], $request->user(), $box, $data['reason'] ?? null),
            'forfeit' => $this->deposits->forfeit($merchant, $data['amount'], $data['reason'], $request->user()),
        };

        return back()->with('success', $row->kindLabel().' — '.$merchant->business_name
            .'. الرصيد الآن '.number_format($row->balance_after).' دينار.');
    }
}

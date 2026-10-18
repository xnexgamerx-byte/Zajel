<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Profits\ManageCollectionBatch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\Courier;
use App\Models\ProfitWithdrawal;
use App\Services\Money\FinancialPosition;
use App\Services\Money\Profits;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «العوائد» تحت «الموقف المالي»: قسم المدير للأرباح — ما حُسب من الوجبات، وما تحقّق نقداً،
 * وما سُحب، وما ينتظر الاعتماد أو فيه فروق، ومستحقّات التجّار، وأداء كل فرعٍ ومندوب.
 */
class ProfitsController extends Controller
{
    public function index(Request $request, Profits $profits, FinancialPosition $position): View
    {
        $user = $request->user();
        $month = new ReportPeriod(now()->startOfMonth(), now()->endOfDay());
        $waiting = ManageCollectionBatch::eligible($user);

        return view('tenant.profits.index', [
            'summary'     => $profits->summary($user),
            'payables'    => $position->now($user)['figures']['merchant_payables'],
            'waiting'     => ['count' => (clone $waiting)->count(), 'cash' => (int) (clone $waiting)->sum('net_amount')],
            'month'       => $profits->report($user, $month),
            'period'      => $month,
            'recent'      => CollectionBatch::visibleTo($user)->with('branch:id,name')->latest('id')->limit(6)->get(),
            'withdrawals' => $user->isBranchLimited() ? collect() : ProfitWithdrawal::latest('id')->limit(5)->get(),
        ]);
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

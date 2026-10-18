<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Profits\RecordProfitWithdrawal;
use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\ProfitWithdrawal;
use App\Services\Money\Profits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** «سحب الأرباح»: عمليةٌ منفصلة عن تحصيل المناديب، بسجلّ كل سحب */
class ProfitWithdrawalController extends Controller
{
    public function index(Request $request, Profits $profits): View
    {
        return view('tenant.profits.withdrawals', [
            'summary'     => $profits->summary($request->user()),
            'withdrawals' => ProfitWithdrawal::with(['cashBox:id,name', 'createdBy:id,name', 'cancelledBy:id,name'])
                ->latest('withdrawn_on')->latest('id')
                ->paginate(config('zajel.per_page'))->withQueryString(),
            'boxes'       => CashBox::active()->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'balance']),
        ]);
    }

    public function store(Request $request, RecordProfitWithdrawal $withdraw): RedirectResponse
    {
        $data = $request->validate([
            'amount'       => ['required', 'integer', 'min:1', 'max:100000000000'],
            'withdrawn_on' => ['required', 'date', 'before_or_equal:today'],
            'withdrawn_by' => ['nullable', 'string', 'max:120'],
            'cash_box_id'  => ['nullable', 'integer'],
            'note'         => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'المبلغ', 'withdrawn_on' => 'تاريخ السحب', 'withdrawn_by' => 'المستلم', 'cash_box_id' => 'الصندوق']);

        $box = filled($data['cash_box_id'] ?? null)
            ? CashBox::active()->visibleTo($request->user())->findOrFail($data['cash_box_id'])
            : null;

        $withdrawal = $withdraw->handle((int) $data['amount'], Carbon::parse($data['withdrawn_on']), $box,
            $data['withdrawn_by'] ?? null, $data['note'] ?? null, $request->user());

        return back()->with('success', "سُجِّل السحب {$withdrawal->number} بمبلغ ".number_format((int) $withdrawal->amount).' د.ع'
            .($box ? " من «{$box->name}»." : '.'));
    }

    public function cancel(Request $request, ProfitWithdrawal $withdrawal, RecordProfitWithdrawal $withdraw): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'سبب الإلغاء']);

        $withdraw->cancel($withdrawal, $data['reason'], $request->user());

        return back()->with('success', "أُلغي السحب {$withdrawal->number}".($withdrawal->cash_box_id ? '، وعاد مبلغه إلى الصندوق.' : '.'));
    }
}

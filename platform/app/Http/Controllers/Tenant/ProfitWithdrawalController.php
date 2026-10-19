<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Profits\RecordProfitWithdrawal;
use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\ProfitWithdrawal;
use App\Services\Finance\ProfitDashboard;
use App\Support\StreamingXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «سحب الأرباح» (docs/plan/68 §١٠): ما رُبح وما حُصِّل وما احتُجز، والمتاح بتفصيل معادلته،
 * والسحب بمساره — طلبٌ ← اعتماد ← دفع — وسند صرفه وسجلّه.
 */
class ProfitWithdrawalController extends Controller
{
    public function index(Request $request, RecordProfitWithdrawal $withdraw): View
    {
        $board = new ProfitDashboard;
        $available = $board->available();
        $committed = $withdraw->committed();

        return view('tenant.profits.withdrawals', [
            'h'           => $available,
            'g'           => $board->retained($available['free']),
            'committed'   => $committed,
            'free'        => max(0, $available['total'] - $committed),
            'approvalOver' => RecordProfitWithdrawal::approvalOver(),
            'withdrawals' => $this->filtered($request)
                ->with(['cashBox:id,name,type,bank_name,account_label', 'createdBy:id,name', 'approvedBy:id,name', 'paidBy:id,name', 'cancelledBy:id,name'])
                ->latest('id')->paginate(config('zajel.per_page'))->withQueryString(),
            'boxes'       => CashBox::active()->visibleTo($request->user())->whereNull('user_id')->orderBy('name')
                ->get(['id', 'name', 'type', 'bank_name', 'account_label', 'balance']),
            'key'         => (string) Str::uuid(),
        ]);
    }

    /** طلب سحب — ولمن يعتمد ويختار «ادفع الآن»: طلبٌ واعتمادٌ ودفعٌ معاً */
    public function store(Request $request, RecordProfitWithdrawal $withdraw): RedirectResponse
    {
        $data = $request->validate([
            'amount'          => ['required', 'integer', 'min:1', 'max:100000000000'],
            'withdrawn_on'    => ['required', 'date', 'before_or_equal:today'],
            'withdrawn_by'    => ['required', 'string', 'max:120'],
            'cash_box_id'     => ['required', 'integer'],
            'note'            => ['required', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'pay_now'         => ['nullable', 'boolean'],
        ], [], ['amount' => 'المبلغ', 'withdrawn_on' => 'تاريخ السحب', 'withdrawn_by' => 'المستفيد', 'cash_box_id' => 'الصندوق أو الحساب', 'note' => 'سبب السحب']);

        $user = $request->user();
        $box = CashBox::active()->visibleTo($user)->whereNull('user_id')->findOrFail($data['cash_box_id']);
        $args = [(int) $data['amount'], Carbon::parse($data['withdrawn_on']), $box, $data['withdrawn_by'], $data['note'], $user, $data['idempotency_key'] ?? null];

        $withdrawal = $request->boolean('pay_now') && $user->can('withdrawals.approve')
            ? $withdraw->handle(...$args)
            : $withdraw->request(...$args);

        return redirect()->route('profits.withdrawals.index')->with('success', match ($withdrawal->status) {
            'paid'  => "دُفع السحب {$withdrawal->number} بمبلغ ".number_format((int) $withdrawal->amount)." د.ع من «{$box->name}».",
            default => "طُلب السحب {$withdrawal->number} بمبلغ ".number_format((int) $withdrawal->amount).' د.ع: ينتظر الاعتماد ثم الدفع.',
        });
    }

    public function approve(Request $request, ProfitWithdrawal $withdrawal, RecordProfitWithdrawal $withdraw): RedirectResponse
    {
        $withdraw->approve($withdrawal, $request->user());

        return back()->with('success', "اعتُمد السحب {$withdrawal->number}: يُدفع من «{$withdrawal->cashBox?->name}».");
    }

    public function pay(Request $request, ProfitWithdrawal $withdrawal, RecordProfitWithdrawal $withdraw): RedirectResponse
    {
        $withdrawal = $withdraw->pay($withdrawal, $request->user());

        return back()->with('success', "دُفع السحب {$withdrawal->number}. اطبع سند الصرف للتوقيع.");
    }

    /** قبل الدفع إلغاء، وبعده عكسٌ في يومه — والعكس لمن يعتمد */
    public function cancel(Request $request, ProfitWithdrawal $withdrawal, RecordProfitWithdrawal $withdraw): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'السبب']);
        abort_if($withdrawal->isPaid() && ! $request->user()->can('withdrawals.approve'), 403);

        $withdrawal = $withdraw->cancel($withdrawal, $data['reason'], $request->user());

        return back()->with('success', $withdrawal->isReversed()
            ? "عُكس السحب {$withdrawal->number}، وعاد مبلغه إلى «{$withdrawal->cashBox?->name}»."
            : "أُلغي طلب السحب {$withdrawal->number}.");
    }

    /** سند الصرف للتوقيع */
    public function print(ProfitWithdrawal $withdrawal): View
    {
        $withdrawal->load(['cashBox', 'createdBy:id,name', 'approvedBy:id,name', 'paidBy:id,name']);

        return view('tenant.profits.withdrawal-print', ['w' => $withdrawal]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $sheet = new StreamingXlsx([
            ['الرقم', 12], ['الحال', 10], ['التاريخ', 12], ['المبلغ', 14, 'number'], ['المستفيد', 18], ['الصندوق أو الحساب', 20],
            ['السبب', 30], ['طلبه', 16], ['اعتمده', 16], ['دفعه', 16], ['وقت الدفع', 16],
            ['المتاح قبل', 14, 'number'], ['المتاح بعد', 14, 'number'], ['سبب الإلغاء أو العكس', 24],
        ], 'سحب الأرباح');

        foreach ($this->filtered($request)->with(['cashBox:id,name', 'createdBy:id,name', 'approvedBy:id,name', 'paidBy:id,name'])->lazyById(500) as $w) {
            $sheet->add([
                $w->number, $w->statusLabel(), $w->withdrawn_on?->format('Y-m-d'), (int) $w->amount, $w->withdrawn_by, $w->cashBox?->name,
                $w->note, $w->createdBy?->name, $w->approvedBy?->name, $w->paidBy?->name, $w->paid_at?->format('Y-m-d H:i'),
                $w->available_before, $w->available_after, $w->cancel_reason,
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'withdrawals-').'.xlsx';
        $sheet->save($path);

        return response()->download($path, 'profit-withdrawals-'.now()->format('Y-m-d-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ProfitWithdrawal> */
    protected function filtered(Request $request)
    {
        return ProfitWithdrawal::query()
            ->when(isset(ProfitWithdrawal::STATUSES[(string) $request->input('status')]), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->date('from'), fn ($q, $from) => $q->where('withdrawn_on', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('withdrawn_on', '<=', $to->toDateString()));
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Cash\RecordExpense;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المصروفات. الشاشة تُجيب سؤالين: ماذا علينا هذا الشهر، وأين ذهب المال.
 * الثاني لا يُجاب إلّا بأبواب مصنّفة، ولذلك لا حقل نصّي حرّ للباب.
 */
class ExpenseController extends Controller
{
    public function __construct(protected RecordExpense $expenses) {}

    public function index(Request $request): View
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now()->endOfMonth();

        $base = fn () => Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', 'cancelled');

        $expenses = Expense::with(['category:id,name_ar,group', 'branch:id,name', 'cashBox:id,name'])
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('spent_on')->orderByDesc('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.expenses.index', [
            'expenses'   => $expenses,
            'from'       => $from,
            'to'         => $to,
            'total'      => (int) $base()->sum('amount'),
            'unpaid'     => (int) $base()->where('status', 'recorded')->sum('amount'),
            'byCategory' => $this->byCategory($from, $to),
            'categories' => ExpenseCategory::availableFor($request->user()->company_id)->get(),
            'boxes'      => CashBox::active()->orderBy('name')->get(['id', 'name', 'balance']),
            'branches'   => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'expense_category_id' => ['required', 'integer'],
            'amount'              => ['required', 'integer', 'min:1'],
            'spent_on'            => ['required', 'date', 'before_or_equal:today'],
            'description'         => ['required', 'string', 'max:255'],
            'payee'               => ['nullable', 'string', 'max:120'],
            'reference'           => ['nullable', 'string', 'max:60'],
            'branch_id'           => ['nullable', 'integer'],
            'pay_now'             => ['nullable', 'boolean'],
            'cash_box_id'         => ['nullable', 'integer'],
        ], [], [
            'expense_category_id' => 'باب المصروف',
            'amount'              => 'المبلغ',
            'spent_on'            => 'التاريخ',
            'description'         => 'البيان',
        ]);

        $category = ExpenseCategory::availableFor($request->user()->company_id)->find($data['expense_category_id']);

        if (! $category) {
            return back()->withErrors(['expense_category_id' => 'باب المصروف غير معروف.'])->withInput();
        }

        $expense = $this->expenses->handle($data, $request->user());

        return back()->with('success', $expense->status === 'paid'
            ? "سُجّل المصروف {$expense->number} ودُفع من الصندوق."
            : "سُجّل المصروف {$expense->number}. يبقى غير مدفوع حتى يُدفَع من صندوق.");
    }

    public function pay(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'cash_box_id' => ['required', 'integer'],
        ], [], ['cash_box_id' => 'الصندوق']);

        $box = CashBox::active()->find($data['cash_box_id']);

        if (! $box) {
            return back()->withErrors(['cash_box_id' => 'اختر صندوقاً مفعّلاً.']);
        }

        if ($box->balance < $expense->amount) {
            return back()->withErrors([
                'cash_box_id' => "رصيد {$box->name} ".number_format($box->balance).' دينار فقط.',
            ]);
        }

        $this->expenses->pay($expense, $box, $request->user());

        return back()->with('success', "دُفع المصروف {$expense->number} من {$box->name}.");
    }

    public function cancel(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:255'],
        ], [], ['cancel_reason' => 'السبب']);

        $this->expenses->cancel($expense, $data['cancel_reason'], $request->user());

        return back()->with('success', "أُلغي المصروف {$expense->number}."
            .($expense->cash_box_id ? ' وأُعيد مبلغه إلى الصندوق بحركة معاكسة.' : ''));
    }

    /** أين ذهب المال — الجواب الذي يُبنى عليه التصنيف. */
    protected function byCategory($from, $to)
    {
        return Expense::query()
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->whereBetween('expenses.spent_on', [$from->toDateString(), $to->toDateString()])
            ->where('expenses.status', '!=', 'cancelled')
            ->selectRaw('expense_categories.name_ar as name, sum(expenses.amount) as total')
            ->groupBy('expense_categories.id', 'expense_categories.name_ar')
            ->orderByDesc('total')
            ->get();
    }
}

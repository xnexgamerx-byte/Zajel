<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Services\CashBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * القاصة — الجواب الوحيد على «كم ديناراً في الدرج الآن؟».
 *
 * دفتر الحركات يقول مَن له ومَن عليه؛ هذا يقول ما بقي فعلاً بعد أن
 * سلّم المندوبون وقبض التجّار ودُفعت المصروفات.
 */
class CashBoxController extends Controller
{
    public function __construct(protected CashBook $cash) {}

    public function index(Request $request): View
    {
        $boxes = CashBox::with('branch:id,name')->orderByRaw("case when type = 'main' then 0 else 1 end")
            ->orderBy('name')->get();

        $box = $request->integer('box_id')
            ? $boxes->firstWhere('id', $request->integer('box_id'))
            : $boxes->first();

        $movements = $box
            ? CashMovement::with('user:id,name')
                ->where('cash_box_id', $box->id)
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString()
            : null;

        return view('tenant.cash.index', [
            'boxes'     => $boxes,
            'box'       => $box,
            'movements' => $movements,
            'total'     => $boxes->where('is_active', true)->sum('balance'),
            // الجرد: هل الرصيد المخزَّن يطابق مجموع الحركات؟
            'check'     => $box ? $this->cash->reconcile($box) : null,
            'today'     => $box ? $this->todayTotals($box) : null,
            'branches'  => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'code'      => ['required', 'string', 'max:20', 'alpha_dash'],
            'type'      => ['required', 'in:main,branch,petty'],
            'branch_id' => ['nullable', 'integer'],
            'opening'   => ['nullable', 'integer', 'min:0'],
        ], [], ['name' => 'الاسم', 'code' => 'الرمز', 'type' => 'النوع']);

        if (CashBox::where('code', $data['code'])->exists()) {
            return back()->withErrors(['code' => 'هذا الرمز مستعمل لصندوق آخر.'])->withInput();
        }

        $box = CashBox::create([
            'code'      => $data['code'],
            'name'      => $data['name'],
            'type'      => $data['type'],
            'branch_id' => $data['branch_id'] ?? null,
            'balance'   => 0,
            'is_active' => true,
        ]);

        // الرصيد الافتتاحي حركة لا قيمة ابتدائية: أول سطر في جرد الصندوق
        if ($opening = (int) ($data['opening'] ?? 0)) {
            $this->cash->in($box, 'opening', $opening, 'رصيد افتتاحي', $request->user());
        }

        return redirect()->route('cash.index', ['box_id' => $box->id])
            ->with('success', "أُنشئ الصندوق {$box->name}.");
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_box_id' => ['required', 'integer'],
            'to_box_id'   => ['required', 'integer', 'different:from_box_id'],
            'amount'      => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['from_box_id' => 'الصندوق المُرسِل', 'to_box_id' => 'الصندوق المستلم', 'amount' => 'المبلغ']);

        $from = CashBox::active()->find($data['from_box_id']);
        $to = CashBox::active()->find($data['to_box_id']);

        if (! $from || ! $to) {
            return back()->withErrors(['to_box_id' => 'اختر صندوقين مفعّلين.']);
        }

        if ($from->balance < $data['amount']) {
            return back()->withErrors([
                'amount' => "رصيد {$from->name} ".number_format($from->balance).' دينار فقط.',
            ]);
        }

        $this->cash->transfer($from, $to, $data['amount'], $data['description'] ?? null, $request->user());

        return back()->with('success', 'نُقل '.number_format($data['amount'])." دينار من {$from->name} إلى {$to->name}.");
    }

    /**
     * تسوية جرد: عدّ اليد يخالف الرصيد أحياناً، والفرق يُقيَّد بسببه
     * لا يُكتب فوق الرصيد — وإلّا ضاع أثر النقص.
     */
    public function adjust(Request $request, CashBox $box): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', 'integer', 'min:0'],
            'reason'  => ['required', 'string', 'max:255'],
        ], [], ['counted' => 'المبلغ المعدود', 'reason' => 'السبب']);

        $drift = $data['counted'] - (int) $box->balance;

        if ($drift === 0) {
            return back()->with('success', 'الجرد مطابق — لا حركة.');
        }

        $method = $drift > 0 ? 'in' : 'out';
        $this->cash->{$method}($box, 'adjustment', abs($drift), 'تسوية جرد — '.$data['reason'], $request->user());

        return back()->with('success', 'قُيّد فرق الجرد '.number_format(abs($drift))
            .' دينار '.($drift > 0 ? 'زيادة' : 'نقصاً').'.');
    }

    /** حركة اليوم داخلاً وخارجاً — أكثر رقمين يُسألان آخر الدوام. */
    protected function todayTotals(CashBox $box): array
    {
        $rows = CashMovement::where('cash_box_id', $box->id)
            ->whereDate('created_at', today())
            ->selectRaw("direction, sum(amount) as total")
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return ['in' => (int) ($rows['in'] ?? 0), 'out' => (int) ($rows['out'] ?? 0)];
    }
}

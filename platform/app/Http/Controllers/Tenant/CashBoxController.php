<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\InsufficientCash;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\User;
use App\Enums\UserRole;
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
        // صناديق فرعه وحدها إن كان مقيَّداً بفرع: القاصة الرئيسية للفرع الرئيسي
        $boxes = CashBox::visibleTo($request->user())->with('branch:id,name')
            ->orderByRaw("case when type = 'main' then 0 else 1 end")
            ->orderBy('name')->get();

        $box = $request->integer('box_id')
            ? $boxes->firstWhere('id', $request->integer('box_id'))
            : $boxes->first();

        // من قام بالحركة، ونوعها، ومدّتها: حركةٌ فيها مشكلة تُراجَع بصاحبها
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : null;
        $filters = [
            'by'       => $request->integer('by') ?: null,
            'category' => is_string($request->query('category')) && $request->query('category') !== '' ? $request->query('category') : null,
            'from'     => $date('from'),
            'to'       => $date('to'),
        ];

        $movements = $box
            ? CashMovement::with('user:id,name')
                ->where('cash_box_id', $box->id)
                ->when($filters['by'], fn ($q, $by) => $q->where('created_by_user_id', $by))
                ->when($filters['category'], fn ($q, $category) => $q->where('category', $category))
                ->when($filters['from'], fn ($q, $from) => $q->whereFromDate('created_at', $from))
                ->when($filters['to'], fn ($q, $to) => $q->whereUntilDate('created_at', $to))
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString()
            : null;

        return view('tenant.cash.index', [
            'boxes'     => $boxes,
            'box'       => $box,
            'movements' => $movements,
            // ما أُلغي من هذه الصفحة: لا يُعرض له «إلغاء» ثانٍ (docs/plan/38)
            'undone'    => $movements ? CashMovement::where('reference_type', 'reversal')
                ->whereIn('reference_id', $movements->pluck('id')->all() ?: [0])->pluck('reference_id')->flip() : collect(),
            'filters'   => $filters,
            // من حرّك هذا الصندوق، وبأيّ نوع: خيارات الفلترة
            'movers'    => $box ? User::query()->whereIn('id', CashMovement::where('cash_box_id', $box->id)
                ->whereNotNull('created_by_user_id')->distinct()->select('created_by_user_id'))->orderBy('name')->get(['id', 'name']) : collect(),
            'categories' => $box ? CashMovement::where('cash_box_id', $box->id)->distinct()->pluck('category')
                ->mapWithKeys(fn ($category) => [$category => CashMovement::labelFor((string) $category)])->sort() : collect(),
            'total'     => $boxes->where('is_active', true)->sum('balance'),
            // الجرد: هل الرصيد المخزَّن يطابق مجموع الحركات؟
            'check'     => $box ? $this->cash->reconcile($box) : null,
            'today'     => $box ? $this->todayTotals($box) : null,
            'branches'  => Branch::query()
                ->when($request->user()->isBranchLimited(), fn ($q) => $q->whereKey($request->user()->branch_id))
                ->orderBy('name')->get(['id', 'name']),
            // من يُفتح له «صندوق موظّف»: موظّفو الشركة، لا المناديب ولا التجّار
            'staff'     => User::query()->visibleTo($request->user())->where('is_active', true)
                ->whereNotIn('role', [UserRole::Courier->value, UserRole::Merchant->value])
                ->whereNotIn('id', CashBox::whereNotNull('user_id')->select('user_id'))
                ->orderBy('name')->get(['id', 'name']),
            'owner'     => $box?->user_id ? User::find($box->user_id, ['id', 'name']) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'code'      => ['required', 'string', 'max:20', 'alpha_dash'],
            'type'      => ['required', 'in:main,branch,petty,employee'],
            'branch_id' => ['nullable', 'integer'],
            'user_id'   => ['nullable', 'required_if:type,employee', 'integer'],
            'opening'   => ['nullable', 'integer', 'min:0'],
        ], ['user_id.required_if' => 'اختر الموظّف صاحب الصندوق.'], ['name' => 'الاسم', 'code' => 'الرمز', 'type' => 'النوع', 'user_id' => 'الموظّف']);

        // موظّف الفرع يفتح صناديق فرعه وحده
        if ($request->user()->isBranchLimited()) {
            $data['branch_id'] = $request->user()->branch_id;
        }

        if (CashBox::where('code', $data['code'])->exists()) {
            return back()->withErrors(['code' => 'هذا الرمز مستعمل لصندوق آخر.'])->withInput();
        }

        $owner = null;

        if ($data['type'] === 'employee') {
            $owner = User::query()->whereKey($data['user_id'])->visibleTo($request->user())->where('is_active', true)
                ->whereNotIn('role', [UserRole::Courier->value, UserRole::Merchant->value])->first();

            if (! $owner) {
                return back()->withErrors(['user_id' => 'اختر موظّفاً مفعّلاً من موظّفي الشركة.'])->withInput();
            }

            if (CashBox::where('user_id', $owner->id)->exists()) {
                return back()->withErrors(['user_id' => "لـ{$owner->name} صندوقٌ سلفاً."])->withInput();
            }
        }

        $box = CashBox::create([
            'code'      => $data['code'],
            'name'      => $data['name'],
            'type'      => $data['type'],
            'branch_id' => $data['branch_id'] ?? $owner?->branch_id,
            'user_id'   => $owner?->id,
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

    /**
     * صاحب الصندوق: صندوقٌ أُنشئ باسم المحاسب صندوقَ فرعٍ لا يعرف أنه له، فكان ما يقبضه
     * يدخل القاصة الرئيسية. ربطه بموظّفه يجعل ما يقبضه — محاسبة المندوبين والأجور
     * المقبوضة مقدّماً — يدخله هو (CashBox::forActor). والقاصة الرئيسية للشركة لا لموظّف.
     */
    public function owner(Request $request, CashBox $box): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer']], [], ['user_id' => 'الموظّف']);

        abort_unless(CashBox::visibleTo($request->user())->whereKey($box->id)->exists(), 404);

        if ($box->type === 'main') {
            return back()->withErrors(['user_id' => 'القاصة الرئيسية للشركة كلّها، لا تُربط بموظّف.']);
        }

        if (! filled($data['user_id'] ?? null)) {
            $box->forceFill(['user_id' => null, 'type' => 'branch'])->save();

            return back()->with('success', "صار {$box->name} صندوق فرع، لا صندوق موظّف.");
        }

        $owner = User::query()->whereKey($data['user_id'])->visibleTo($request->user())->where('is_active', true)
            ->whereNotIn('role', [UserRole::Courier->value, UserRole::Merchant->value])->first();

        if (! $owner) {
            return back()->withErrors(['user_id' => 'اختر موظّفاً مفعّلاً من موظّفي الشركة.']);
        }

        if (CashBox::where('user_id', $owner->id)->whereKeyNot($box->id)->exists()) {
            return back()->withErrors(['user_id' => "لـ{$owner->name} صندوقٌ سلفاً."]);
        }

        $box->forceFill(['user_id' => $owner->id, 'type' => 'employee'])->save();

        return back()->with('success', "صار {$box->name} صندوق {$owner->name}: ما يقبضه بيده يدخله.");
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_box_id' => ['required', 'integer'],
            'to_box_id'   => ['required', 'integer', 'different:from_box_id'],
            'amount'      => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['from_box_id' => 'الصندوق المُرسِل', 'to_box_id' => 'الصندوق المستلم', 'amount' => 'المبلغ']);

        // بين صناديق فرعه وحده؛ وما يخرج إلى فرعٍ آخر حوالةٌ في «كشف حساب الفروع»
        $from = CashBox::active()->visibleTo($request->user())->find($data['from_box_id']);
        $to = CashBox::active()->visibleTo($request->user())->find($data['to_box_id']);

        if (! $from || ! $to) {
            return back()->withErrors(['to_box_id' => 'اختر صندوقين مفعّلين.']);
        }

        if ($from->balance < $data['amount']) {
            return back()->withErrors([
                'amount' => InsufficientCash::sentence($from, (int) $from->balance, (int) $data['amount'], 'transfer_out'),
            ])->withInput();
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

    /** مناقلةٌ أو تسوية جرد خطأ، في يومها: حركةٌ معاكسة بسببها (docs/plan/38). */
    public function undo(Request $request, \App\Models\CashMovement $movement, \App\Actions\Money\UndoWithinDay $undo): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'سبب الإلغاء']);

        abort_unless(CashBox::visibleTo($request->user())->whereKey($movement->cash_box_id)->exists(), 404);

        $undo->cashMovement($movement, $request->user(), $data['reason']);

        return back()->with('success', 'أُلغيت الحركة بحركةٍ معاكسة، وعاد الرصيد كما كان.');
    }

    /** حركة اليوم داخلاً وخارجاً — أكثر رقمين يُسألان آخر الدوام. */
    protected function todayTotals(CashBox $box): array
    {
        $rows = CashMovement::where('cash_box_id', $box->id)
            ->whereOnDate('created_at', today())
            ->selectRaw("direction, sum(amount) as total")
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return ['in' => (int) ($rows['in'] ?? 0), 'out' => (int) ($rows['out'] ?? 0)];
    }
}

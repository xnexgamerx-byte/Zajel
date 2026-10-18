<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Profits\ManageCollectionBatch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchCost;
use App\Models\CollectionBatchItem;
use App\Models\CourierSettlementShipment;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * «وجبات التحصيل والأرباح»: إنشاء الوجبة من كشوف المناديب المُقفَلة، ومطابقتها، واعتمادها،
 * وسجلّها. كل تعديلٍ في سجلّ الوجبة، وبعد الاعتماد بسببه.
 */
class CollectionBatchController extends Controller
{
    public function __construct(protected ManageCollectionBatch $batches) {}

    /** «سجل وجبات التحصيل» */
    public function index(Request $request): View
    {
        $user = $request->user();

        $batches = CollectionBatch::query()->visibleTo($user)
            ->with(['branch:id,name', 'approvedBy:id,name'])
            ->when($request->input('status'), fn ($q, $status) => match ($status) {
                'draft', 'approved' => $q->where('status', $status),
                'discrepancy'       => $q->where('discrepancy', '!=', 0),
                default             => $q,
            })
            ->when(! $user->isBranchLimited() && $request->integer('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->date('from'), fn ($q, $from) => $q->whereFromDate('to_date', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->whereUntilDate('to_date', $to->toDateString()))
            ->when(trim((string) $request->input('q')), fn ($q, $term) => $q->where('number', 'like', '%'.$term.'%'))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.profits.batches', [
            'batches'  => $batches,
            'branches' => $user->isBranchLimited() ? collect() : Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** «إنشاء وجبة تحصيل»: المدّة والفرع، ثم الكشوف المُقفَلة التي لم تدخل وجبة */
    public function create(Request $request): View
    {
        $user = $request->user();
        [$period, $from, $to] = $this->period($request);
        $branchId = $user->isBranchLimited() ? $user->branch_id : ($request->integer('branch_id') ?: null);

        $settlements = ManageCollectionBatch::eligible($user, $branchId, $from, $to)
            ->with(['courier:id,name,code', 'confirmedBy:id,name'])
            ->orderBy('courier_id')->orderBy('confirmed_at')
            ->get();

        return view('tenant.profits.create', [
            'period'      => $period,
            'from'        => $from,
            'to'          => $to,
            'branchId'    => $branchId,
            'branches'    => $user->isBranchLimited() ? collect() : Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'settlements' => $settlements,
            // ما أُقفل خارج المدّة ولم يدخل وجبةً بعد: لا يُنسى
            'older'       => ManageCollectionBatch::eligible($user, $branchId, null, $from->copy()->subDay())->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period'           => ['required', 'string', 'in:'.implode(',', array_keys(CollectionBatch::PERIODS))],
            'from'             => ['required', 'date'],
            'to'               => ['required', 'date', 'after_or_equal:from'],
            'branch_id'        => ['nullable', 'integer'],
            'settlement_ids'   => ['required', 'array', 'min:1', 'max:2000'],
            'settlement_ids.*' => ['integer'],
            'notes'            => ['nullable', 'string', 'max:500'],
        ], ['settlement_ids.required' => 'اختر كشوف المناديب التي تدخل الوجبة.'], ['to' => 'إلى']);

        $user = $request->user();
        $branchId = $user->isBranchLimited() ? $user->branch_id
            : (filled($data['branch_id'] ?? null) ? Branch::findOrFail($data['branch_id'])->id : null);

        $batch = $this->batches->create(
            array_map('intval', $data['settlement_ids']), $branchId, $data['period'],
            Carbon::parse($data['from']), Carbon::parse($data['to']), $data['notes'] ?? null, $user,
        );

        return redirect()->route('profits.batches.show', $batch)
            ->with('success', "أُنشئت وجبة التحصيل {$batch->number}: طابِق ما استُلم من كل مندوب، ثم اعتمدها.");
    }

    public function show(Request $request, CollectionBatch $batch): View
    {
        return view('tenant.profits.show', $this->sheet($request, $batch) + [
            'addable'  => $batch->isApproved() ? collect() : ManageCollectionBatch::eligible($request->user(), $batch->branch_id)
                ->with('courier:id,name')->orderBy('confirmed_at')->limit(300)->get(),
            'expenses' => Expense::query()->visibleTo($request->user())
                ->where('status', '!=', 'cancelled')
                ->whereNotIn('id', CollectionBatchCost::query()->select('expense_id'))
                ->when($batch->branch_id, fn ($q) => $q->where('branch_id', $batch->branch_id))
                ->whereFromDate('spent_on', $batch->from_date->toDateString())
                ->whereUntilDate('spent_on', $batch->to_date->toDateString())
                ->with('category:id,name_ar')->orderBy('spent_on')->get(),
            // عجزٌ في وجباتٍ معتمدة سابقة لم يُسدَّد: يُحصَّل في هذه الوجبة بسطرٍ موثَّق
            'shortages' => $batch->isApproved() ? collect() : CollectionBatchItem::query()
                ->with(['courier:id,name', 'settlement:id,code', 'batch:id,number'])
                ->where('kind', 'settlement')
                ->whereColumn('received_cash', '<', 'expected_cash')
                ->whereHas('batch', fn ($q) => $q->visibleTo($request->user())->where('status', 'approved'))
                ->get()
                ->filter(fn (CollectionBatchItem $item) => $item->outstanding() > 0)
                ->values(),
        ]);
    }

    /** ورقة الوجبة للطباعة والتوقيع */
    public function print(Request $request, CollectionBatch $batch): View
    {
        return view('tenant.profits.print', $this->sheet($request, $batch));
    }

    public function addItems(Request $request, CollectionBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'settlement_ids'   => ['required', 'array', 'min:1', 'max:2000'],
            'settlement_ids.*' => ['integer'],
        ], ['settlement_ids.required' => 'اختر الكشوف التي تُضاف.']);

        $this->batches->addSettlements($batch, array_map('intval', $data['settlement_ids']), $request->user());

        return back()->with('success', 'أُضيفت الكشوف إلى الوجبة '.$batch->number.'.');
    }

    public function removeItem(Request $request, CollectionBatch $batch, CollectionBatchItem $item): RedirectResponse
    {
        $this->batches->removeItem($batch, $item, $request->user());

        return back()->with('success', 'أُخرج السطر من الوجبة، وعاد كشفه لوجبةٍ أخرى.');
    }

    public function received(Request $request, CollectionBatch $batch, CollectionBatchItem $item): RedirectResponse
    {
        $data = $request->validate([
            'received_cash' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'reason'        => ['nullable', 'string', 'max:255'],
        ], [], ['received_cash' => 'المستلم فعلاً', 'reason' => 'سبب التصحيح']);

        $this->batches->setReceived($batch, $item, (int) $data['received_cash'], $data['reason'] ?? null, $request->user());

        return back()->with('success', 'حُفظ المستلم فعلاً'.($batch->isApproved() ? '، والتصحيح في سجلّ الوجبة.' : '.'));
    }

    public function addCosts(Request $request, CollectionBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'expense_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'expense_ids.*' => ['integer'],
            'reason'        => ['nullable', 'string', 'max:255'],
        ], ['expense_ids.required' => 'اختر المصروفات التي تُحسب على الوجبة.'], ['reason' => 'سبب التصحيح']);

        $this->batches->addCosts($batch, array_map('intval', $data['expense_ids']), $data['reason'] ?? null, $request->user());

        return back()->with('success', 'حُسبت المصروفات على الوجبة '.$batch->number.'.');
    }

    public function removeCost(Request $request, CollectionBatch $batch, CollectionBatchCost $cost): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']], [], ['reason' => 'سبب التصحيح']);

        $this->batches->removeCost($batch, $cost, $data['reason'] ?? null, $request->user());

        return back()->with('success', 'أُخرج المصروف من الوجبة.');
    }

    public function remainder(Request $request, CollectionBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'remainder_of_item_id' => ['required', 'integer'],
            'amount'               => ['required', 'integer', 'min:1', 'max:100000000000'],
            'note'                 => ['required', 'string', 'max:255'],
        ], [], ['remainder_of_item_id' => 'العجز', 'amount' => 'المبلغ', 'note' => 'البيان']);

        $original = CollectionBatchItem::whereHas('batch', fn ($q) => $q->visibleTo($request->user()))
            ->findOrFail($data['remainder_of_item_id']);

        $this->batches->addRemainder($batch, $original, (int) $data['amount'], $data['note'], $request->user());

        return back()->with('success', 'سُجِّل تحصيل '.number_format((int) $data['amount']).' د.ع من عجزٍ سابق في هذه الوجبة.');
    }

    public function approve(Request $request, CollectionBatch $batch): RedirectResponse
    {
        $batch = $this->batches->approve($batch, $request->user());

        return back()->with('success', "اعتُمدت الوجبة {$batch->number}: صافي ربحها ".number_format((int) $batch->net_profit).' د.ع.');
    }

    public function destroy(Request $request, CollectionBatch $batch): RedirectResponse
    {
        $this->batches->delete($batch, $request->user());

        return redirect()->route('profits.batches.index')
            ->with('success', "حُذفت الوجبة {$batch->number}. كشوفها ومصروفاتها تدخل وجبةً أخرى.");
    }

    /** @return array<string, mixed> */
    protected function sheet(Request $request, CollectionBatch $batch): array
    {
        $batch->load(['branch:id,name', 'approvedBy:id,name', 'createdBy:id,name', 'costLines.expense.category:id,name_ar']);

        $items = $batch->items()
            ->with(['courier:id,name,code', 'settlement:id,code,confirmed_at', 'remainderOf.batch:id,number', 'remainderOf.settlement:id,code'])
            ->withSum('remainders as repaid', 'received_cash')
            ->orderBy('courier_id')->orderBy('id')->get();

        // شحنات كل كشف: ورقةٌ مفصّلة لكل مندوب
        $lines = CourierSettlementShipment::query()
            ->whereIn('courier_settlement_id', $items->pluck('courier_settlement_id')->filter()->all() ?: [0])
            ->with('shipment:id,number,status,delivered_at,total_fees,return_fee,merchant_due,merchant_id', 'shipment.merchant:id,business_name')
            ->get()
            ->groupBy('courier_settlement_id');

        return [
            'batch'   => $batch,
            'items'   => $items,
            'byCourier' => $items->groupBy(fn ($item) => (int) $item->courier_id),
            'lines'   => $lines,
            'audits'  => $batch->audits()->latest('id')->get(),
        ];
    }

    /**
     * المدّة: أسبوع أو ١٥ يوماً أو شهر تنتهي اليوم، أو ما يُحدَّد بالتاريخين («custom»).
     *
     * @return array{string, Carbon, Carbon}
     */
    protected function period(Request $request): array
    {
        $period = (string) $request->input('period', 'week');
        $days = CollectionBatch::PERIODS[$period][1] ?? null;

        if ($days !== null) {
            return [$period, now()->subDays($days - 1)->startOfDay(), now()->endOfDay()];
        }

        $from = $request->date('from') ?? now()->subDays(6);
        $to = $request->date('to') ?? now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [array_key_exists($period, CollectionBatch::PERIODS) ? $period : 'custom', $from->startOfDay(), $to->endOfDay()];
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Cash\RemitBetweenBranches;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchRemittance;
use App\Models\CashBox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «ديون على الفروع» و«استلام المبالغ المسدّدة من الفروع» كما في المعتاد.
 *
 * الدَّين من الشحنات نفسها: فرعٌ سلّم شحنة تاجرِ فرعٍ آخر فجمع نقداً ليس له.
 * ويُطفأ بما وصل فعلاً لا بما أُرسل: ما بالطريق يُعرض وحده، والفرق يبقى دَيناً.
 */
class BranchRemittanceController extends Controller
{
    /** الفرع الجامع للنقد: فرع المندوب الموصِّل، وإلّا (ما سُلِّم قبل docs/plan/51) فرع الشحنة */
    public const COLLECTOR = 'coalesce(shipments.delivery_branch_id, shipments.branch_id)';

    public function __construct(protected RemitBetweenBranches $remit) {}

    public function debts(Request $request): View
    {
        $mine = $request->user()->isBranchLimited() ? $request->user()->branch_id : null;

        $owed = DB::table('shipments')
            ->join('merchants', 'merchants.id', '=', 'shipments.merchant_id')
            ->where('shipments.company_id', $request->user()->company_id)
            ->whereNull('shipments.deleted_at')
            // ما حُصِّل: سُلِّمت كلّها أو بعضها (وباقي الواصل الجزئي يرجع بلا مال)
            ->whereNotNull('shipments.delivered_at')
            ->whereNotNull('merchants.branch_id')
            /*
            | المدين: الفرع الذي وصّل — فرعُ مندوبه (delivery_branch_id)، وما قبلها فرعُ الشحنة.
            | ويُطرح من المستحقّ عمولةُ الفرع عن كلّ طلبٍ وصّله: يحتفظ بها من النقد (docs/plan/51).
            */
            ->whereRaw(self::COLLECTOR.' is not null')
            ->whereRaw(self::COLLECTOR.' != merchants.branch_id')
            ->when($mine, fn ($q) => $q->where(fn ($w) => $w->whereRaw(self::COLLECTOR.' = ?', [$mine])->orWhere('merchants.branch_id', $mine)))
            ->selectRaw(self::COLLECTOR.' as debtor, merchants.branch_id as creditor,
                         count(*) as shipments, sum(shipments.collected_amount) as collected,
                         sum(shipments.branch_commission) as commission,
                         sum(shipments.collected_amount - shipments.branch_commission) as amount')
            ->groupByRaw(self::COLLECTOR.', merchants.branch_id')
            ->get();

        $remitted = BranchRemittance::query()->touching($mine)
            ->selectRaw("from_branch_id, to_branch_id,
                sum(case when status = 'received' then received_amount else 0 end) as received,
                sum(case when status = 'sent' then amount else 0 end) as in_transit")
            ->groupBy('from_branch_id', 'to_branch_id')
            ->toBase()
            ->get()
            ->keyBy(fn ($r) => $r->from_branch_id.'-'.$r->to_branch_id);

        $pairs = $owed->map(function ($row) use ($remitted) {
            $paid = $remitted[$row->debtor.'-'.$row->creditor] ?? null;
            $row->received = (int) ($paid->received ?? 0);
            $row->in_transit = (int) ($paid->in_transit ?? 0);
            $row->remaining = (int) $row->amount - $row->received - $row->in_transit;

            return $row;
        })->sortByDesc('remaining')->values();

        return view('tenant.branch_accounts.debts', [
            'pairs'    => $pairs,
            'branches' => Branch::withTrashed()->get(['id', 'name'])->keyBy('id'),
            'boxes'    => $this->branchBoxes(),
        ]);
    }

    public function inbox(Request $request): View
    {
        $mine = $request->user()->isBranchLimited() ? $request->user()->branch_id : null;

        return view('tenant.branch_accounts.remittances', [
            'pending'  => BranchRemittance::pending()
                ->when($mine, fn ($q) => $q->where('to_branch_id', $mine))
                ->with(['fromBranch:id,name', 'toBranch:id,name', 'sentBy:id,name'])
                ->oldest('sent_at')->get(),
            'history'  => BranchRemittance::where('status', 'received')->touching($mine)
                ->with(['fromBranch:id,name', 'toBranch:id,name', 'receivedBy:id,name', 'toBox:id,name'])
                ->latest('received_at')->limit(30)->get(),
            'outgoing' => BranchRemittance::pending()
                ->when($mine, fn ($q) => $q->where('from_branch_id', $mine))
                ->with(['toBranch:id,name'])->latest('sent_at')->limit(30)->get(),
            'boxes'    => $this->branchBoxes(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id'   => ['required', 'integer', 'different:from_branch_id'],
            'from_box_id'    => ['required', 'integer'],
            'amount'         => ['required', 'integer', 'min:1', 'max:10000000000'],
            'note'           => ['nullable', 'string', 'max:255'],
        ], [], ['from_box_id' => 'الصندوق', 'amount' => 'المبلغ', 'to_branch_id' => 'الفرع المستلم']);

        $user = $request->user();

        // المقيَّد بفرع يسدّد من فرعه وحده
        abort_if($user->isBranchLimited() && (int) $data['from_branch_id'] !== (int) $user->branch_id, 403);

        $from = Branch::findOrFail($data['from_branch_id']);
        $to = Branch::findOrFail($data['to_branch_id']);
        $box = CashBox::findOrFail($data['from_box_id']);

        $remittance = $this->remit->send($from, $to, $box, (int) $data['amount'], $user, $data['note'] ?? null);

        return back()->with('success', 'أُرسل '.number_format($remittance->amount)." دينار من {$from->name} إلى {$to->name} ({$remittance->number}). يُطفئ الدَّين حين يُستلم.");
    }

    public function receive(Request $request, BranchRemittance $remittance): RedirectResponse
    {
        $data = $request->validate([
            'received_amount' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'to_box_id'       => ['required', 'integer'],
            'difference_note' => ['nullable', 'string', 'max:255'],
        ], [], ['received_amount' => 'المستلم فعلياً', 'to_box_id' => 'الصندوق', 'difference_note' => 'ملاحظة الفرق']);

        $user = $request->user();
        abort_if($user->isBranchLimited() && (int) $remittance->to_branch_id !== (int) $user->branch_id, 403);

        $done = $this->remit->receive($remittance, CashBox::findOrFail($data['to_box_id']), (int) $data['received_amount'],
            $user, $data['difference_note'] ?? null);

        $difference = $done->difference();

        return back()->with('success', "استُلم {$done->number}: ".number_format($done->received_amount).' دينار'
            .($difference ? '، بفرق '.number_format($difference).' عن المُرسَل.' : '.'));
    }

    /** صناديق الفروع (لا صناديق الموظّفين) بفرعها */
    private function branchBoxes(): Collection
    {
        return CashBox::active()->whereNull('user_id')->whereNotNull('branch_id')
            ->orderBy('name')->get(['id', 'name', 'branch_id', 'balance'])->groupBy('branch_id');
    }
}

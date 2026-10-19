<?php

namespace App\Services\Money;

use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchItem;
use App\Models\Courier;
use App\Models\ProfitWithdrawal;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «العوائد»: أرقام الأرباح من وجبات التحصيل، وما سُحب منها.
 *
 * الربح المحسوب من الوجبات المعتمدة وحدها، والمسودّة تُعرض منتظرةً لا تُجمع. والمتاح
 * للسحب من المتحقّق نقداً لا من المحاسبيّ: أجرٌ على حساب تاجرٍ لم يُدفع بعدُ ليس في اليد.
 */
final class Profits
{
    /** @return array<string, int> */
    public function summary(?User $viewer): array
    {
        $approved = CollectionBatch::query()->visibleTo($viewer)->where('status', 'approved')
            ->selectRaw('count(*) as n, coalesce(sum(net_profit), 0) as profit, coalesce(sum(realized_profit), 0) as realized,
                coalesce(sum(merchant_money), 0) as merchant, coalesce(sum(delivery_fees), 0) as fees')
            ->toBase()->first();

        // لم تُعتمد: كل حالٍ قبل الاعتماد — والملغاة لا تُعدّ
        $drafts = CollectionBatch::query()->visibleTo($viewer)->whereIn('status', CollectionBatch::OPEN)
            ->selectRaw('count(*) as n, coalesce(sum(net_profit), 0) as profit')->toBase()->first();

        $withDiscrepancy = CollectionBatch::query()->visibleTo($viewer)->where('status', '!=', 'cancelled')->where('discrepancy', '!=', 0)->count();

        // السحب للشركة كلّها: موظّف الفرع لا يرى ما سحبه صاحبها
        $withdrawn = $viewer?->isBranchLimited() ? 0 : (int) ProfitWithdrawal::where('status', 'paid')->sum('amount');

        return [
            'approved_count'   => (int) $approved->n,
            'profit'           => (int) $approved->profit,
            'realized'         => (int) $approved->realized,
            'unrealized'       => (int) $approved->profit - (int) $approved->realized,
            'merchant_money'   => (int) $approved->merchant,
            'fees'             => (int) $approved->fees,
            'draft_count'      => (int) $drafts->n,
            'draft_profit'     => (int) $drafts->profit,
            'discrepancy_count' => $withDiscrepancy,
            'shortage'         => $this->outstandingShortage($viewer),
            'withdrawn'        => $withdrawn,
            'available'        => (int) $approved->realized - $withdrawn,
        ];
    }

    /** المتاح للسحب للشركة كلّها: المتحقّق نقداً من الوجبات المعتمدة ناقص ما سُحب */
    public function available(): int
    {
        return (int) CollectionBatch::where('status', 'approved')->sum('realized_profit')
            - (int) ProfitWithdrawal::where('status', 'paid')->sum('amount');
    }

    /** عجزٌ في عهدة المناديب لم يُسدَّد: نقص الكشوف ناقص ما حُصِّل منه بعدها */
    public function outstandingShortage(?User $viewer): int
    {
        $items = CollectionBatchItem::query()
            ->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_items.collection_batch_id')
            ->when($viewer?->isBranchLimited(), fn ($q) => $q->where('collection_batches.branch_id', $viewer->branch_id));

        $short = (int) (clone $items)->where('collection_batch_items.kind', 'settlement')
            ->whereColumn('collection_batch_items.received_cash', '<', 'collection_batch_items.expected_cash')
            ->sum(DB::raw('collection_batch_items.expected_cash - collection_batch_items.received_cash'));

        $paid = (int) (clone $items)->where('collection_batch_items.kind', 'remainder')
            ->sum('collection_batch_items.received_cash');

        return max(0, $short - $paid);
    }

    /**
     * تقرير المدّة: الوجبات التي تنتهي فيها، بالفرع أو المندوب إن اختير.
     *
     * والمندوب يُقرأ من سطوره: التكاليف على الوجبة لا على مندوبٍ بعينه، فلا تُقسَم عليه.
     *
     * @return array{totals: array<string,int>, branches: Collection, couriers: Collection, batches: Collection}
     */
    public function report(?User $viewer, ReportPeriod $period, ?int $branchId = null, ?int $courierId = null, bool $drafts = false): array
    {
        $batches = CollectionBatch::query()->visibleTo($viewer)
            ->with('branch:id,name')
            ->whereFromDate('to_date', $period->from->toDateString())
            ->whereUntilDate('to_date', $period->to->toDateString())
            ->when(! $drafts, fn ($q) => $q->where('status', 'approved'), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($courierId, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('courier_id', $courierId)))
            ->orderByDesc('to_date')->orderByDesc('id')
            ->get();

        $ids = $batches->pluck('id')->all() ?: [0];

        $couriers = CollectionBatchItem::query()
            ->whereIn('collection_batch_id', $ids)
            ->when($courierId, fn ($q) => $q->where('courier_id', $courierId))
            ->selectRaw("courier_id,
                sum(case when kind = 'settlement' then 1 else 0 end) as settlements,
                sum(shipments_count) as shipments, sum(delivered_count) as delivered,
                sum(cod_collected) as cod, sum(merchant_money) as merchant, sum(delivery_fees) as fees,
                sum(courier_commissions) as commissions, sum(deductions) as deductions,
                sum(case when kind = 'settlement' then expected_cash else 0 end) as expected,
                sum(case when kind = 'settlement' then received_cash else 0 end) as received,
                sum(case when kind = 'remainder' then received_cash else 0 end) as remainders")
            ->groupBy('courier_id')
            ->toBase()->get();

        $names = Courier::withTrashed()->whereKey($couriers->pluck('courier_id')->filter()->all())->pluck('name', 'id');
        $couriers = $couriers->map(function ($row) use ($names) {
            $row->name = $names[$row->courier_id] ?? '—';
            $row->contribution = (int) $row->fees - (int) $row->commissions;
            $row->discrepancy = (int) $row->received - (int) $row->expected;

            return $row;
        })->sortByDesc('contribution')->values();

        $branchNames = Branch::withTrashed()->pluck('name', 'id');
        $branches = $batches->groupBy(fn ($b) => (int) $b->branch_id)->map(fn ($group, $id) => (object) [
            'name'        => $id ? ($branchNames[$id] ?? '—') : 'كل الفروع',
            'batches'     => $group->count(),
            'shipments'   => (int) $group->sum('shipments_count'),
            'cod'         => (int) $group->sum('cod_collected'),
            'merchant'    => (int) $group->sum('merchant_money'),
            'fees'        => (int) $group->sum('delivery_fees'),
            'commissions' => (int) $group->sum('courier_commissions'),
            'costs'       => (int) $group->sum('costs'),
            'profit'      => (int) $group->sum('net_profit'),
            'realized'    => (int) $group->sum('realized_profit'),
            'discrepancy' => (int) $group->sum('discrepancy'),
        ])->sortByDesc('profit')->values();

        // بمندوبٍ بعينه: أرقامه من سطوره، وصافيه مساهمته (أجوره ناقص عمولته) بلا تكاليف
        $totals = $courierId
            ? [
                'batches'     => $batches->count(),
                'shipments'   => (int) $couriers->sum('shipments'),
                'cod'         => (int) $couriers->sum('cod'),
                'merchant'    => (int) $couriers->sum('merchant'),
                'fees'        => (int) $couriers->sum('fees'),
                'commissions' => (int) $couriers->sum('commissions'),
                'costs'       => 0,
                'profit'      => (int) $couriers->sum('contribution'),
                'realized'    => (int) $couriers->sum('received') + (int) $couriers->sum('remainders') - (int) $couriers->sum('merchant') - (int) $couriers->sum('deductions'),
                'discrepancy' => (int) $couriers->sum('discrepancy'),
            ]
            : [
                'batches'     => $batches->count(),
                'shipments'   => (int) $batches->sum('shipments_count'),
                'cod'         => (int) $batches->sum('cod_collected'),
                'merchant'    => (int) $batches->sum('merchant_money'),
                'fees'        => (int) $batches->sum('delivery_fees'),
                'commissions' => (int) $batches->sum('courier_commissions'),
                'costs'       => (int) $batches->sum('costs'),
                'profit'      => (int) $batches->sum('net_profit'),
                'realized'    => (int) $batches->sum('realized_profit'),
                'discrepancy' => (int) $batches->sum('discrepancy'),
            ];

        $totals['withdrawn'] = $viewer?->isBranchLimited() ? 0 : (int) ProfitWithdrawal::where('status', 'paid')
            ->whereFromDate('withdrawn_on', $period->from->toDateString())
            ->whereUntilDate('withdrawn_on', $period->to->toDateString())->sum('amount');

        return ['totals' => $totals, 'branches' => $branches, 'couriers' => $couriers, 'batches' => $batches];
    }
}

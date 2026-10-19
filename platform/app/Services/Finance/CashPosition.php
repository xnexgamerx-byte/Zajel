<?php

namespace App\Services\Finance;

use App\Actions\Profits\RecordProfitWithdrawal;
use App\Models\BranchRemittance;
use App\Models\CashBox;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchItem;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Governorate;
use App\Models\JournalLine;
use App\Models\Shipment;
use App\Models\User;
use App\Support\DeliveryDeadline;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «الموقف النقدي والتدفق المتوقَّع» (docs/plan/68 §١١): فئاتٌ منفصلة لا تُجمع في رقمٍ واحد،
 * وتوقّعٌ لأربعة عشر يوماً بنطاقٍ أدنى وأعلى من تذبذب الماضي، بافتراضاته مكتوبةً.
 *
 * التوقّع من سلوك الشركة نفسها في آخر ٣٠ يوماً — نسبة التسليم لكل محافظة، وزمن التسليم من
 * الاستلام، وتأخّر تسليم المندوب نقده — ولا يُعرض إن قلّ تاريخها عن ٣٠ يوماً.
 */
final class CashPosition
{
    public const DAYS = 14;

    public const WINDOW = 30;

    public function __construct(private ?User $viewer = null) {}

    /** الفئات التسع */
    public function categories(): array
    {
        $boxes = CashBox::active()->visibleTo($this->viewer)->orderByRaw("case when type = 'main' then 0 when type = 'bank' then 2 else 1 end")
            ->orderBy('name')->get(['id', 'name', 'type', 'bank_name', 'account_label', 'balance', 'branch_id', 'user_id']);

        $board = new ProfitDashboard($this->viewer);
        $available = $this->viewer?->isBranchLimited() ? null : $board->available();

        return [
            'boxes'        => $boxes,
            'cash'         => (int) $boxes->sum('balance'),
            'unreconciled' => $this->unreconciled(),
            'couriers'     => $this->custody(),
            'in_transit'   => [
                'amount' => (int) BranchRemittance::pending()->when($this->viewer?->isBranchLimited(), fn ($q) => $q->where(fn ($w) => $w
                    ->where('from_branch_id', $this->viewer->branch_id)->orWhere('to_branch_id', $this->viewer->branch_id)))->sum('amount'),
                'count'  => BranchRemittance::pending()->count(),
            ],
            'expected'     => $this->expected(),
            'merchants'    => $board->merchantPayables(),
            'liabilities'  => [
                'expenses_due' => max(0, -$board->net([A::EXPENSES_DUE])),
                'commissions'  => (int) Courier::query()->visibleTo($this->viewer)->where('commission_balance', '>', 0)->sum('commission_balance'),
                'deposits'     => max(0, -$board->net([A::DEPOSITS])),
                'prepaid'      => max(0, -$board->net([A::PREPAID])),
                'excess'       => max(0, -$board->net([A::EXCESS_CASH])),
            ],
            'reserve'      => $board->reserve(),
            'available'    => $available,
            'committed'    => $available ? app(RecordProfitWithdrawal::class)->committed() : 0,
        ];
    }

    /** منه: نقدٌ في الصناديق لم تُطابَق وجبته بعد — جزءٌ من الصناديق لا يُضاف إليها */
    public function unreconciled(): array
    {
        $open = CollectionBatchItem::query()
            ->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_items.collection_batch_id')
            ->whereIn('collection_batches.status', CollectionBatch::OPEN)
            ->where('collection_batch_items.status', '!=', 'pending_cash')
            ->when($this->viewer?->isBranchLimited(), fn ($q) => $q->where('collection_batches.branch_id', $this->viewer->branch_id));

        $unbatched = CourierSettlement::query()->visibleTo($this->viewer)
            ->whereIn('courier_settlements.status', ['confirmed', 'paid'])->whereNull('courier_settlements.cancelled_at')
            ->whereNotIn('courier_settlements.id', CollectionBatchItem::query()->whereNotNull('courier_settlement_id')->select('courier_settlement_id'));

        return [
            'in_batches'       => (int) (clone $open)->sum('collection_batch_items.received_cash'),
            'batches'          => (int) (clone $open)->distinct()->count('collection_batches.id'),
            'unbatched'        => (int) (clone $unbatched)->sum('net_amount'),
            'unbatched_count'  => (clone $unbatched)->count(),
        ];
    }

    /** النقد عند المناديب بأعماره: ما سُلِّم ولم يُقفَل كشفه، بعمر تسليمه */
    public function custody(): array
    {
        $now = now()->startOfDay();
        $rows = Shipment::query()->visibleTo($this->viewer)->toBase()
            ->whereNotNull('shipments.delivered_at')->whereNotNull('shipments.delivery_courier_id')->whereNull('shipments.courier_settlement_id')
            ->selectRaw('date(shipments.delivered_at) as day, sum(shipments.collected_amount) as cash')
            ->groupBy(DB::raw('date(shipments.delivered_at)'))->get();

        $ages = ['0_1' => 0, '2_3' => 0, '4_7' => 0, '8' => 0];
        foreach ($rows as $row) {
            $age = (int) Carbon::parse($row->day)->startOfDay()->diffInDays($now);
            $ages[match (true) { $age <= 1 => '0_1', $age <= 3 => '2_3', $age <= 7 => '4_7', default => '8' }] += (int) $row->cash;
        }

        $top = Courier::query()->visibleTo($this->viewer)->where('cash_in_hand', '>', 0)
            ->orderByDesc('cash_in_hand')->limit(8)->get(['id', 'name', 'code', 'cash_in_hand']);

        return [
            'total' => (int) Courier::query()->visibleTo($this->viewer)->where('cash_in_hand', '>', 0)->sum('cash_in_hand'),
            'ages'  => $ages,
            'top'   => $top,
        ];
    }

    /** التحصيلات المتوقَّعة: الشحنات التي في طريقها، بمبالغها ونسبة تسليم محافظتها */
    public function expected(): array
    {
        $rates = $this->rates();
        $open = $this->openShipments();
        $total = 0;
        $weighted = 0;

        foreach ($open as $group) {
            $total += $group['cod'];
            $weighted += $group['cod'] * ($rates['by_governorate'][$group['governorate']] ?? $rates['overall']);
        }

        return ['amount' => $total, 'expected' => (int) round($weighted), 'count' => (int) array_sum(array_column($open, 'count')),
            'rate' => $rates['overall']];
    }

    // ─── التوقّع ─────────────────────────────────────────────────────────

    /** للشركة تاريخٌ يكفي التوقّع: أوّل تسليمٍ قبل ٣٠ يوماً على الأقل */
    public function enoughHistory(): bool
    {
        $first = Shipment::query()->whereNotNull('delivered_at')->min('delivered_at');

        return $first !== null && Carbon::parse($first)->lte(now()->subDays(self::WINDOW));
    }

    /**
     * أربعة عشر يوماً: الداخل (مبالغ الشحنات المفتوحة × احتمال تسليمها × توزيع زمن تسليمها، ثم
     * تأخّر المندوب في تسليم النقد؛ ومعه ما بيد المناديب الآن)، والخارج (دفعات التجّار بدورتهم،
     * ومتوسّط المصروف اليومي)، والرصيد المتوقَّع بنطاقه.
     *
     * @return array{days: list<array<string, mixed>>, assumptions: array<string, mixed>}|null
     */
    public function forecast(): ?array
    {
        if (! $this->enoughHistory()) {
            return null;
        }

        $rates = $this->rates();
        $delivery = $this->pmf($this->deliveryDays());
        $handover = $this->pmf($this->handoverDays());
        $handoverMean = (int) round(array_sum(array_map(fn ($d, $p) => $d * $p, array_keys($handover), $handover)));
        $cycle = max(1, (int) (Tenancy::company()?->setting('finance.merchant_cycle_days', 7) ?: 7));
        $board = new ProfitDashboard($this->viewer);

        $inflow = array_fill(0, self::DAYS, 0.0);
        $merchantShare = array_fill(0, self::DAYS + $cycle, 0.0);

        // الشحنات المفتوحة: ما بقي من توزيع زمن تسليمها بعد عمرها، ثم تأخّر تسليم النقد
        foreach ($this->openShipments() as $group) {
            $p = $rates['by_governorate'][$group['governorate']] ?? $rates['overall'];
            $remaining = array_slice($delivery, $group['age'], null, true);
            $mass = array_sum($remaining);
            $shape = $mass > 0
                ? array_values(array_map(fn ($v) => $v / $mass, $remaining))
                : [1 / 3, 1 / 3, 1 / 3];

            foreach ($shape as $k => $share) {
                $day = $k + $handoverMean;
                if ($day < self::DAYS) {
                    $inflow[$day] += $group['cod'] * $p * $share;
                    $merchantShare[$day] += $group['merchant'] * $p * $share;
                }
            }
        }

        // ما بيد المناديب الآن يصل بتوزيع تأخّر تسليمهم
        $custody = $this->custody()['total'];
        foreach ($handover as $d => $share) {
            if ($d < self::DAYS) {
                $inflow[$d] += $custody * $share;
            }
        }

        $payables = $board->merchantPayables();
        $dailyExpense = (int) round(max(0, (int) JournalLine::query()->toBase()->where('account', A::OPERATING_EXPENSES)
            ->where('entry_date', '>=', now()->subDays(60)->toDateString())->sum(DB::raw('debit - credit'))) / 60);
        $cash = (int) CashBox::active()->visibleTo($this->viewer)->sum('balance');
        $low = $rates['low_factor'];
        $high = $rates['high_factor'];

        $days = [];
        $balance = ['mid' => $cash, 'low' => $cash, 'high' => $cash];
        for ($d = 0; $d < self::DAYS; $d++) {
            // المستحقّ للتجّار الآن يُدفع على دورتهم بالتساوي، وحصّتهم ممّا يدخل تُدفع بعدها بدورة
            $due = $d < $cycle ? $payables['now'] / $cycle : 0;
            $share = $d >= $cycle ? $merchantShare[$d - $cycle] : 0;
            $merchants = $due + $share;
            $in = $inflow[$d];

            // النطاق يمسّ ما يتوقّف على التسليم وحده: الداخل وحصّة التجّار منه، لا المستحقّ الآن ولا المصروف
            foreach (['mid' => 1, 'low' => $low, 'high' => $high] as $key => $factor) {
                $balance[$key] += $in * $factor - $due - $share * $factor - $dailyExpense;
            }

            $days[] = [
                'date'      => now()->addDays($d + 1)->toDateString(),
                'label'     => now()->addDays($d + 1)->format('m/d'),
                'in'        => (int) round($in),
                'in_low'    => (int) round($in * $low),
                'in_high'   => (int) round($in * $high),
                'merchants' => (int) round($merchants),
                'expenses'  => $dailyExpense,
                'net'       => (int) round($in - $merchants - $dailyExpense),
                'balance'   => (int) round($balance['mid']),
                'low'       => (int) round(min($balance['low'], $balance['high'])),
                'high'      => (int) round(max($balance['low'], $balance['high'])),
            ];
        }

        return ['days' => $days, 'assumptions' => [
            'rate'          => $rates['overall'],
            'governorates'  => $rates['named'],
            'low_factor'    => $low,
            'high_factor'   => $high,
            'delivery_mean' => round(array_sum(array_map(fn ($d, $p) => $d * $p, array_keys($delivery), $delivery)), 1),
            'handover_mean' => $handoverMean,
            'cycle'         => $cycle,
            'daily_expense' => $dailyExpense,
            'start_cash'    => $cash,
        ]];
    }

    /**
     * نسبة التسليم في آخر ٣٠ يوماً: المسلَّم ÷ (المسلَّم + الراجع)، للشركة ولكل محافظةٍ لها عشرٌ
     * فأكثر، وتذبذبها اليومي (المئين ١٠ و٩٠) معاملاً للنطاق.
     */
    public function rates(): array
    {
        $from = now()->subDays(self::WINDOW)->startOfDay();

        $delivered = Shipment::query()->toBase()->whereNotNull('delivered_at')->where('delivered_at', '>=', $from)
            ->selectRaw('governorate_id, date(delivered_at) as day, count(*) as n')->groupBy('governorate_id', DB::raw('date(delivered_at)'))->get();
        $returned = Shipment::query()->toBase()->where('status', 'returned')->whereNull('delivered_at')->where('status_changed_at', '>=', $from)
            ->selectRaw('governorate_id, date(status_changed_at) as day, count(*) as n')->groupBy('governorate_id', DB::raw('date(status_changed_at)'))->get();

        $byGov = [];
        $byDay = [];
        foreach ([[$delivered, 'd'], [$returned, 'r']] as [$rows, $kind]) {
            foreach ($rows as $row) {
                $byGov[$row->governorate_id][$kind] = ($byGov[$row->governorate_id][$kind] ?? 0) + (int) $row->n;
                $byDay[substr((string) $row->day, 0, 10)][$kind] = ($byDay[substr((string) $row->day, 0, 10)][$kind] ?? 0) + (int) $row->n;
            }
        }

        $d = array_sum(array_column($byGov, 'd'));
        $r = array_sum(array_column($byGov, 'r'));
        $overall = $d + $r > 0 ? $d / ($d + $r) : 0.8;

        $rates = [];
        foreach ($byGov as $gov => $n) {
            $closed = ($n['d'] ?? 0) + ($n['r'] ?? 0);
            if ($closed >= 10) {
                $rates[$gov] = ($n['d'] ?? 0) / $closed;
            }
        }

        $daily = array_values(array_filter(array_map(fn ($n) => (($n['d'] ?? 0) + ($n['r'] ?? 0)) >= 3
            ? ($n['d'] ?? 0) / (($n['d'] ?? 0) + ($n['r'] ?? 0)) : null, $byDay), fn ($v) => $v !== null));
        sort($daily);
        $pct = fn (float $q) => $daily ? $daily[(int) floor($q * (count($daily) - 1))] : $overall;

        $names = Governorate::whereKey(array_keys($rates))->pluck('name_ar', 'id');

        return [
            'overall'        => round($overall, 3),
            'by_governorate' => $rates,
            'named'          => collect($rates)->mapWithKeys(fn ($v, $id) => [$names[$id] ?? (string) $id => round($v * 100)])->sortDesc()->take(6)->all(),
            'low_factor'     => $overall > 0 ? round(min(1, $pct(0.1) / $overall), 3) : 1,
            'high_factor'    => $overall > 0 ? round(max(1, $pct(0.9) / $overall), 3) : 1,
        ];
    }

    /**
     * الشحنات التي في طريقها (استُلمت من التاجر ولم تُحسم)، بمحافظتها وعمرها منذ الاستلام.
     *
     * @return list<array{governorate: int, age: int, cod: int, merchant: int, count: int}>
     */
    public function openShipments(): array
    {
        $today = now()->startOfDay();

        return Shipment::query()->visibleTo($this->viewer)->toBase()
            ->whereIn('shipments.status', DeliveryDeadline::onTheWayValues())->whereNull('shipments.delivered_at')
            ->where('shipments.cod_amount', '>', 0)
            ->selectRaw('shipments.governorate_id as gov, date(coalesce(shipments.picked_up_at, shipments.created_at)) as day,
                count(*) as n, sum(shipments.cod_amount) as cod,
                sum(case when shipments.merchant_due > 0 then shipments.merchant_due else 0 end) as merchant')
            ->groupBy('shipments.governorate_id', DB::raw('date(coalesce(shipments.picked_up_at, shipments.created_at))'))
            ->get()
            ->map(fn ($r) => ['governorate' => (int) $r->gov, 'count' => (int) $r->n, 'cod' => (int) $r->cod, 'merchant' => (int) $r->merchant,
                'age' => min(self::DAYS, (int) Carbon::parse($r->day)->startOfDay()->diffInDays($today))])
            ->all();
    }

    /** @return array<int, int> أيامٌ من الاستلام إلى التسليم ⇐ عدد الشحنات، في آخر ٣٠ يوماً */
    public function deliveryDays(): array
    {
        $rows = Shipment::query()->toBase()->whereNotNull('delivered_at')->whereNotNull('picked_up_at')
            ->where('delivered_at', '>=', now()->subDays(self::WINDOW)->startOfDay())
            ->selectRaw('date(picked_up_at) as a, date(delivered_at) as b, count(*) as n')
            ->groupBy(DB::raw('date(picked_up_at)'), DB::raw('date(delivered_at)'))->get();

        return $this->histogram($rows);
    }

    /** @return array<int, int> أيامٌ من التسليم إلى إقفال كشف المندوب ⇐ عدد الشحنات */
    public function handoverDays(): array
    {
        $rows = Shipment::query()->toBase()
            ->join('courier_settlements as cs', 'cs.id', '=', 'shipments.courier_settlement_id')
            ->whereNotNull('shipments.delivered_at')->whereNotNull('cs.confirmed_at')
            ->where('cs.confirmed_at', '>=', now()->subDays(self::WINDOW)->startOfDay())
            ->selectRaw('date(shipments.delivered_at) as a, date(cs.confirmed_at) as b, count(*) as n')
            ->groupBy(DB::raw('date(shipments.delivered_at)'), DB::raw('date(cs.confirmed_at)'))->get();

        return $this->histogram($rows);
    }

    /** @return array<int, int> */
    private function histogram($rows): array
    {
        $counts = array_fill(0, self::DAYS + 1, 0);
        foreach ($rows as $row) {
            $days = max(0, min(self::DAYS, (int) Carbon::parse($row->a)->startOfDay()->diffInDays(Carbon::parse($row->b)->startOfDay())));
            $counts[$days] += (int) $row->n;
        }

        return $counts;
    }

    /** @return array<int, float> توزيعٌ احتماليّ؛ وبلا بياناتٍ: اليوم التالي */
    private function pmf(array $counts): array
    {
        $total = array_sum($counts);

        return $total > 0 ? array_map(fn ($n) => $n / $total, $counts) : [0, 1.0];
    }
}

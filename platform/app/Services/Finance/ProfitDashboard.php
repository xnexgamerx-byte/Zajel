<?php

namespace App\Services\Finance;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchItem;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\JournalLine;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Support\DeliveryDeadline;
use App\Support\Finance\Accounts as A;
use App\Support\MerchantBalance;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «لوحة الأرباح» (docs/plan/68 §٥، المرحلة الثالثة): البطاقات التسع من دفتر القيود ومن
 * مصادرها، كلٌّ بمعادلته وأجزائه («كيف حُسب»)، ومقارنةٌ بالمدّة السابقة، ورسومٌ وجداول.
 *
 * النطاق: الشركة، أو فرعٌ (فرع إنشاء الشحنة لإيرادها، وفرع السطر لغيره)، أو مندوبٌ (شحناته
 * وسطوره)، أو تاجر. وما لا يُقسَم على النطاق — المصروفات العامّة على مندوب، والسحب على
 * فرع — لا يُخترع له رقم: يُقال إنه لا ينطبق.
 *
 * بطاقات المدّة (أ–هـ) تُحسب في المدّة ومقابلها السابقة بطولها؛ وبطاقات الحال (و–ط) الآن.
 */
final class ProfitDashboard
{
    /** حسابات الإيراد والتكلفة كما تدخل صافي الربح (د) */
    public const REVENUE = [A::DELIVERY_REVENUE, A::RETURN_REVENUE, A::OTHER_INCOME, A::COUNT_DIFFERENCES];

    public const COSTS = [A::COURIER_COMMISSIONS, A::PICKUP_SHARES, A::OPERATING_EXPENSES, A::WRITE_OFFS];

    /** حدث دفع العمولة في الدفتر (نوع الحساب:الصنف) — يُبنى جزأين فلا يُقرأ «علاقة:أعمدة» */
    public const COMMISSION_PAID = 'courier'.':commission_paid';

    /** أعمار غير المحصَّل بالأيام */
    public const AGES = ['0_7' => '٠–٧ أيام', '8_30' => '٨–٣٠ يوماً', '31' => 'أكثر من ٣٠ يوماً'];

    /** @var array{branch: ?int, courier: ?int, merchant: ?int} */
    private array $scope;

    public function __construct(private ?User $viewer = null, array $scope = [])
    {
        $branch = $viewer?->isBranchLimited() ? (int) $viewer->branch_id : ($scope['branch'] ?? null);

        $this->scope = [
            'branch'   => $branch ?: null,
            'courier'  => ($scope['courier'] ?? null) ?: null,
            'merchant' => ($scope['merchant'] ?? null) ?: null,
        ];
    }

    /** @return array{branch: ?int, courier: ?int, merchant: ?int} */
    public function scope(): array
    {
        return $this->scope;
    }

    /** نطاق الشركة كلّها: لا فرع ولا مندوب ولا تاجر */
    public function companyWide(): bool
    {
        return ! array_filter($this->scope);
    }

    /** الدفتر مبنيٌّ لهذه الشركة: وإلّا فالأرقام ناقصة حتى يُبنى تاريخها */
    public function initialized(): bool
    {
        return DB::table('journal_heads')->where('company_id', Tenancy::id())->whereNotNull('initialized_at')->exists()
            || ! DB::table('transactions')->where('company_id', Tenancy::id())->exists();
    }

    // ─── سطور الدفتر ──────────────────────────────────────────────────────

    /** سطور الدفتر في النطاق، ومدّةٍ إن طُلبت (عمود تاريخٍ لا وقت: يُقارن نصّاً) */
    public function lines(?Carbon $from = null, ?Carbon $to = null): QueryBuilder
    {
        $s = $this->scope;

        return JournalLine::query()->toBase()
            ->when($from, fn ($q) => $q->where('journal_lines.entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('journal_lines.entry_date', '<=', $to->toDateString()))
            ->when($s['branch'], fn ($q, $id) => $q->where('journal_lines.branch_id', $id))
            ->when($s['merchant'], fn ($q, $id) => $q->where('journal_lines.merchant_id', $id))
            // للمندوب: سطوره، وسطور شحناته التي لا تحمل مندوباً (إيرادها)
            ->when($s['courier'], fn ($q, $id) => $q->where(fn ($w) => $w->where('journal_lines.courier_id', $id)
                ->orWhere(fn ($x) => $x->whereNull('journal_lines.courier_id')
                    ->whereIn('journal_lines.shipment_id', Shipment::withTrashed()->where('delivery_courier_id', $id)->select('id')))));
    }

    /** مدين − دائن لحساباتٍ في مدّة */
    public function net(array $accounts, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return (int) $this->lines($from, $to)->whereIn('journal_lines.account', $accounts)
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
    }

    /** @return array<string, int> مدين − دائن لكل حساب */
    public function netByAccount(array $accounts, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = $this->lines($from, $to)->whereIn('journal_lines.account', $accounts)
            ->groupBy('journal_lines.account')
            ->selectRaw('journal_lines.account as account, sum(journal_lines.debit - journal_lines.credit) as v')
            ->pluck('v', 'account');

        return collect($accounts)->mapWithKeys(fn ($a) => [$a => (int) ($rows[$a] ?? 0)])->all();
    }

    // ─── الشحنات في النطاق ─────────────────────────────────────────────────

    /** @return Builder<Shipment> */
    public function shipments(): Builder
    {
        $s = $this->scope;

        return Shipment::query()
            ->when($s['branch'], fn ($q, $id) => $q->where('shipments.branch_id', $id))
            ->when($s['courier'], fn ($q, $id) => $q->where('shipments.delivery_courier_id', $id))
            ->when($s['merchant'], fn ($q, $id) => $q->where('shipments.merchant_id', $id));
    }

    /** أجر الشحنة الذي جاء نقداً مع المحصَّل: المحصَّل ناقص ما منه للتاجر */
    public static function sqlCashFees(): string
    {
        return '(shipments.collected_amount - case when shipments.merchant_due > 0 then shipments.merchant_due else 0 end)';
    }

    // ─── البطاقات ─────────────────────────────────────────────────────────

    /** أ. المسلَّمة في المدّة، والراجع المغلق بجانبها */
    public function delivered(ReportPeriod $p): array
    {
        $delivered = (clone $this->shipments())->whereFromDate('delivered_at', $p->from)->whereUntilDate('delivered_at', $p->to)->count();
        $returned = (clone $this->shipments())->where('shipments.status', 'returned')->whereNull('delivered_at')
            ->whereFromDate('status_changed_at', $p->from)->whereUntilDate('status_changed_at', $p->to)->count();

        return ['delivered' => $delivered, 'returned' => $returned];
    }

    /** ب. إيراد الأجور: دائن 4100 و4110 في المدّة */
    public function revenue(ReportPeriod $p): array
    {
        $by = $this->netByAccount([A::DELIVERY_REVENUE, A::RETURN_REVENUE], $p->from, $p->to);

        return ['delivery' => -$by[A::DELIVERY_REVENUE], 'returns' => -$by[A::RETURN_REVENUE],
            'total' => -$by[A::DELIVERY_REVENUE] - $by[A::RETURN_REVENUE]];
    }

    /** ج. التكاليف: مباشرة (عمولات وحصص)، وغير مباشرة (المصروفات بتاريخها)، وخسائر (المشطوب) */
    public function costs(ReportPeriod $p): array
    {
        $by = $this->netByAccount(self::COSTS, $p->from, $p->to);

        return ['commissions' => $by[A::COURIER_COMMISSIONS], 'pickup' => $by[A::PICKUP_SHARES],
            'expenses' => $by[A::OPERATING_EXPENSES], 'write_offs' => $by[A::WRITE_OFFS], 'total' => array_sum($by)];
    }

    /** د. صافي الربح المحاسبي: كل الإيرادات ناقص كل التكاليف، قُبض أم لم يُقبض */
    public function netProfit(ReportPeriod $p): array
    {
        $rev = $this->netByAccount(self::REVENUE, $p->from, $p->to);
        $costs = (int) $this->net(self::COSTS, $p->from, $p->to);
        $fees = -$rev[A::DELIVERY_REVENUE] - $rev[A::RETURN_REVENUE];

        return [
            'fees'        => $fees,
            'other'       => -$rev[A::OTHER_INCOME],
            'differences' => -$rev[A::COUNT_DIFFERENCES],
            'costs'       => $costs,
            'total'       => -array_sum($rev) - $costs,
        ];
    }

    /**
     * هـ. الربح المحصَّل نقداً في المدّة: أجورٌ وصل نقدها وطوبق، ناقص تكاليف دُفعت نقداً.
     *
     * - أجرٌ دفعه الزبون: عند اعتماد الوجبة، ناقصاً عجزها (realized_fees على سطرها)، ومعه تحصيل العجز.
     * - أجرٌ على التاجر أو أجرة راجع: عند دفع كشف التاجر الذي اقتُطعت منه (ما زاد على ما حُصِّل).
     * - أجرٌ مدفوعٌ مقدّماً: حين يُكتسب (يخرج من «أجور مقبوضة مقدّماً»).
     * - التكاليف: العمولات والحصص حين تُدفع، والمصروف حين يُدفع من صندوق.
     */
    public function realized(?Carbon $from, ?Carbon $to): array
    {
        $s = $this->scope;

        $batches = $s['merchant'] ? 0 : (int) CollectionBatchItem::query()
            ->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_items.collection_batch_id')
            ->where('collection_batches.status', 'approved')
            ->when($from, fn ($q) => $q->whereFromDate('collection_batches.approved_at', $from))
            ->when($to, fn ($q) => $q->whereUntilDate('collection_batches.approved_at', $to))
            ->when($s['courier'], fn ($q, $id) => $q->where('collection_batch_items.courier_id', $id))
            ->when($s['branch'], fn ($q, $id) => $q->whereIn('collection_batch_items.courier_id', Courier::withTrashed()->where('branch_id', $id)->select('id')))
            ->sum('collection_batch_items.realized_fees');

        $merchants = (int) DB::table('merchant_settlement_shipments as l')
            ->join('merchant_settlements as m', 'm.id', '=', 'l.merchant_settlement_id')
            ->join('shipments', 'shipments.id', '=', 'l.shipment_id')
            ->where('m.company_id', Tenancy::id())
            ->where('m.status', 'paid')
            ->when($from, fn ($q) => $q->whereFromDate('m.paid_at', $from))
            ->when($to, fn ($q) => $q->whereUntilDate('m.paid_at', $to))
            ->when($s['merchant'], fn ($q, $id) => $q->where('m.merchant_id', $id))
            ->when($s['branch'], fn ($q, $id) => $q->where('shipments.branch_id', $id))
            ->when($s['courier'], fn ($q, $id) => $q->where('shipments.delivery_courier_id', $id))
            ->where('l.net_amount', '<', 0)
            ->sum(DB::raw('-l.net_amount'));

        $prepaid = (int) $this->lines($from, $to)->where('journal_lines.account', A::PREPAID)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', 'transaction')
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));

        $commissions = (int) $this->lines($from, $to)->where('journal_lines.account', A::COMMISSIONS)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.event', self::COMMISSION_PAID)
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));

        $expenses = (int) $this->lines($from, $to)->where('journal_lines.account', A::EXPENSES_DUE)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', 'cash_movement')
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));

        $fees = $batches + $merchants + $prepaid;

        return [
            'batches'     => $batches,
            'merchants'   => $merchants,
            'prepaid'     => $prepaid,
            'fees'        => $fees,
            'commissions' => $commissions,
            'expenses'    => $expenses,
            'total'       => $fees - $commissions - $expenses,
        ];
    }

    /**
     * و. الأجور التي لم تُحصَّل بعد، بمكانها وعمرها:
     * عند المناديب (مسلَّمٌ لم يُقفَل كشفه)، وفي كشوفٍ ووجباتٍ لم تُعتمد، وعلى التجّار، وعجز المناديب.
     */
    public function unrealized(): array
    {
        $now = now();
        $bounds = [$now->copy()->subDays(7)->startOfDay(), $now->copy()->subDays(30)->startOfDay()];

        // ١. بيد المناديب: واصلٌ مع مندوبٍ لم يدخل كشفاً مُقفَلاً
        $couriers = $this->aged((clone $this->shipments())->whereNotNull('shipments.delivered_at')
            ->whereNotNull('shipments.delivery_courier_id')->whereNull('shipments.courier_settlement_id')
            ->toBase(), 'shipments.delivered_at', self::sqlCashFees(), $bounds);

        // ٢. في كشوفٍ مُقفَلة لم تدخل وجبةً معتمدة
        $approvedSettlements = CollectionBatchItem::query()
            ->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_items.collection_batch_id')
            ->where('collection_batches.status', 'approved')->whereNotNull('collection_batch_items.courier_settlement_id')
            ->select('collection_batch_items.courier_settlement_id');
        $batches = $this->aged((clone $this->shipments())->toBase()
            ->join('courier_settlement_shipments as cl', 'cl.shipment_id', '=', 'shipments.id')
            ->join('courier_settlements as cs', 'cs.id', '=', 'cl.courier_settlement_id')
            ->whereIn('cs.status', ['confirmed', 'paid'])->whereNull('cs.cancelled_at')
            ->whereNotIn('cs.id', $approvedSettlements), 'cs.confirmed_at', self::sqlCashFees(), $bounds);

        // ٣. على التجّار: أجورٌ تزيد على ما حُصِّل، لم يُدفع كشفها بعد
        $merchants = $this->aged((clone $this->shipments())->toBase()
            ->where('shipments.merchant_due', '<', 0)
            ->where(fn ($q) => $q->whereNotNull('shipments.delivered_at')->orWhere('shipments.status', 'returned'))
            ->where(fn ($q) => $q->whereNull('shipments.merchant_settlement_id')
                ->orWhereIn('shipments.merchant_settlement_id', MerchantSettlement::query()->where('status', '!=', 'paid')->select('id'))),
            'shipments.status_changed_at', '-shipments.merchant_due', $bounds);

        // ٤. عجزٌ على المناديب لم يُسترد: رصيد 1250
        $shortages = $this->scope['merchant'] ? 0 : $this->net([A::SHORTAGES]);

        $places = [
            'couriers'  => $couriers,
            'batches'   => $batches,
            'merchants' => $merchants,
            'shortages' => ['total' => $shortages, '0_7' => 0, '8_30' => 0, '31' => 0, 'aged' => false],
        ];

        return $places + ['total' => array_sum(array_column($places, 'total'))];
    }

    /** مجموعٌ بأعمار ٠–٧ و٨–٣٠ وأكثر من ٣٠ يوماً */
    private function aged(QueryBuilder $q, string $col, string $value, array $bounds): array
    {
        [$week, $month] = $bounds;
        $r = $q->selectRaw("coalesce(sum({$value}), 0) as total,
                coalesce(sum(case when {$col} >= ? then {$value} else 0 end), 0) as a,
                coalesce(sum(case when {$col} < ? and {$col} >= ? then {$value} else 0 end), 0) as b,
                coalesce(sum(case when {$col} < ? then {$value} else 0 end), 0) as c", [$week, $week, $month, $month])
            ->first();

        return ['total' => (int) $r->total, '0_7' => (int) $r->a, '8_30' => (int) $r->b, '31' => (int) $r->c, 'aged' => true];
    }

    /** ز. الأرباح المحتجزة للشركة: كل ما رُبح منذ البداية ناقص ما سُحب، ومنها ما يدعمه نقدٌ حرّ */
    public function retained(int $freeCash): array
    {
        $earned = -$this->net(self::REVENUE) - $this->net(self::COSTS);
        $drawn = $this->net([A::DRAWINGS]);
        $retained = $earned - $drawn;
        $backed = max(0, min($retained, $freeCash));

        return ['earned' => $earned, 'drawn' => $drawn, 'total' => $retained, 'backed' => $backed, 'unbacked' => $retained - $backed];
    }

    /**
     * ح. المتاح للسحب: الأصغر من النقد الحرّ والمحصَّل نقداً المتراكم بعد المسحوب، ولا يقلّ عن صفر.
     * والنقد الحرّ: الصناديق ناقص كل ما فيها لغير الشركة، وناقص الاحتياطي.
     */
    public function available(): array
    {
        $balance = fn (string $a) => $this->net([$a]);

        $cash = $balance(A::CASH);
        $payables = (int) Merchant::query()->where('balance', '>', 0)->sum('balance');
        // أموال التجّار التي ما زالت بيد المناديب ليست في الصناديق: لا تُطرح منها مرّتين
        $withCouriers = (int) Shipment::query()->whereNotNull('delivered_at')->whereNotNull('delivery_courier_id')
            ->whereNull('courier_settlement_id')->where('merchant_due', '>', 0)->sum('merchant_due');
        $merchantsInBoxes = max(0, $payables - $withCouriers);
        $deposits = -$balance(A::DEPOSITS);
        $prepaid = -$balance(A::PREPAID);
        $expensesDue = -$balance(A::EXPENSES_DUE);
        $pickupDue = (int) Courier::query()->where('type', 'pickup')->where('commission_balance', '>', 0)->sum('commission_balance');
        $excess = -$balance(A::EXCESS_CASH);
        $reserve = $this->reserve();

        $free = $cash - $merchantsInBoxes - max(0, $deposits) - max(0, $prepaid) - max(0, $expensesDue) - $pickupDue - max(0, $excess) - $reserve;
        $realized = $this->realized(null, null)['total'];
        $drawn = $balance(A::DRAWINGS);

        return [
            'cash' => $cash, 'merchants' => $merchantsInBoxes, 'payables' => $payables, 'with_couriers' => $withCouriers,
            'deposits' => max(0, $deposits), 'prepaid' => max(0, $prepaid), 'expenses_due' => max(0, $expensesDue),
            'pickup_due' => $pickupDue, 'excess' => max(0, $excess), 'reserve' => $reserve,
            'free' => $free, 'realized' => $realized, 'drawn' => $drawn,
            'total' => max(0, min($free, $realized - $drawn)),
        ];
    }

    /** الاحتياطي التشغيلي (ق٨): مبلغٌ ثابت، أو أيامٌ من متوسّط المصروف اليومي في ٩٠ يوماً — الأكبر */
    public function reserve(): int
    {
        $company = Tenancy::company();
        $fixed = max(0, (int) $company?->setting('finance.reserve_fixed', 0));
        $days = max(0, (int) $company?->setting('finance.reserve_days', 0));

        if ($days === 0) {
            return $fixed;
        }

        $spent = (int) JournalLine::query()->toBase()->where('account', A::OPERATING_EXPENSES)
            ->where('entry_date', '>=', now()->subDays(90)->toDateString())->sum(DB::raw('debit - credit'));

        return max($fixed, (int) round($spent / 90 * $days));
    }

    /**
     * ط. مستحقّات التجّار: مستحقٌّ الآن (نقده عندنا)، ومنه المتأخّر عن دورة التسوية (ق٩)،
     * ومستقبليٌّ نقده ما زال عند المناديب.
     */
    public function merchantPayables(): array
    {
        $cycle = max(1, (int) (Tenancy::company()?->setting('finance.merchant_cycle_days', 7) ?: 7));
        $cutoff = now()->subDays($cycle);
        $base = fn () => clone $this->shipments();

        $ready = BuildMerchantSettlement::eligibleScope($base());
        $confirmed = MerchantSettlement::query()->where('status', 'confirmed')
            ->when($this->scope['merchant'], fn ($q, $id) => $q->where('merchant_id', $id))
            ->when($this->scope['branch'], fn ($q, $id) => $q->where('branch_id', $id));

        $now = (int) (clone $ready)->sum('merchant_due') + (int) (clone $confirmed)->sum('net_amount');
        $late = (int) (clone $ready)->where(fn ($q) => $q->where('delivered_at', '<', $cutoff)
                ->orWhere(fn ($r) => $r->whereNull('delivered_at')->where('status_changed_at', '<', $cutoff)))->sum('merchant_due')
            + (int) (clone $confirmed)->where('confirmed_at', '<', $cutoff)->sum('net_amount');
        $future = (int) MerchantBalance::awaitingCourier($base())->whereNull('merchant_settlement_id')->sum('merchant_due');

        return ['now' => $now, 'late' => $late, 'future' => $future, 'cycle' => $cycle, 'total' => $now + $future];
    }

    // ─── الرسوم والجداول ───────────────────────────────────────────────────

    /**
     * الرسم ١: الإيراد والتكلفة والصافي يوماً بيوم (أو أسبوعاً بأسبوع لما زاد على ٣١ يوماً)،
     * والصافي للمدّة السابقة بالموضع نفسه خطّاً شبحياً.
     *
     * @return list<array{from: string, to: string, label: string, revenue: int, costs: int, net: int, previous: int}>
     */
    public function series(ReportPeriod $p, ReportPeriod $previous): array
    {
        $weekly = $p->days() > 31;
        $buckets = [];
        for ($d = $p->from->copy()->startOfDay(), $i = 0; $d->lte($p->to); $d->addDays($weekly ? 7 : 1), $i++) {
            $end = $weekly ? $d->copy()->addDays(6)->min($p->to->copy()->startOfDay()) : $d->copy();
            $buckets[] = ['from' => $d->toDateString(), 'to' => $end->toDateString(),
                'label' => $weekly ? $d->format('m/d').'–'.$end->format('m/d') : $d->format('m/d'),
                'revenue' => 0, 'costs' => 0, 'net' => 0, 'previous' => 0];
        }

        $daily = fn (ReportPeriod $range) => $this->lines($range->from, $range->to)
            ->whereIn('journal_lines.account', [...self::REVENUE, ...self::COSTS])
            ->groupBy('journal_lines.entry_date')
            ->selectRaw('journal_lines.entry_date as day,
                sum(case when journal_lines.account in (\''.implode("','", self::REVENUE).'\') then journal_lines.credit - journal_lines.debit else 0 end) as revenue,
                sum(case when journal_lines.account in (\''.implode("','", self::COSTS).'\') then journal_lines.debit - journal_lines.credit else 0 end) as costs')
            ->get();

        $index = fn (string $day, Carbon $start) => intdiv((int) $start->copy()->startOfDay()->diffInDays(Carbon::parse(substr($day, 0, 10))), $weekly ? 7 : 1);

        foreach ($daily($p) as $row) {
            $i = $index((string) $row->day, $p->from);
            if (isset($buckets[$i])) {
                $buckets[$i]['revenue'] += (int) $row->revenue;
                $buckets[$i]['costs'] += (int) $row->costs;
                $buckets[$i]['net'] += (int) $row->revenue - (int) $row->costs;
            }
        }

        foreach ($daily($previous) as $row) {
            $i = $index((string) $row->day, $previous->from);
            if (isset($buckets[$i])) {
                $buckets[$i]['previous'] += (int) $row->revenue - (int) $row->costs;
            }
        }

        return $buckets;
    }

    /**
     * الرسم ٤: نقدٌ عُدَّ في وجباتٍ اعتُمدت، يوماً بيوم.
     *
     * @return list<array{day: string, label: string, cash: int, batches: int}>
     */
    public function collections(ReportPeriod $p): array
    {
        $rows = CollectionBatch::query()->visibleTo($this->viewer)->where('status', 'approved')
            ->whereFromDate('approved_at', $p->from)->whereUntilDate('approved_at', $p->to)
            ->when($this->scope['branch'], fn ($q, $id) => $q->where('branch_id', $id))
            ->when($this->scope['courier'], fn ($q, $id) => $q->whereHas('items', fn ($i) => $i->where('courier_id', $id)))
            ->get(['id', 'approved_at', 'received_cash'])
            ->groupBy(fn ($b) => $b->approved_at->toDateString());

        $days = [];
        for ($d = $p->from->copy()->startOfDay(); $d->lte($p->to); $d->addDay()) {
            $group = $rows[$d->toDateString()] ?? collect();
            $days[] = ['day' => $d->toDateString(), 'label' => $d->format('m/d'),
                'cash' => (int) $group->sum('received_cash'), 'batches' => $group->count()];
        }

        return $days;
    }

    /** الشحنة سُلِّمت في مهلة التوصيل من استلامها (DeliveryDeadline)، بلغة قاعدة البيانات */
    public static function sqlOnTime(): string
    {
        $minutes = DeliveryDeadline::hours() * 60;

        return DB::getDriverName() === 'sqlite'
            ? "(shipments.picked_up_at is not null and (julianday(shipments.delivered_at) - julianday(shipments.picked_up_at)) * 1440 <= {$minutes})"
            : "(shipments.picked_up_at is not null and timestampdiff(minute, shipments.picked_up_at, shipments.delivered_at) <= {$minutes})";
    }

    /** المسلَّمة في المدّة، ومنها ما سُلِّم في الموعد */
    public function punctuality(ReportPeriod $p): array
    {
        $r = (clone $this->shipments())->toBase()
            ->whereFromDate('shipments.delivered_at', $p->from)->whereUntilDate('shipments.delivered_at', $p->to)
            ->selectRaw('count(*) as n, coalesce(sum(case when '.self::sqlOnTime().' then 1 else 0 end), 0) as on_time')
            ->first();

        return ['delivered' => (int) $r->n, 'on_time' => (int) $r->on_time,
            'ratio' => $r->n ? round($r->on_time / $r->n * 100, 1) : null];
    }

    public const RANKS = [
        'contribution' => 'المساهمة',
        'delivered'    => 'المسلَّمة',
        'on_time'      => 'الالتزام بالموعد',
        'discrepancy'  => 'الفروقات',
    ];

    /**
     * «أفضل المناديب» في المدّة: مساهمته (إيراد شحناته ناقص عمولاته)، والمسلَّمة، والالتزام
     * بالموعد، وفرق وجباته المعتمدة فيها.
     */
    public function couriers(ReportPeriod $p, string $rank = 'contribution', int $limit = 10): Collection
    {
        $s = $this->scope;

        $work = Shipment::query()->toBase()
            ->whereNotNull('shipments.delivery_courier_id')
            ->whereFromDate('shipments.delivered_at', $p->from)->whereUntilDate('shipments.delivered_at', $p->to)
            ->when($s['branch'], fn ($q, $id) => $q->where('shipments.branch_id', $id))
            ->when($s['merchant'], fn ($q, $id) => $q->where('shipments.merchant_id', $id))
            ->when($s['courier'], fn ($q, $id) => $q->where('shipments.delivery_courier_id', $id))
            ->groupBy('shipments.delivery_courier_id')
            ->selectRaw('shipments.delivery_courier_id as courier_id, count(*) as delivered,
                sum(case when '.self::sqlOnTime().' then 1 else 0 end) as on_time')
            ->get()->keyBy('courier_id');

        // إيراد شحناته في المدّة من الدفتر، وعمولاته من سطورها
        $revenue = $this->lines($p->from, $p->to)
            ->whereIn('journal_lines.account', [A::DELIVERY_REVENUE, A::RETURN_REVENUE])
            ->join('shipments', 'shipments.id', '=', 'journal_lines.shipment_id')
            ->whereNotNull('shipments.delivery_courier_id')
            ->groupBy('shipments.delivery_courier_id')
            ->selectRaw('shipments.delivery_courier_id as courier_id, sum(journal_lines.credit - journal_lines.debit) as v')
            ->pluck('v', 'courier_id');

        $commissions = $this->lines($p->from, $p->to)
            ->where('journal_lines.account', A::COURIER_COMMISSIONS)->whereNotNull('journal_lines.courier_id')
            ->groupBy('journal_lines.courier_id')
            ->selectRaw('journal_lines.courier_id as courier_id, sum(journal_lines.debit - journal_lines.credit) as v')
            ->pluck('v', 'courier_id');

        $differences = CollectionBatchItem::query()->toBase()
            ->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_items.collection_batch_id')
            ->where('collection_batches.status', 'approved')->where('collection_batch_items.kind', 'settlement')
            ->whereFromDate('collection_batches.approved_at', $p->from)->whereUntilDate('collection_batches.approved_at', $p->to)
            ->groupBy('collection_batch_items.courier_id')
            ->selectRaw('collection_batch_items.courier_id as courier_id,
                sum(collection_batch_items.received_cash - collection_batch_items.expected_cash) as v')
            ->pluck('v', 'courier_id');

        $ids = collect([...$work->keys(), ...$revenue->keys(), ...$commissions->keys()])
            ->map(fn ($id) => (int) $id)->filter()->unique()
            ->when($s['courier'], fn ($c, $id) => $c->filter(fn ($x) => $x === (int) $id));

        $names = Courier::withTrashed()->whereKey($ids->all())->get(['id', 'name', 'code', 'branch_id'])->keyBy('id');

        $rows = $ids->map(function (int $id) use ($work, $revenue, $commissions, $differences, $names) {
            $w = $work[$id] ?? null;
            $delivered = (int) ($w->delivered ?? 0);

            return (object) [
                'id'           => $id,
                'name'         => $names[$id]->name ?? '—',
                'code'         => $names[$id]->code ?? null,
                'delivered'    => $delivered,
                'on_time'      => $delivered ? round((int) $w->on_time / $delivered * 100, 1) : null,
                'revenue'      => (int) ($revenue[$id] ?? 0),
                'commissions'  => (int) ($commissions[$id] ?? 0),
                'contribution' => (int) ($revenue[$id] ?? 0) - (int) ($commissions[$id] ?? 0),
                'discrepancy'  => (int) ($differences[$id] ?? 0),
            ];
        });

        return (match ($rank) {
            'delivered'   => $rows->sortByDesc('delivered'),
            'on_time'     => $rows->filter(fn ($r) => $r->delivered > 0)->sortByDesc('on_time'),
            'discrepancy' => $rows->sortBy('discrepancy'),
            default       => $rows->sortByDesc('contribution'),
        })->take($limit)->values();
    }

    /**
     * مقارنة الفروع: كل فرعٍ بنطاقه، وسطرٌ لما لا يحمل فرعاً حتى يساوي المجموعُ الشركة.
     * والعمولة الداخلية بين الفروع (الفرع الموصِّل) تُعرض منفصلة: تتقاصّ في مجموع الشركة (ق٦).
     */
    public function branches(ReportPeriod $p): Collection
    {
        $branches = Branch::query()
            ->when($this->viewer?->isBranchLimited(), fn ($q) => $q->whereKey($this->viewer->branch_id))
            ->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']);

        $internal = Shipment::query()->toBase()
            ->whereFromDate('delivered_at', $p->from)->whereUntilDate('delivered_at', $p->to)
            ->whereNotNull('delivery_branch_id')->whereColumn('delivery_branch_id', '!=', 'branch_id')
            ->where('branch_commission', '>', 0)
            ->selectRaw('branch_id, delivery_branch_id, sum(branch_commission) as v')
            ->groupBy('branch_id', 'delivery_branch_id')->get();

        $rows = $branches->map(function (Branch $branch) use ($p, $internal) {
            $one = new self($this->viewer, ['branch' => $branch->id]);
            $net = $one->netProfit($p);
            $punctual = $one->punctuality($p);
            $unrealized = $one->unrealized();

            return (object) [
                'id'           => $branch->id,
                'name'         => $branch->name,
                'delivered'    => $punctual['delivered'],
                'on_time'      => $punctual['ratio'],
                'revenue'      => $net['fees'],
                'other'        => $net['other'] + $net['differences'],
                'costs'        => $net['costs'],
                'net'          => $net['total'],
                'internal_in'  => (int) $internal->where('delivery_branch_id', $branch->id)->sum('v'),
                'internal_out' => (int) $internal->where('branch_id', $branch->id)->sum('v'),
                'realized'     => $one->realized($p->from, $p->to)['fees'],
                'unrealized'   => $unrealized['total'],
                'custody'      => (int) Courier::where('branch_id', $branch->id)->where('cash_in_hand', '>', 0)->sum('cash_in_hand'),
                'boxes'        => (int) CashBox::active()->where('branch_id', $branch->id)->sum('balance'),
                'open_shortage' => (int) CollectionBatchItem::query()
                    ->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_items.collection_batch_id')
                    ->whereIn('collection_batches.status', CollectionBatch::OPEN)
                    ->where('collection_batch_items.status', 'short')->whereNull('collection_batch_items.resolution')
                    ->whereIn('collection_batch_items.courier_id', Courier::withTrashed()->where('branch_id', $branch->id)->select('id'))
                    ->sum(DB::raw('collection_batch_items.expected_cash - collection_batch_items.received_cash')),
                'payables'     => (int) Merchant::where('branch_id', $branch->id)->where('balance', '>', 0)->sum('balance'),
                'expenses_due' => max(0, -$one->net([A::EXPENSES_DUE])),
            ];
        });

        // ما لا يحمل فرعاً (سحبٌ، أو مصروفٌ عامّ): سطرٌ ظاهر لا فرقٌ مخفيّ
        if (! $this->viewer?->isBranchLimited()) {
            $company = (new self($this->viewer))->netProfit($p);
            $rest = [
                'revenue' => $company['fees'] - (int) $rows->sum('revenue'),
                'other'   => $company['other'] + $company['differences'] - (int) $rows->sum('other'),
                'costs'   => $company['costs'] - (int) $rows->sum('costs'),
            ];

            if (array_filter($rest)) {
                $rows->push((object) ['id' => null, 'name' => 'بلا فرع', 'delivered' => null, 'on_time' => null,
                    'revenue' => $rest['revenue'], 'other' => $rest['other'], 'costs' => $rest['costs'],
                    'net' => $rest['revenue'] + $rest['other'] - $rest['costs'],
                    'internal_in' => 0, 'internal_out' => 0, 'realized' => null, 'unrealized' => null, 'custody' => null,
                    'boxes' => null, 'open_shortage' => null, 'payables' => null, 'expenses_due' => null]);
            }
        }

        return $rows->values();
    }
}

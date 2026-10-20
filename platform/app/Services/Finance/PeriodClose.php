<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CollectionBatch;
use App\Models\CourierSettlement;
use App\Models\Expense;
use App\Models\FinancialPeriod;
use App\Models\FinancialPeriodReport;
use App\Models\JournalLine;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\SequenceGenerator;
use App\Support\Arabic;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إقفال الفترات (docs/plan/68 §٩).
 *
 * يُعِدّه مخوَّلٌ بمراجعة أرقام الفترة وفحوصها — وما فشل منها يلزمه استثناءٌ موثّق — ثم يعتمده
 * غيرُه إن فُعِّل الاعتماد المزدوج (ق١٠). وبالاعتماد: قيد الإقفال (الإيرادات والتكاليف إلى
 * «أرباح فتراتٍ مُقفَلة» 3200)، والتقرير بنسخته الأولى، ثم لا يُكتب قيدٌ بتاريخ الفترة.
 */
class PeriodClose
{
    /** فحوصٌ لا يتجاوزها استثناء: فترةٌ غير صالحة لا تُقفَل */
    public const BLOCKING = ['range'];

    public function __construct(protected SequenceGenerator $sequences, protected JournalCheck $journalCheck, protected Journal $journal) {}

    /** آخر فترةٍ أُعدّت أو أُقفلت */
    public function last(): ?FinancialPeriod
    {
        return FinancialPeriod::query()->orderByDesc('to_date')->first();
    }

    /** الفترة المقترحة: تبدأ بعد آخر فترة (أو بأوّل قيد)، وتنتهي آخر الشهر الماضي أو أمس */
    public function suggest(): array
    {
        $last = $this->last();
        $first = JournalLine::query()->min('entry_date');
        $from = $last ? $last->to_date->copy()->addDay() : ($first ? Carbon::parse($first)->startOfMonth() : now()->subMonthNoOverflow()->startOfMonth());
        $to = now()->startOfMonth()->subDay();

        if ($to->lt($from)) {
            $to = now()->subDay()->startOfDay();
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    /** أرقام الفترة كلّها، تُحفظ عند الإقفال كما هي */
    public function figures(Carbon $from, Carbon $to): array
    {
        $board = new ProfitDashboard;
        $p = new ReportPeriod($from->copy()->startOfDay(), $to->copy()->endOfDay());
        $available = $board->available();

        return [
            'from'        => $from->toDateString(),
            'to'          => $to->toDateString(),
            'computed_at' => now()->toDateTimeString(),
            'delivered'   => $board->delivered($p),
            'revenue'     => $board->revenue($p),
            'costs'       => $board->costs($p),
            'net'         => $board->netProfit($p),
            'realized'    => $board->realized($p->from, $p->to),
            'unrealized'  => array_map(fn ($place) => is_array($place) ? $place['total'] : $place, $board->unrealized()),
            'drawn'       => $board->net([A::DRAWINGS], $p->from, $p->to),
            'retained'    => $board->retained($available['free']),
            'available'   => $available,
            'payables'    => $board->merchantPayables(),
            'trial'       => $this->trial($from, $to),
        ];
    }

    /** ميزان المراجعة للفترة: افتتاحي وحركة وختامي لكل حساب، بلا قيود الإقفال */
    public function trial(Carbon $from, Carbon $to): array
    {
        $rows = (new ProfitDashboard)->daily(null, $to)
            ->selectRaw('account,
                sum(case when entry_date < ? then debit - credit else 0 end) as opening,
                sum(case when entry_date >= ? then debit else 0 end) as debit,
                sum(case when entry_date >= ? then credit else 0 end) as credit',
                [$from->toDateString(), $from->toDateString(), $from->toDateString()])
            ->groupBy('account')->get();

        return $rows->map(fn ($r) => [
            'account' => $r->account, 'name' => A::name($r->account),
            'opening' => (int) $r->opening, 'debit' => (int) $r->debit, 'credit' => (int) $r->credit,
            'closing' => (int) $r->opening + (int) $r->debit - (int) $r->credit,
        ])->sortBy('account')->values()->all();
    }

    /**
     * الفحوص: كلٌّ {key, label, ok, detail}. ما فشل منها يمنع الإقفال إلّا باستثناءٍ موثّق —
     * عدا صلاحية الفترة نفسها (range).
     *
     * @return list<array{key: string, label: string, ok: bool, detail: string}>
     */
    /** $fresh: الإعداد والاعتماد يفحصان الآن؛ وشاشة المراجعة تقرأ آخر مطابقةٍ محفوظة (JournalCheck::latest) */
    public function checks(Carbon $from, Carbon $to, ?FinancialPeriod $except = null, bool $fresh = true): array
    {
        $checks = [];
        $add = function (string $key, string $label, bool $ok, string $detail) use (&$checks) {
            $checks[] = compact('key', 'label', 'ok', 'detail');
        };

        // الفترة: تبدأ بعد آخر فترةٍ بلا فجوةٍ ولا تداخل، وتنتهي قبل اليوم
        $last = FinancialPeriod::query()->when($except, fn ($q) => $q->whereKeyNot($except->id))->orderByDesc('to_date')->first();
        $expected = $last?->to_date->copy()->addDay();
        $rangeOk = $from->lte($to) && $to->lt(now()->startOfDay()) && (! $expected || $from->isSameDay($expected));
        $add('range', 'الفترة صالحة', $rangeOk, match (true) {
            $from->gt($to)                                  => 'البداية بعد النهاية.',
            ! $to->lt(now()->startOfDay())                  => 'لا تُقفَل فترةٌ تنتهي اليوم أو بعده.',
            $expected && ! $from->isSameDay($expected)      => 'تبدأ الفترة التالية '.$expected->toDateString().' — بعد آخر فترةٍ بلا فجوةٍ ولا تداخل.',
            default                                         => 'تبدأ بعد آخر فترةٍ مُقفَلة، وتنتهي قبل اليوم.',
        });

        $batches = CollectionBatch::query()->whereIn('status', CollectionBatch::OPEN)
            ->whereUntilDate('created_at', $to->toDateString())->count();
        $add('batches', 'لا وجبات تحصيلٍ مفتوحة في الفترة', $batches === 0,
            $batches ? Arabic::count($batches, ['وجبةٌ واحدة', 'وجبتان', 'وجبات', 'وجبة']).' لم تُعتمد أو فيها فروقات مفتوحة.' : 'كل وجبات الفترة معتمدة.');

        $age = max(1, (int) (Tenancy::company()?->setting('finance.custody_age_days', 7) ?: 7));
        $custody = Shipment::query()->whereNotNull('delivered_at')->whereNotNull('delivery_courier_id')->whereNull('courier_settlement_id')
            ->whereUntilDate('delivered_at', min($to->toDateString(), now()->subDays($age)->toDateString()));
        $custodyCount = (clone $custody)->count();
        $add('custody', 'لا عهدٌ أقدم من '.Arabic::days($age), $custodyCount === 0,
            $custodyCount ? Arabic::shipments($custodyCount).' سُلِّمت قبل '.Arabic::days($age).' ولم يُحاسَب مندوبها، نقدها '.number_format((int) (clone $custody)->sum('collected_amount')).' د.ع.' : 'كل عهدةٍ أحدث من الحدّ.');

        $uncategorised = Expense::query()->whereNull('expense_category_id')->where('status', '!=', 'cancelled')
            ->where('spent_on', '>=', $from->toDateString())->where('spent_on', '<=', $to->toDateString())->count();
        $drafts = CourierSettlement::query()->where('status', 'draft')->whereUntilDate('created_at', $to->toDateString())->count()
            + MerchantSettlement::query()->where('status', 'draft')->whereUntilDate('created_at', $to->toDateString())->count();
        $add('drafts', 'لا مصروفات بلا بند ولا كشوف مسودّة', $uncategorised + $drafts === 0, trim(
            ($uncategorised ? Arabic::count($uncategorised, ['مصروفٌ واحد', 'مصروفان', 'مصروفات', 'مصروفاً']).' بلا بند. ' : '')
            .($drafts ? Arabic::count($drafts, ['كشفٌ مسودّة', 'كشفان مسودّتان', 'كشوف مسودّة', 'كشفاً مسودّة']).' لم يُقفَل أو يُحذف.' : '')) ?: 'لا شيء معلَّق.');

        $journal = collect($fresh ? $this->journalCheck->run() : $this->journalCheck->latest()['checks'])->keyBy('key');
        $ledgerFails = collect(['boxes', 'custody', 'commissions', 'merchants'])->filter(fn ($k) => ($journal[$k]['status'] ?? 'ok') === 'fail');
        $add('ledger', 'الدفتر العام = الدفاتر الفرعية', $ledgerFails->isEmpty(),
            $ledgerFails->isEmpty() ? 'الصناديق والعهد والعمولات والتجّار مطابقةٌ لقيودها.' : $ledgerFails->map(fn ($k) => $journal[$k]['label'].': '.$journal[$k]['detail'])->implode(' · '));

        $add('interbranch', 'الجاري بين الفروع متقاصّ (1400)', ($journal['interbranch']['status'] ?? 'ok') === 'ok', $journal['interbranch']['detail'] ?? 'لا قيد بين الفروع.');

        $failing = collect(['balanced', 'chain', 'audit_chain'])->filter(fn ($k) => ($journal[$k]['status'] ?? 'ok') !== 'ok');
        $add('balanced', 'ميزان المراجعة متوازن وسلسلتا البصمات سليمتان', $failing->isEmpty(),
            $failing->isEmpty() ? 'متوازن، ولا قيد ولا سطر تدقيقٍ عُدِّل خارج النظام.' : $failing->map(fn ($k) => $journal[$k]['detail'])->implode(' '));

        if (($journal['initialized']['status'] ?? null) === 'note') {
            $add('initialized', 'الدفتر مبنيٌّ من التاريخ', false, 'لم يكتمل بناء الدفتر بعد: انتظره دقائق ثم أعد المراجعة.');
        }

        return $checks;
    }

    /**
     * الإعداد: الفحوص الفاشلة يلزم كلًّا منها سببٌ مكتوب، والفترة غير الصالحة لا تُعَدّ.
     *
     * @param  array<string, ?string>  $exceptions  مفتاح الفحص ⇐ سبب الاستثناء
     */
    public function prepare(Carbon $from, Carbon $to, array $exceptions, User $actor): FinancialPeriod
    {
        return DB::transaction(function () use ($from, $to, $exceptions, $actor) {
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();

            $checks = $this->checks($from, $to);
            $taken = $this->requireExceptions($checks, $exceptions);

            $period = FinancialPeriod::create([
                'number'              => $this->sequences->next('financial_period'),
                'from_date'           => $from->toDateString(),
                'to_date'             => $to->toDateString(),
                'status'              => 'pending_approval',
                'prepared_by_user_id' => $actor->id,
                'prepared_at'         => now(),
                'figures'             => $this->figures($from, $to),
                'checks'              => $checks,
                'exceptions'          => $taken,
            ]);

            $this->audit($period, $actor, 'period_prepared', $taken ? implode(' · ', $taken) : null);

            return $period;
        });
    }

    /**
     * الاعتماد: تُعاد الفحوص (ما تغيّر منذ الإعداد بلا استثناءٍ يُردّ)، ويُكتب قيد الإقفال بتاريخ
     * آخر يوم قبل أن تُقفَل، وتُحفظ الأرقام والنسخة الأولى من التقرير.
     */
    public function approve(FinancialPeriod $period, User $actor): FinancialPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status !== 'pending_approval') {
                throw ValidationException::withMessages(['period' => "الفترة {$period->number} ليست بانتظار الاعتماد."]);
            }

            if (Tenancy::company()?->setting('finance.dual_approval') && (int) $period->prepared_by_user_id === (int) $actor->id) {
                throw ValidationException::withMessages(['period' => 'يعتمد الإقفالَ غيرُ من أعدّه (إعداد الشركة).']);
            }

            $checks = $this->checks($period->from_date, $period->to_date, $period);
            $this->requireExceptions($checks, $period->exceptions ?? [], 'تغيّر شيءٌ منذ الإعداد: ');
            $figures = $this->figures($period->from_date, $period->to_date);

            $entry = $this->closingEntry($period, $actor);

            $period->forceFill([
                'status' => 'closed', 'approved_by_user_id' => $actor->id, 'approved_at' => now(),
                'figures' => $figures, 'checks' => $checks, 'closing_entry_id' => $entry,
            ])->save();

            FinancialPeriodReport::create([
                'financial_period_id' => $period->id, 'version' => 1, 'figures' => $figures, 'adjustments' => [],
                'issued_by_user_id' => $actor->id, 'issued_at' => now(), 'reason' => 'الإقفال',
            ]);

            $this->audit($period, $actor, 'period_closed');

            return $period;
        });
    }

    /** إعدادٌ لم يُعتمد يُلغى بسببه، والفترة تُعَدّ من جديد */
    public function cancel(FinancialPeriod $period, string $reason, User $actor): void
    {
        DB::transaction(function () use ($period, $reason, $actor) {
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status !== 'pending_approval') {
                throw ValidationException::withMessages(['period' => 'الفترة المُقفَلة لا تُلغى: تعديلاتها تُكتب في الفترة المفتوحة.']);
            }

            $this->audit($period, $actor, 'period_preparation_cancelled', $reason);
            $period->delete();
        });
    }

    /**
     * ما كُتب بعد الإقفال ويمسّ الفترة: قيوده، وأثره على حساباتها.
     *
     * @return array{entries: list<array<string, mixed>>, accounts: array<string, int>, revenue: int, costs: int, net: int}
     */
    public function adjustments(FinancialPeriod $period): array
    {
        $entries = DB::table('journal_entries')->where('company_id', $period->company_id)->where('adjusts_period_id', $period->id)
            ->orderBy('id')->get(['id', 'number', 'entry_date', 'event', 'description', 'amount']);

        $accounts = DB::table('journal_lines')->whereIn('journal_entry_id', $entries->pluck('id')->all() ?: [0])
            ->groupBy('account')->selectRaw('account, sum(debit - credit) as v')->pluck('v', 'account')
            ->map(fn ($v) => (int) $v)->all();

        $revenue = -array_sum(array_intersect_key($accounts, array_flip(ProfitDashboard::REVENUE)));
        $costs = array_sum(array_intersect_key($accounts, array_flip(ProfitDashboard::COSTS)));

        return [
            'entries'  => $entries->map(fn ($e) => (array) $e + ['entry_date' => substr((string) $e->entry_date, 0, 10)])->all(),
            'accounts' => $accounts,
            'revenue'  => $revenue,
            'costs'    => $costs,
            'net'      => $revenue - $costs,
        ];
    }

    /** نسخةٌ تالية من التقرير: أرقام الإقفال كما هي، وبيان التعديلات بعدها وأثرها */
    public function reissue(FinancialPeriod $period, string $reason, User $actor): FinancialPeriodReport
    {
        return DB::transaction(function () use ($period, $reason, $actor) {
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (! $period->isClosed()) {
                throw ValidationException::withMessages(['period' => 'التقرير يُصدَر لفترةٍ مُقفَلة.']);
            }

            $adjustments = $this->adjustments($period);
            $figures = $period->figures;
            $figures['after_adjustments'] = [
                'revenue' => (int) ($figures['net']['fees'] ?? 0) + (int) $adjustments['revenue'],
                'costs'   => (int) ($figures['net']['costs'] ?? 0) + (int) $adjustments['costs'],
                'net'     => (int) ($figures['net']['total'] ?? 0) + (int) $adjustments['net'],
            ];

            $report = FinancialPeriodReport::create([
                'financial_period_id' => $period->id,
                'version'             => (int) $period->reports()->max('version') + 1,
                'figures'             => $figures,
                'adjustments'         => $adjustments,
                'issued_by_user_id'   => $actor->id,
                'issued_at'           => now(),
                'reason'              => $reason,
            ]);

            $this->audit($period, $actor, 'period_report_reissued', $reason);

            return $report;
        });
    }

    /**
     * قيد الإقفال: يُصفّي إيرادات الفترة وتكاليفها (والداخليّة بين الفروع) إلى 3200 بتاريخ
     * آخر يومٍ فيها. ولا يدخل لوحة الأرباح (ProfitDashboard يستثني حدثه).
     */
    protected function closingEntry(FinancialPeriod $period, User $actor): ?int
    {
        $accounts = [...ProfitDashboard::REVENUE, A::INTERNAL_REVENUE, ...ProfitDashboard::COSTS, A::INTERNAL_COST];
        $by = (new ProfitDashboard)->netByAccount($accounts, $period->from_date, $period->to_date);

        $lines = [];
        $total = 0;
        foreach ($by as $account => $balance) {
            if ($balance !== 0) {
                $lines[] = ['account' => $account, ($balance > 0 ? 'credit' : 'debit') => abs($balance)];
                $total += $balance;
            }
        }

        if ($lines === []) {
            return null;
        }

        // ما بقي ربحاً (دائن) أو خسارةً (مدين) يدخل «أرباح فتراتٍ مُقفَلة»
        $lines[] = ['account' => A::RETAINED, ($total > 0 ? 'debit' : 'credit') => abs($total)];

        return $this->journal->post((int) $period->company_id, 'close:'.$period->id, 'period_close', $period->to_date->copy()->setTime(12, 0),
            array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? 0) + ($l['credit'] ?? 0) > 0)),
            'financial_period', $period->id, "إقفال الفترة {$period->number} ({$period->label()})", $actor->id);
    }

    /**
     * @param  list<array{key: string, label: string, ok: bool, detail: string}>  $checks
     * @return array<string, string> الاستثناءات المأخوذة بأسبابها
     */
    protected function requireExceptions(array $checks, array $exceptions, string $prefix = ''): array
    {
        $taken = [];
        $missing = [];

        foreach ($checks as $check) {
            if ($check['ok']) {
                continue;
            }

            $reason = trim((string) ($exceptions[$check['key']] ?? ''));

            if (in_array($check['key'], self::BLOCKING, true) || $check['key'] === 'initialized') {
                throw ValidationException::withMessages(['period' => $prefix.$check['label'].': '.$check['detail']]);
            }

            if ($reason === '') {
                $missing[] = $check['label'];
            } else {
                $taken[$check['key']] = $reason;
            }
        }

        if ($missing) {
            throw ValidationException::withMessages(['exceptions' => $prefix.'فحوصٌ لم تنجح بلا استثناءٍ موثّق: '.implode('، ', $missing).'. اكتب لكلٍّ سبب تجاوزه.']);
        }

        return $taken;
    }

    protected function audit(FinancialPeriod $period, User $actor, string $action, ?string $reason = null): void
    {
        AuditLog::create([
            'user_id'        => $actor->id,
            'user_name'      => $actor->name,
            'action'         => $action,
            'auditable_type' => FinancialPeriod::class,
            'auditable_id'   => $period->id,
            'new_values'     => array_filter(['number' => $period->number, 'from' => $period->from_date?->toDateString(),
                'to' => $period->to_date?->toDateString(), 'status' => $period->status]),
            'reason'         => $reason === null ? null : mb_substr($reason, 0, 255),
            'ip'             => request()->ip(),
        ]);
    }
}

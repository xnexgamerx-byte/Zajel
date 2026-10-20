<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\FinancialAlert;
use App\Models\User;
use App\Services\Finance\FinancialAlerts;
use App\Services\Finance\Journal;
use App\Services\Finance\JournalCheck;
use App\Services\Finance\OpeningBalances;
use App\Services\Finance\PeriodClose;
use App\Services\Finance\ProfitDashboard;
use App\Services\Reports\ReportPeriod;
use App\Support\Audit\AuditChain;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * قبول النظام المالي على بيانات شركةٍ حقيقية (docs/plan/68، المرحلة السابعة) — يعمل على الخادم
 * نفسه، على القاعدة أو على نسخةٍ مستعادة منها (--database)، ولا يُخرج بياناً عن عميلٍ بعينه:
 * - الدفتر: مبنيٌّ؟ وكم قيداً وسطراً؟ وفحوص المطابقة كلّها، وسلسلتا البصمات.
 * - ما يحتاج قيداً افتتاحياً (عدداً ومجموعاً لكل نوع).
 * - الخصائص الثابتة: الميزان صفر، مجموع الفروع = الشركة، المتحقّق ≤ المعترف، المتاح ≥ ٠.
 * - فحوص إقفال الفترة المقترحة، والتنبيهات التي سيفتحها الفحص (في معاملةٍ تُلغى).
 * - زمن الشاشات المالية كما يفتحها صاحب الشركة: اللوحة أقل من ثانية، والتقارير أقل من ٣.
 *
 * التقرير في storage/app/acceptance على الخادم، ولا يُرفع إلى المستودع.
 */
class FinanceAcceptanceCommand extends Command
{
    protected $signature = 'finance:acceptance
        {--company= : الشركة (slug)؛ فارغاً: كل الشركات}
        {--database= : قاعدةٌ أخرى على الخادم نفسه (نسخةٌ مستعادة) بدل قاعدة التشغيل}
        {--no-pages : بلا قياس الشاشات}';

    protected $description = 'يفحص الدفتر والإقفال والتنبيهات ويقيس الشاشات المالية على بيانات الشركة، ويكتب تقريراً على الخادم';

    /** الشاشة: [المسار، الحدّ بالمللي ثانية] */
    public const PAGES = [
        'لوحة الأرباح'           => ['/profits', 1000],
        'الموقف النقدي'          => ['/profits/cash', 3000],
        'سجل الوجبات'            => ['/profits/batches', 3000],
        'سحب الأرباح'            => ['/profits/withdrawals', 3000],
        'ميزان المراجعة'         => ['/finance/trial-balance', 3000],
        'دفتر القيود'            => ['/finance/ledger', 3000],
        'مراجعة ما قبل الإقفال'  => ['/finance/periods/review', 3000],
        'الأرصدة الافتتاحية'     => ['/finance/opening', 3000],
        'التنبيهات المالية'      => ['/operational-alerts?tab=finance', 3000],
        'تصدير لوحة الأرباح'     => ['/profits/export', 3000],
    ];

    private array $report = [];

    private bool $failed = false;

    public function handle(Journal $journal, JournalCheck $check, OpeningBalances $opening, PeriodClose $close, FinancialAlerts $alerts): int
    {
        if ($database = $this->option('database')) {
            $connection = config('database.default');
            config(["database.connections.{$connection}.database" => $database]);
            DB::purge($connection);
            $this->line("القاعدة: {$database}");
        }

        $companies = Tenancy::runAsPlatform(fn () => Company::query()
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))->orderBy('id')->get());

        foreach ($companies as $company) {
            $this->report = [];
            Tenancy::runFor($company, fn () => $this->company($company, $journal, $check, $opening, $close, $alerts));
            if (! $this->option('no-pages')) {
                $this->pages($company);
            }
            $this->write($company);
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function company(Company $company, Journal $journal, JournalCheck $check, OpeningBalances $opening, PeriodClose $close, FinancialAlerts $alerts): void
    {
        $this->section("# قبول النظام المالي — {$company->name}");
        $this->line('- التاريخ: '.now()->timezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->line('- الشحنات: '.number_format(DB::table('shipments')->where('company_id', $company->id)->count()));

        // ١. الدفتر
        $this->section('## الدفتر');
        $initialized = $journal->isInitialized($company->id);
        $this->line('- مبنيٌّ من التاريخ: '.($initialized ? 'نعم' : 'لا — ينتظر finance:journal-backfill'));
        $this->line('- القيود: '.number_format(DB::table('journal_entries')->where('company_id', $company->id)->count())
            .'، السطور: '.number_format(DB::table('journal_lines')->where('company_id', $company->id)->count()));

        [$ms, $results] = $this->timed(fn () => $check->run());
        foreach ($results as $r) {
            $this->verdict($r['status'] !== 'fail', "{$r['label']}: {$r['detail']}", $r['status'] === 'note');
        }
        $this->line(sprintf('- زمن المطابقة الكاملة: %s مللي ثانية', number_format($ms)));

        // ٢. القيد الافتتاحي
        $this->section('## ما يحتاج قيداً افتتاحياً');
        $rows = collect($opening->differences())->groupBy('kind');
        if ($rows->isEmpty()) {
            $this->verdict(true, 'كل رصيدٍ يطابق قيوده: لا حاجة لقيدٍ افتتاحي.');
        }
        foreach (OpeningBalances::KINDS as $kind => $label) {
            if ($rows->has($kind)) {
                $this->verdict(false, sprintf('%s: %s بفرقٍ مجموعه %s د.ع', $label, number_format($rows[$kind]->count()), number_format($rows[$kind]->sum('diff'))), true);
            }
        }

        // ٣. الخصائص الثابتة
        $this->section('## الخصائص الثابتة');
        $board = new ProfitDashboard;
        $all = ReportPeriod::fromRequest(new Request(['from' => '2000-01-01', 'to' => now()->toDateString()]));
        $trial = (int) DB::table('journal_lines')->where('company_id', $company->id)->sum(DB::raw('debit - credit'));
        $this->verdict($trial === 0, 'ميزان المراجعة = صفر ('.number_format($trial).')');

        $revenue = $board->revenue($all)['total'];
        $branches = $board->branches($all)->sum('revenue');
        $this->verdict($revenue === (int) $branches, sprintf('مجموع إيرادات الفروع = إيراد الشركة (%s / %s)', number_format($branches), number_format($revenue)));

        $realized = $board->realized(null, null)['fees'];
        $this->verdict($realized <= $revenue, sprintf('الأجور المحصَّلة نقداً ≤ المعترف بها (%s / %s)', number_format($realized), number_format($revenue)));

        $available = $board->available()['total'];
        $this->verdict($available >= 0, 'المتاح للسحب ≥ ٠ ('.number_format($available).')');

        // ٤. الإقفال
        $this->section('## الفترة المقترحة للإقفال');
        [$from, $to] = $close->suggest();
        $this->line('- من '.$from->toDateString().' إلى '.$to->toDateString());
        [$ms, $checks] = $this->timed(fn () => $close->checks($from, $to));
        foreach ($checks as $c) {
            $this->verdict($c['ok'], "{$c['label']}: {$c['detail']}", true);
        }
        $this->line(sprintf('- زمن الفحوص: %s مللي ثانية', number_format($ms)));

        // ٥. التنبيهات (تُفتح في معاملةٍ ثم تُلغى: لا أثر)
        $this->section('## التنبيهات المفتوحة بعد الفحص');
        DB::beginTransaction();
        try {
            [$ms] = $this->timed(fn () => $alerts->scan());
            $alerts->scan(FinancialAlert::DAILY);
            $open = FinancialAlert::query()->whereIn('status', ['open', 'in_progress'])->get()->groupBy('type');
            foreach ($open as $type => $group) {
                $this->line(sprintf('- %s: %s (%s)', FinancialAlert::TYPES[$type][0] ?? $type, number_format($group->count()),
                    $group->groupBy('severity')->map(fn ($g, $s) => FinancialAlert::SEVERITIES[$s][0].' '.$g->count())->implode('، ')));
            }
            if ($open->isEmpty()) {
                $this->line('- لا تنبيه.');
            }
            $this->line(sprintf('- زمن فحص الساعة: %s مللي ثانية', number_format($ms)));
        } finally {
            DB::rollBack();
        }
    }

    /** الشاشات كما يفتحها صاحب الشركة، بطلبٍ داخليٍّ كامل (المسار والصلاحية والعرض) */
    private function pages(Company $company): void
    {
        $this->section('## زمن الشاشات المالية');
        $owner = Tenancy::runFor($company, fn () => User::query()->where('role', UserRole::CompanyOwner)->where('is_active', true)->orderBy('id')->first());
        if (! $owner) {
            $this->line('- لا صاحب شركةٍ نشط: لم تُقس الشاشات.');

            return;
        }

        $host = $company->slug.'.'.config('zajel.tenant_domain');
        // القياس لا يكتب: شاشة التنبيهات لا تفحص بنفسها الآن (زمن الفحص مقيسٌ أعلاه في معاملةٍ تُلغى)
        cache()->add(FinancialAlerts::scanKey($company->id), true, now()->addMinutes(15));
        foreach (self::PAGES as $label => [$path, $limit]) {
            $best = null;
            $status = null;
            // المرّة الأولى تُحمّل الشيفرة والذاكرة المؤقّتة: يُحسب أسرع الثلاث
            foreach (range(1, 3) as $i) {
                try {
                    auth()->guard('web')->setUser($owner);
                    $request = Request::create('https://'.$host.$path, 'GET');
                    $request->headers->set('host', $host);
                    $started = microtime(true);
                    $response = app(Kernel::class)->handle($request);
                    $ms = (int) round((microtime(true) - $started) * 1000);
                    $status = $response->getStatusCode();
                    $best = $best === null ? $ms : min($best, $ms);
                } catch (Throwable $e) {
                    $status = 'خطأ: '.mb_substr($e->getMessage(), 0, 120);
                    break;
                }
            }
            $this->verdict($status === 200 && $best !== null && $best <= $limit,
                sprintf('%s: %s مللي ثانية (الحدّ %s) — %s', $label, $best === null ? '—' : number_format($best), number_format($limit), $status));
        }
        Tenancy::forget();
    }

    /** @return array{int, mixed} */
    private function timed(callable $fn): array
    {
        $started = microtime(true);
        $result = $fn();

        return [(int) round((microtime(true) - $started) * 1000), $result];
    }

    private function section(string $title): void
    {
        $this->report[] = '';
        $this->report[] = $title;
        $this->info($title);
    }

    public function line($string, $style = null, $verbosity = null): void
    {
        $this->report[] = $string;
        parent::line($string, $style, $verbosity);
    }

    private function verdict(bool $ok, string $text, bool $soft = false): void
    {
        if (! $ok && ! $soft) {
            $this->failed = true;
        }
        $this->line(($ok ? '- ✓ ' : ($soft ? '- • ' : '- ✗ ')).$text);
    }

    private function write(Company $company): void
    {
        $dir = storage_path('app/acceptance');
        File::ensureDirectoryExists($dir);
        $file = $dir.'/'.$company->slug.'-'.now()->format('Ymd-His').'.md';
        File::put($file, implode("\n", $this->report)."\n");
        $this->info("التقرير: {$file}");
    }
}

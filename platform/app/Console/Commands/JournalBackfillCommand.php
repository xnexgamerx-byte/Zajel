<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Finance\JournalBackfill;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يبني دفتر القيود من التاريخ لكل شركةٍ لم يُبنَ دفترها بعد (docs/plan/68).
 *
 * مجدولٌ كل خمس دقائق: بعد أوّل تحديثٍ يبني تاريخ كل شركةٍ مرّةً، ثم لا يجد شيئاً.
 * وإعادته على شركةٍ مبنيّة لا تكرّر قيداً: كل قيدٍ بمفتاح مصدره.
 */
class JournalBackfillCommand extends Command
{
    protected $signature = 'finance:journal-backfill {--company= : شركةٌ بعينها (ولو كانت مبنيّة)}';

    protected $description = 'يبني دفتر القيود من حركات الشركات التي لم يُبنَ دفترها';

    public function handle(JournalBackfill $backfill): int
    {
        $pending = DB::table('journal_heads')->whereNull('initialized_at')->pluck('company_id');

        $companies = Tenancy::runAsPlatform(fn () => Company::query()
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug), fn ($q) => $q->whereIn('id', $pending))
            ->orderBy('id')->get());

        foreach ($companies as $company) {
            $started = microtime(true);
            $counts = $backfill->run($company);

            $this->info(sprintf('%s: %s حركة حساب، %s حركة صندوق، %s مصروف، %s سحب — %.1f ث',
                $company->name, number_format($counts['transactions']), number_format($counts['cash']),
                number_format($counts['expenses']), number_format($counts['withdrawals']), microtime(true) - $started));
        }

        return self::SUCCESS;
    }
}

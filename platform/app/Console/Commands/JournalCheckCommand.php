<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Finance\JournalCheck;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * مطابقة دفتر القيود بالدفاتر الفرعية لكل شركة كل ليلة. يخرج بغير الصفر إن وجد فرقاً.
 */
class JournalCheckCommand extends Command
{
    protected $signature = 'finance:journal-check {--company= : نطاق شركة واحدة}';

    protected $description = 'يطابق دفتر القيود بالصناديق والمناديب والتجّار ويفحص سلسلة البصمات';

    public function handle(JournalCheck $check): int
    {
        $companies = Tenancy::runAsPlatform(fn () => Company::query()
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get());

        $failed = false;

        foreach ($companies as $company) {
            $results = Tenancy::runFor($company, fn () => $check->run());
            $fails = array_values(array_filter($results, fn ($r) => $r['status'] === 'fail'));
            $failed = $failed || $fails !== [];

            $this->line($company->name.': '.($fails === [] ? 'مطابق' : implode('، ', array_column($fails, 'label'))));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

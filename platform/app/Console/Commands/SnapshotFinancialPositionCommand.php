<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FinancialSnapshot;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * لقطةٌ ليليّة من «الموقف المالي» لكل شركة: «تاريخ الموقف المالي» يُقرأ
 * يوماً بيوم ولو لم يضغط أحدٌ «حفظ نسخة».
 */
class SnapshotFinancialPositionCommand extends Command
{
    protected $signature = 'zajel:snapshot {--company= : نطاق شركة واحدة}';

    protected $description = 'يلتقط الموقف المالي لكل شركة';

    public function handle(): int
    {
        $companies = Tenancy::runAsPlatform(fn () => Company::query()
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get());

        foreach ($companies as $company) {
            $snapshot = Tenancy::runFor($company, fn () => FinancialSnapshot::take());
            $this->line("{$company->name}: ".number_format($snapshot->total));
        }

        return self::SUCCESS;
    }
}

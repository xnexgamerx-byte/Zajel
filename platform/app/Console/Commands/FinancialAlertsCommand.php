<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FinancialAlert;
use App\Services\Finance\FinancialAlerts;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * التنبيهات المالية (docs/plan/68 §١٢): كل ساعة تُفحص الحسابات، وكل يومٍ سلسلتا البصمات.
 * شركةٌ يتعثّر فحصها لا توقف غيرها.
 */
class FinancialAlertsCommand extends Command
{
    protected $signature = 'finance:alerts {--daily : سلسلتا البصمات بدل فحص الساعة} {--company= : نطاق شركة واحدة}';

    protected $description = 'يفحص الحسابات ويفتح التنبيهات المالية أو يغلق ما زال سببه';

    public function handle(FinancialAlerts $alerts): int
    {
        $companies = Tenancy::runAsPlatform(fn () => Company::query()
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get());

        $failed = false;

        foreach ($companies as $company) {
            try {
                $done = Tenancy::runFor($company, fn () => $alerts->scan($this->option('daily') ? FinancialAlert::DAILY : null));
                $this->line("{$company->name}: فُتح {$done['opened']}، وزال سبب {$done['cleared']}");
            } catch (Throwable $e) {
                $failed = true;
                report($e);
                $this->error("{$company->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

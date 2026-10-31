<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Models\AggregatorLink;
use App\Models\Company;
use App\Services\Aggregator\AggregatorOutbox;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * يرسل لنظام الوسيط ما حان من أعمال كل شركةٍ مربوطة (docs/plan/104): إنشاء الطلبات دفعاتٍ،
 * واستلام الرواجع. كل دقيقة؛ وما فشل يُعاد بمهلةٍ تطول ويُرى في شاشة الربط.
 */
class AggregatorSyncCommand extends Command
{
    protected $signature = 'aggregator:sync';

    protected $description = 'يرسل لنظام الشركة الوسيطة ما حان من طلباتٍ ورواجع';

    public function handle(AggregatorOutbox $outbox): int
    {
        $links = Tenancy::runAsPlatform(fn () => AggregatorLink::query()->withoutGlobalScopes()->whereNotNull('courier_id')->get());

        foreach ($links as $link) {
            $company = Tenancy::runAsPlatform(fn () => Company::query()->find($link->company_id));

            if (! $company?->hasFeature(Feature::Aggregator) || ! $company->isOperational()) {
                continue;
            }

            try {
                $counts = Tenancy::runFor($company, fn () => $outbox->run($link));
                if ($counts['sent'] || $counts['failed']) {
                    $this->info("{$company->name}: أُرسل {$counts['sent']}، وفشل {$counts['failed']}.");
                }
            } catch (Throwable $e) {
                report($e); // شركةٌ تعطّلت لا توقف غيرها
            }
        }

        return self::SUCCESS;
    }
}

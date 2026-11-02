<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Models\AggregatorLink;
use App\Models\Company;
use App\Services\Aggregator\AggregatorInbox;
use App\Services\Aggregator\AggregatorOutbox;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * يرسل لنظام الوسيط ما حان من أعمال كل شركةٍ مربوطة (docs/plan/104): إنشاء الطلبات دفعاتٍ،
 * واستلام الرواجع. كل دقيقة؛ وما فشل يُعاد بمهلةٍ تطول ويُرى في شاشة الربط. ويطبّق قبلها ما وصل منهم
 * ولم يُطبَّق بعد الردّ (docs/plan/106).
 */
class AggregatorSyncCommand extends Command
{
    protected $signature = 'aggregator:sync';

    protected $description = 'يرسل لنظام الشركة الوسيطة ما حان من طلباتٍ ورواجع';

    public function handle(AggregatorOutbox $outbox, AggregatorInbox $inbox): int
    {
        $links = Tenancy::runAsPlatform(fn () => AggregatorLink::query()->withoutGlobalScopes()->whereNotNull('courier_id')->get());

        foreach ($links as $link) {
            $company = Tenancy::runAsPlatform(fn () => Company::query()->find($link->company_id));

            if (! $company?->hasFeature(Feature::Aggregator) || ! $company->isOperational()) {
                continue;
            }

            // تحديثاتٌ حُفظت ولم تُطبَّق بعد الردّ (انقطاعٌ مثلاً): تُطبَّق الآن بترتيبها (docs/plan/106)
            try {
                Tenancy::runFor($company, fn () => $inbox->processPending($link));
            } catch (Throwable $e) {
                report($e);
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

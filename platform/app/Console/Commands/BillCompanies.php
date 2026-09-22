<?php

namespace App\Console\Commands;

use App\Actions\Billing\GenerateInvoice;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * فوترة شهرية لكل الشركات. يُجدوَل أول كل شهر:
 *   Schedule::command('zajel:bill')->monthlyOn(1, '02:00');
 */
class BillCompanies extends Command
{
    protected $signature = 'zajel:bill
        {--month= : الشهر بصيغة YYYY-MM (الافتراضي: الشهر الماضي)}
        {--company= : نطاق شركة واحدة}
        {--draft : أنشئها مسوّدة بلا إصدار}';

    protected $description = 'يُصدر فواتير الاشتراك والعمولة للشركات';

    public function handle(GenerateInvoice $generate): int
    {
        $period = $this->option('month')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('month').'-01')
            : CarbonImmutable::now()->subMonth();

        $companies = Tenancy::runAsPlatform(fn () => Company::query()
            ->whereIn('status', ['active', 'trial'])
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get());

        if ($companies->isEmpty()) {
            $this->warn('لا شركات مطابقة.');

            return self::SUCCESS;
        }

        $this->info("فوترة {$period->format('Y-m')} لـ {$companies->count()} شركة:");

        $rows = [];
        $total = 0;

        foreach ($companies as $company) {
            try {
                $invoice = $generate->handle($company, $period, ! $this->option('draft'));

                $rows[] = [$company->name, $invoice->number, number_format($invoice->billable_shipments),
                    number_format($invoice->total), 'صدرت'];
                $total += $invoice->total;
            } catch (ValidationException $e) {
                $rows[] = [$company->name, '—', '—', '—', collect($e->errors())->flatten()->first()];
            }
        }

        $this->table(['الشركة', 'الفاتورة', 'شحنات', 'المبلغ', 'الحالة'], $rows);
        $this->line('  المجموع: '.number_format($total).' د.ع');

        return self::SUCCESS;
    }
}

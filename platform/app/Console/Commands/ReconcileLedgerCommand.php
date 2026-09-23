<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * مطابقة الدفتر لكل الشركات كل ليلة.
 *
 * يخرج بغير الصفر إن وجد فرقاً، فيلتقطه أيّ منبّهٍ يراقب المهامّ المجدولة:
 * فرقٌ في المال يُعرَف صباحاً لا في جرد آخر السنة.
 */
class ReconcileLedgerCommand extends Command
{
    protected $signature = 'zajel:reconcile {--company= : نطاق شركة واحدة}';

    protected $description = 'يطابق الأرصدة بالدفتر والدفتر بالشحنات لكل شركة';

    public function handle(Ledger $ledger): int
    {
        $companies = Tenancy::runAsPlatform(fn () => Company::query()
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get());

        $rows = [];
        $dirty = false;

        foreach ($companies as $company) {
            [$balances, $shipments] = Tenancy::runFor($company, fn () => [
                $ledger->balancesOff(),
                $ledger->offLedgerByMerchant(),
            ]);

            $counts = [
                $balances['merchants']->count(),
                $balances['couriers']->count(),
                (int) $shipments->sum('shipments'),
            ];

            $dirty = $dirty || array_sum($counts) > 0;
            $rows[] = [$company->name, ...array_map('number_format', $counts), array_sum($counts) ? 'فرق' : 'مطابق'];
        }

        $this->table(['الشركة', 'تجّار', 'مناديب', 'شحنات', ''], $rows);

        return $dirty ? self::FAILURE : self::SUCCESS;
    }
}

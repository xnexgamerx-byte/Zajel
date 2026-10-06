<?php

namespace App\Console\Commands;

use App\Actions\Billing\EnforceDues;
use Illuminate\Console\Command;

class EnforceDuesCommand extends Command
{
    protected $signature = 'zajel:dues';

    protected $description = 'يعلّم الفواتير المتأخّرة، ويوقف ما فات المهلة، ويعيد ما سُدّد (docs/plan/36)';

    public function handle(EnforceDues $enforce): int
    {
        $done = $enforce->handle();

        $this->info("تأخّرت {$done['overdue']} فاتورة.");
        $this->line($done['suspended'] ? 'أُوقفت لتأخّر السداد: '.implode('، ', $done['suspended']) : 'لم تُوقَف شركة.');
        $this->line($done['resumed'] ? 'عادت بعد السداد: '.implode('، ', $done['resumed']) : 'لم تَعُد شركة.');

        return self::SUCCESS;
    }
}

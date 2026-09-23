<?php

namespace App\Console\Commands;

use App\Actions\Billing\RenewSubscriptions;
use Illuminate\Console\Command;

class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'zajel:renew';

    protected $description = 'يُجدّد الاشتراكات التلقائية التي مضى أجلها';

    public function handle(RenewSubscriptions $renew): int
    {
        $renewed = $renew->handle();

        $this->info($renewed->isEmpty()
            ? 'لا اشتراك يحتاج تجديداً.'
            : 'جُدِّد '.$renewed->count().' اشتراك.');

        return self::SUCCESS;
    }
}

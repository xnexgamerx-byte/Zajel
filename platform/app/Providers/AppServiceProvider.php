<?php

namespace App\Providers;

use Carbon\Carbon;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         | ar_IQ لا ar: أسماء الشهور السريانية هي المستعملة في العراق
         | — "أيلول" لا "سبتمبر". ملفات الترجمة تبقى تحت lang/ar.
         */
        Carbon::setLocale('ar_IQ');

        //
    }
}

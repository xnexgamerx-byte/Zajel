<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions\Ability;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
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

        /*
        | كل صلاحية بوّابة باسمها، فتُحرَس المسارات بـ can:... ويُسأل
        | عنها في القوالب بـ @can. والمنع في المسار لا في القالب وحده:
        | إخفاء الزرّ ليس منعاً.
        */
        foreach (Ability::all() as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasAbility($ability));
        }
    }
}

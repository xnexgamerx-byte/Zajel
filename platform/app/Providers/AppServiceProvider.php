<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions\Ability;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

        $this->registerDateMacros();
    }

    /**
     * مدى بدل دالّة على العمود.
     *
     * whereDate تُترجَم إلى strftime('%Y-%m-%d', col) = ? — والعمود داخل
     * دالّة لا يُستعمل فهرسه، فيُمسَح الجدول كلّه. على ١٨٠ ألف شحنة:
     * ١٦٠ مللي ثانية بـ whereDate مقابل ٢ بمدى. والفرق يزداد مع النموّ.
     */
    protected function registerDateMacros(): void
    {
        $onDate = function (string $column, $date) {
            $day = Carbon::parse($date)->startOfDay();

            return $this->where($column, '>=', $day)
                ->where($column, '<', $day->copy()->addDay());
        };

        $fromDate = fn (string $column, $date) => $this->where($column, '>=', Carbon::parse($date)->startOfDay());
        $untilDate = fn (string $column, $date) => $this->where($column, '<', Carbon::parse($date)->startOfDay()->addDay());

        foreach ([QueryBuilder::class, EloquentBuilder::class] as $builder) {
            $builder::macro('whereOnDate', $onDate);
            $builder::macro('whereFromDate', $fromDate);
            $builder::macro('whereUntilDate', $untilDate);
        }
    }
}

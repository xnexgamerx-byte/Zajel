<?php

namespace App\Support;

use App\Enums\Feature;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * الميزات في الشركة الحالية (docs/plan/35): المسار يحمل ميزته في وسيط feature:<المفتاح>،
 * ومنه يُعرف أيُّ رابطٍ يُعرض — في شريط الموظّفين وبوّابة التاجر وغيرهما — بلا قائمةٍ
 * ثانية تُنسى. وفي القوالب: @feature('order_reading') … @endfeature.
 */
final class FeatureGate
{
    /** الميزة مفتوحةٌ في الشركة الحالية. وبلا شركة (لوحة المنصّة) لا ميزة */
    public static function enabled(Feature|string $feature): bool
    {
        return Tenancy::company()?->hasFeature($feature) ?? false;
    }

    /** يُفتح هذا المسار في الشركة الحالية: لا ميزة تحرسه، أو ميزته مفتوحة */
    public static function allowsRoute(string $route): bool
    {
        $feature = self::guarding($route);

        return $feature === null || self::enabled($feature);
    }

    /** الميزة التي تحرس المسار، إن كانت */
    public static function guarding(string $route): ?Feature
    {
        foreach (Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                return self::known(substr($middleware, 8));
            }
        }

        return null;
    }

    /** مفتاحٌ مكتوبٌ في مسار: خطأٌ في الكتابة يُكتشف في أوّل طلب لا يمرّ صامتاً */
    public static function known(string $key): Feature
    {
        return Feature::tryFrom($key) ?? throw new InvalidArgumentException("ميزةٌ مجهولة في مسار: {$key}");
    }
}

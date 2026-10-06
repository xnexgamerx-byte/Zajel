<?php

namespace Tests\Feature\Platform;

use App\Enums\Feature;
use App\Support\FeatureGate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * قائمة الميزات (docs/plan/35) وقاعدتها: الميزة الجديدة مطفأةٌ في كل الشركات حتى تفتحها
 * المنصّة لشركةٍ بعينها. فإن فشل الاختبار الأوّل بعد إضافة ميزة، فالميزة لا تُضاف إلى
 * INCLUDED — تبقى مطفأةً وتُفتح لمن يدفع.
 */
class FeatureCatalogTest extends TestCase
{
    public function test_only_the_screens_that_came_before_the_catalog_are_open_everywhere(): void
    {
        $open = array_values(array_filter(Feature::cases(), fn (Feature $feature) => $feature->included()));

        $this->assertSame(
            [Feature::QuickEntry, Feature::ExcelImport, Feature::Waybills, Feature::Conversations, Feature::Announcements, Feature::AppAds],
            $open,
            'ميزةٌ جديدة لا تُفتح لكل الشركات: تبقى مطفأةً حتى تفتحها المنصّة لشركةٍ بعينها.',
        );
        $this->assertFalse(Feature::OrderReading->included());
    }

    public function test_every_feature_guards_screens_and_every_guard_names_a_feature(): void
    {
        $guarded = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                    // مفتاحٌ مكتوبٌ خطأً يرمي هنا لا في طلب مستخدم
                    $guarded[] = FeatureGate::known(substr($middleware, 8));
                }
            }
        }

        foreach (Feature::cases() as $feature) {
            $this->assertContains($feature, $guarded, "«{$feature->label()}» لا تحرس شاشة: إغلاقها لا يُغلق شيئاً.");
        }
    }

    public function test_every_feature_is_named_and_explained_in_arabic(): void
    {
        foreach (Feature::cases() as $feature) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $feature->label());
            $this->assertMatchesRegularExpression('/\p{Arabic}{3}/u', $feature->description());
        }

        $this->assertSame(array_column(Feature::cases(), 'value'), Feature::keys());
    }
}

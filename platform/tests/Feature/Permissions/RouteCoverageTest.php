<?php

namespace Tests\Feature\Permissions;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * فحص بنيوي: كل مسار موظّفين يُغيّر حالةً يجب أن يحرسه can.
 *
 * وُضِع لأن مسار «المستخدمون» فات الحراسة عند إضافتها: كل شيء يعمل،
 * ويعمل أكثر ممّا ينبغي. والنسيان هنا لا يُكتشف بالاستعمال — يُكتشف
 * يوم يفعل موظّف ما لا يخصّه.
 */
class RouteCoverageTest extends TestCase
{
    /**
     * مسارات لا تحتاج صلاحية بطبيعتها.
     *
     * @var array<int, string>
     */
    private const EXEMPT = [
        'logout',
        'shipments.index',      // القراءة مقيّدة بالدور ثم بـ visibleTo
        'shipments.show',
        'dashboard',
        'pricing.quote',        // تسعير تقديريّ للشاشة، لا يكتب شيئاً
        'impersonation.stop',
    ];

    public function test_every_staff_route_that_writes_is_guarded_by_an_ability(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $name = $route->getName() ?? $route->uri();

            $isStaff = in_array('staff', $middleware, true);
            $writes = (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            $guarded = (bool) array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:'));

            if ($isStaff && $writes && ! $guarded && ! in_array($name, self::EXEMPT, true)) {
                $unguarded[] = $name;
            }
        }

        $this->assertSame([], $unguarded, "مسارات موظّفين تكتب بلا صلاحية:\n".implode("\n", $unguarded));
    }

    public function test_every_staff_screen_that_reads_sensitive_data_is_guarded(): void
    {
        $sensitive = ['cash.', 'expenses.', 'settlements.', 'permissions.', 'pricing.index',
                      'zones.', 'control.', 'reports.', 'users.', 'branches.'];

        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! collect($sensitive)->contains(fn ($p) => str_starts_with($name, $p))) {
                continue;
            }

            $guarded = (bool) array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'can:'),
            );

            if (! $guarded) {
                $unguarded[] = $name;
            }
        }

        $this->assertSame([], $unguarded, "شاشات حسّاسة بلا صلاحية:\n".implode("\n", $unguarded));
    }
}

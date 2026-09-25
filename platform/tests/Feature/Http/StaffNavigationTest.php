<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Company;
use App\Support\Permissions\Ability;
use App\Support\StaffNavigation;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * الشريط العلوي: بترتيب النظام الذي اعتاده الموظّفون، ولكل شاشةٍ مكانٌ فيه،
 * ولكل رابطٍ صلاحيته.
 *
 * الشريط هو الطريق الوحيد إلى أكثر الشاشات. شاشةٌ سقطت منه عند إعادة
 * ترتيبه لا يُبلغ عنها أحد — تختفي فحسب، ويظنّها الموظّف غير موجودة.
 */
class StaffNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** شريط النظام المعتاد من اليمين إلى اليسار (docs/plan/10-live-system-analysis.md §٣) */
    private const ORDER = [
        'الصفحة الرئيسية', 'شحنات العميل', 'عمليات التوصيل', 'طلبات شحن', 'تصفيات الراجع',
        'النظام المصرفي', 'إيرادات ومصروفات', 'تقارير', 'تقارير مالية', 'المراجعة',
        'إعدادات الفروع', 'الدفعات',
    ];

    /** شاشاتٌ تُفتح من داخل غيرها لا من الشريط: نماذج الإضافة، والطباعة، وقالب الاستيراد */
    private const OPENED_FROM_ELSEWHERE = [
        'branches.create', 'couriers.create', 'merchants.create', 'users.create',
        'shipments.import.template', 'branch-accounts.statement.print', 'shipments.labels',
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    public function test_menus_follow_the_familiar_order(): void
    {
        $this->assertSame(self::ORDER, array_column(StaffNavigation::menus(), 0));
    }

    public function test_every_link_names_a_real_route_and_a_real_ability(): void
    {
        $abilities = Ability::all();

        foreach (StaffNavigation::menus() as [$menu, , $links]) {
            foreach ($links as [$route, $label, , $ability]) {
                $this->assertTrue(Route::has($route), "{$menu} › {$label}: لا مسار باسم {$route}");

                if ($ability !== null) {
                    $this->assertContains($ability, $abilities, "{$menu} › {$label}: صلاحية مجهولة {$ability}");
                }
            }
        }
    }

    public function test_every_staff_screen_has_a_place_in_the_bar(): void
    {
        $linked = collect(StaffNavigation::menus())->flatMap(fn (array $menu) => array_column($menu[2], 0));

        $screens = collect(Route::getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true)
                && in_array('staff', $route->gatherMiddleware(), true)
                && ! str_contains($route->uri(), '{'))
            ->map(fn ($route) => $route->getName())
            ->filter();

        // حارسٌ لا يرى شاشاتٍ لا يحرس شيئاً
        $this->assertGreaterThan(30, $screens->count());

        $missing = $screens
            ->reject(fn (string $name) => $linked->contains($name) || in_array($name, self::OPENED_FROM_ELSEWHERE, true))
            ->values()
            ->all();

        $this->assertSame([], $missing, "شاشات لا يبلغها الشريط:\n".implode("\n", $missing));
    }

    public function test_a_link_shows_only_to_whoever_can_open_it(): void
    {
        // خدمة العملاء: تقرأ وتُنشئ وتُجيب، ولا مال ولا نقل ولا إعدادات
        $agent = $this->makeUser($this->company, UserRole::CustomerService);

        $menus = Tenancy::runFor($this->company, fn () => StaffNavigation::for($agent, Request::create('/')));
        $labels = array_column($menus, 'label');

        $this->assertSame(['الصفحة الرئيسية', 'شحنات العميل', 'تقارير', 'تقارير مالية', 'المراجعة'], $labels);

        // والقائمة الباقية لا تحمل من روابطها إلا ما يُفتح
        $home = $menus[array_search('الصفحة الرئيسية', $labels, true)];
        $this->assertSame(['لوحة اليوم'], array_column($home['links'], 'label'));

        $finance = $menus[array_search('تقارير مالية', $labels, true)];
        $this->assertNotContains('كشف حساب الفرع', array_column($finance['links'], 'label'));
    }

    public function test_the_owner_sees_the_twelve_menus_in_order(): void
    {
        $owner = $this->makeUser($this->company);

        $this->actingAs($owner)
            ->get($this->host().'/')
            ->assertOk()
            ->assertSeeInOrder(self::ORDER);
    }

    public function test_the_current_screen_is_marked_in_the_bar(): void
    {
        $owner = $this->makeUser($this->company);

        $menus = Tenancy::runFor($this->company, function () use ($owner) {
            $request = Request::create($this->host().'/returns/sorting');
            $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

            return StaffNavigation::for($owner, $request);
        });

        $active = array_values(array_filter($menus, fn (array $menu) => $menu['active']));
        $this->assertSame(['تصفيات الراجع'], array_column($active, 'label'));

        $links = array_filter($active[0]['links'], fn (array $link) => $link['active']);
        $this->assertSame(['فرز الراجع للفروع'], array_column(array_values($links), 'label'));
    }

    public function test_a_notification_link_opens_with_its_audience_chosen(): void
    {
        $owner = $this->makeUser($this->company);

        $this->actingAs($owner)
            ->get($this->host().'/announcements?audience=merchants')
            ->assertOk()
            ->assertSee('value="merchants" required checked', false)
            ->assertDontSee('value="delivery_couriers" required checked', false);

        // جمهورٌ لا يعرفه النظام لا يُختار: الافتراضيّ كما كان
        $this->actingAs($owner)
            ->get($this->host().'/announcements?audience=everyone')
            ->assertOk()
            ->assertSee('value="delivery_couriers" required checked', false);
    }
}

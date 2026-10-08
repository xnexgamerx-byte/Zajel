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
 * الشريط العلوي: مرتّبٌ بما تفعله كل شاشة، ولكل شاشةٍ مكانٌ فيه (أو في «كل
 * التقارير»)، ولكل رابطٍ صلاحيته.
 *
 * الشريط هو الطريق الوحيد إلى أكثر الشاشات. شاشةٌ سقطت منه عند إعادة
 * ترتيبه لا يُبلغ عنها أحد — تختفي فحسب، ويظنّها الموظّف غير موجودة.
 */
class StaffNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** من اليمين إلى اليسار بعمل الشاشات (docs/plan/21 §١) */
    private const ORDER = [
        'الرئيسية', 'الشحنات', 'التوصيل', 'الراجع', 'الحسابات المالية', 'الموقف المالي والفروع', 'التقارير', 'المتابعة', 'الإعدادات',
    ];

    /** تقاريرٌ تُفتح من «كل التقارير» لا من الشريط: القائمة تحمل الأكثر سؤالاً وحده */
    private const FROM_REPORTS_PAGE = [
        'reports.returns-money', 'reports.dormant', 'reports.debtors', 'reports.changes', 'reports.entries',
        'reports.portal', 'reports.processing', 'reports.merchant-profit', 'reports.courier-overcharge',
        'reports.special-prices', 'reports.unconfirmed', 'reports.notifications',
        'reports.pickup-received', 'reports.pickup-performance', 'reports.unsettled', 'reports.repriced',
        'reports.distribution', 'reports.branch-traffic',
    ];

    /** شاشاتٌ تُفتح من داخل غيرها لا من الشريط: نماذج الإضافة، والطباعة، وقالب الاستيراد */
    private const OPENED_FROM_ELSEWHERE = [
        'branches.create', 'couriers.create', 'merchants.create', 'users.create',
        'shipments.import.template', 'branch-accounts.statement.print', 'shipments.labels',
        // خانتان في شاشة «الصلاحيات والمراتب»
        'permissions.ranks.index', 'permissions.ranks.create',
        // زرّا Excel وPDF في قائمة الشحنات
        'shipments.export', 'shipments.export.print',
        // يسأله جدول المسح عن كل وصل، ومربّع المسح في شاشات الراجع
        'shipments.scan.lookup', 'returns.lookup',
        // إيصالات التسليم لمندوب الاستلام، من رسالة نجاحه
        'return-batches.print-many',
        // كشف كل رواجع التاجر في مدّة، من «إيصالات الراجع» بعد اختياره
        'return-batches.merchant',
        // «صندوقي» من شارة رصيده في الرأس، لصاحب الصندوق وحده
        'cash.mine',
        // «خصّص الرئيسية» من زرّها في «لوحة اليوم»، و«رئيسيّتها» بجانب كل مرتبة
        'home.customize',
        // الأكياس والكشوف اليدوية و«وصل ناقص؟» من داخل «النقل بين الفروع»
        'bags.index', 'manifests.index', 'manifests.inbound',
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

    public function test_menus_follow_the_work_order(): void
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
            ->reject(fn (string $name) => $linked->contains($name)
                || in_array($name, self::OPENED_FROM_ELSEWHERE, true)
                || in_array($name, self::FROM_REPORTS_PAGE, true))
            ->values()
            ->all();

        $this->assertSame([], $missing, "شاشات لا يبلغها الشريط:\n".implode("\n", $missing));
    }

    /** وما لا يحمله الشريط من التقارير تحمله صفحة «كل التقارير» — لمن يملكها */
    public function test_every_report_left_out_of_the_bar_is_on_the_reports_page(): void
    {
        $owner = $this->makeUser($this->company);
        $page = $this->actingAs($owner)->get($this->host().'/reports')->assertOk();

        foreach (self::FROM_REPORTS_PAGE as $route) {
            $page->assertSee(Tenancy::runFor($this->company, fn () => route($route)), false);
        }
    }

    public function test_a_link_shows_only_to_whoever_can_open_it(): void
    {
        // الكول سنتر: تتابع الشحنة وتحدّث حالتها وتعالج، ولا مال ولا نقل ولا إعدادات (docs/plan/30)
        $agent = $this->makeUser($this->company, UserRole::CustomerService);

        $menus = Tenancy::runFor($this->company, fn () => StaffNavigation::for($agent, Request::create('/')));
        $labels = array_column($menus, 'label');

        // لا مال ولا راجع ولا إعدادات؛ ومن التوصيل: المراحل والمسح والمعالجة
        $this->assertSame(['الرئيسية', 'الشحنات', 'التوصيل', 'التقارير', 'المتابعة'], $labels);
        $delivery = $menus[array_search('التوصيل', $labels, true)];
        $this->assertSame(['كل مراحل النقل', 'استلام وتوزيع بالمسح', 'شحنات لم تُسلَّم (للمعالجة)'],
            array_column($delivery['links'], 'label'));
        $review = $menus[array_search('المتابعة', $labels, true)];
        $this->assertSame(['المحادثات', 'طلبات المناديب لتغيير المبلغ'], array_column($review['links'], 'label'));

        // والقائمة التي بقي فيها رابطٌ واحد رابطٌ مباشر: لا قائمة تنسدل بسطرٍ واحد —
        // المحاسب يرى من التوصيل «كل مراحل النقل» وحدها: عدّاداتٌ للقراءة
        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        $accounts = Tenancy::runFor($this->company, fn () => StaffNavigation::for($accountant, Request::create('/')));
        $delivery = collect($accounts)->firstWhere('label', 'التوصيل');
        $this->assertSame(['كل مراحل النقل'], array_column($delivery['links'], 'label'));
        $this->assertSame($delivery['links'][0]['url'], $delivery['url']);
        $home = $menus[array_search('الرئيسية', $labels, true)];
        $this->assertSame(['لوحة اليوم'], array_column($home['links'], 'label'));
        $this->assertNotNull($home['url']);

        // والتقارير: أرباح الشحنات لمن يرى أرباح الشركة وحده
        $reports = $menus[array_search('التقارير', $labels, true)];
        $this->assertNotContains('أرباح الشحنات', array_column($reports['links'], 'label'));
        $this->assertNull($reports['url']);

        $manager = $this->makeUser($this->company, UserRole::BranchManager);
        $menus = Tenancy::runFor($this->company, fn () => StaffNavigation::for($manager, Request::create('/')));
        $reports = collect($menus)->firstWhere('label', 'التقارير');
        $this->assertContains('أرباح الشحنات', array_column($reports['links'], 'label'));
    }

    public function test_the_owner_sees_every_menu_in_order(): void
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
        $this->assertSame(['الراجع'], array_column($active, 'label'));

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

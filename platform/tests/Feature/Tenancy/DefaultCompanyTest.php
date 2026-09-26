<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tracking;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نظامٌ بلا نطاق: عنوان Railway المجاني عنوانٌ واحد، فتُخدَم عليه الشركة
 * الافتراضية، ولوحة المنصّة تحت /admin.
 *
 * في بيئة production كالإنتاج: في testing تُعرَف الشركة من الجلسة أيضاً،
 * فيمرّ الاختبار ولو كان الطريق الحقيقيّ معطّلاً.
 */
class DefaultCompanyTest extends TestCase
{
    use RefreshDatabase;

    private const FREE_ADDRESS = 'zajel-production.up.railway.app';

    private Company $barq;

    private Company $zajel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->barq = $this->makeCompany('barq', 'البرق');
        $this->zajel = $this->makeCompany('zajel', 'الزاجل');
        $this->makeMerchant($this->barq);
        $this->makeMerchant($this->zajel);

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        config(['zajel.default_company' => 'barq']);

        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function free(string $path = ''): string
    {
        return 'http://'.self::FREE_ADDRESS.$path;
    }

    private function subdomain(string $slug, string $path = ''): string
    {
        return "http://{$slug}.".config('zajel.tenant_domain').$path;
    }

    public function test_the_free_address_serves_the_default_company(): void
    {
        $this->get($this->free('/login'))->assertOk()->assertSee('البرق')->assertDontSee('الزاجل');
    }

    public function test_a_company_subdomain_still_serves_its_own_company(): void
    {
        $this->get($this->subdomain('zajel', '/login'))->assertOk()->assertSee('الزاجل')->assertDontSee('البرق');
    }

    public function test_a_mistyped_subdomain_does_not_fall_back_to_the_default(): void
    {
        $this->get($this->subdomain('barqq', '/login'))->assertNotFound();
        $this->get($this->subdomain('admin', '/login'))->assertNotFound();
    }

    public function test_without_a_default_company_the_free_address_opens_nothing(): void
    {
        config(['zajel.default_company' => null]);

        $this->get($this->free('/login'))->assertNotFound();
    }

    public function test_the_owner_works_on_the_free_address(): void
    {
        $owner = $this->makeUser($this->barq, UserRole::CompanyOwner);

        $this->actingAs($owner)->get($this->free('/shipments'))->assertOk();
    }

    public function test_the_platform_panel_opens_on_the_free_address(): void
    {
        $this->get($this->free('/admin/login'))->assertOk();
        $this->actingAs($this->admin)->get($this->free('/admin'))->assertOk();
    }

    public function test_entering_the_company_from_the_platform_and_leaving_stay_on_one_address(): void
    {
        $owner = $this->makeUser($this->barq, UserRole::CompanyOwner);

        $ticket = $this->actingAs($this->admin)
            ->post($this->free("/admin/companies/{$this->barq->id}/impersonate"))
            ->headers->get('Location');

        $this->assertStringStartsWith($this->free('/impersonate/'), $ticket);

        $this->get($ticket)->assertRedirect($this->free('/shipments'));
        $this->assertSame($owner->id, auth()->id());

        $this->post($this->free('/stop-impersonating'))->assertRedirect($this->free('/admin'));
        $this->assertGuest();
    }

    public function test_a_company_page_opened_first_does_not_follow_the_admin_into_the_panel(): void
    {
        // الجلسة واحدة على العنوان: الزائر يُعاد إلى دخول الشركة، والصفحة التي قصدها محفوظة
        $this->get($this->free('/shipments'))->assertRedirect($this->free('/login'));

        $this->post($this->free('/admin/login'), ['phone' => '07700000000', 'password' => 'password'])
            ->assertRedirect($this->free('/admin'));
    }

    public function test_a_panel_page_opened_first_does_not_follow_the_owner_into_the_company(): void
    {
        $owner = $this->makeUser($this->barq, UserRole::CompanyOwner);

        $this->get($this->free('/admin/companies'))->assertRedirect($this->free('/admin/login'));

        $this->post($this->free('/login'), ['phone' => $owner->phone, 'password' => 'password'])
            ->assertRedirect($this->free());
    }

    public function test_a_page_on_the_same_side_is_still_where_login_leads(): void
    {
        $this->get($this->free('/admin/companies'))->assertRedirect($this->free('/admin/login'));

        $this->post($this->free('/admin/login'), ['phone' => '07700000000', 'password' => 'password'])
            ->assertRedirect($this->free('/admin/companies'));
    }

    public function test_the_panel_says_why_the_free_address_opens_nothing(): void
    {
        $warning = 'لا يفتح نظام أيّ شركة';

        $this->actingAs($this->admin)->get($this->free('/admin'))->assertOk()->assertDontSee($warning);

        config(['zajel.default_company' => 'barqq']);
        $this->get($this->free('/admin'))
            ->assertSee($warning)
            ->assertSee('ZAJEL_DEFAULT_COMPANY=barqq');

        config(['zajel.default_company' => '']);
        $this->get($this->free('/admin'))
            ->assertSee($warning)
            ->assertSee('لم يصل إلى الخدمة');

        // بنطاق: اللوحة على admin. والشركات على نطاقاتها، فلا عنوانَ ينتظر شركة
        $this->get('http://admin.'.config('zajel.tenant_domain').'/admin')->assertOk()->assertDontSee($warning);
    }

    public function test_the_default_company_page_shows_the_address_its_system_answers_on(): void
    {
        $this->actingAs($this->admin)
            ->get($this->free("/admin/companies/{$this->barq->id}"))
            ->assertOk()
            ->assertSee('نظامها الآن على')
            ->assertSee(self::FREE_ADDRESS);

        $this->get($this->free("/admin/companies/{$this->zajel->id}"))
            ->assertOk()
            ->assertDontSee('نظامها الآن على');
    }

    public function test_the_settings_forgive_capitals_spaces_and_quotes(): void
    {
        // يُكتبان في متغيّرات الاستضافة باليد، والمضيف يُقارَن بهما حرفاً بحرف
        $_SERVER['ZAJEL_TENANT_DOMAIN'] = ' Wahaj.IQ ';
        $_SERVER['ZAJEL_DEFAULT_COMPANY'] = ' "Barq" ';

        try {
            $settings = require config_path('zajel.php');
        } finally {
            unset($_SERVER['ZAJEL_TENANT_DOMAIN'], $_SERVER['ZAJEL_DEFAULT_COMPANY']);
        }

        $this->assertSame('wahaj.iq', $settings['tenant_domain']);
        $this->assertSame('barq', $settings['default_company']);
    }

    public function test_a_tracking_link_printed_on_the_free_address_opens_there(): void
    {
        $owner = $this->makeUser($this->barq, UserRole::CompanyOwner);

        $shipment = Tenancy::runFor($this->barq, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => \App\Models\Merchant::firstOrFail()->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'الكرادة',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => 25_000,
        ], $owner));

        $this->get($this->free('/t/'.$shipment->number.'/'.Tracking::token($shipment)))
            ->assertOk()
            ->assertSee($shipment->number);
    }
}

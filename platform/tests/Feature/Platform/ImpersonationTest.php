<?php

namespace Tests\Feature\Platform;

use App\Actions\Platform\ImpersonateCompany;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * دخول مدير المنصّة إلى نظام شركة — بالشكل الذي يعمل به في الإنتاج.
 *
 * كانت اختبارات الدخول تمرّ والدخول معطَّل في الإنتاج: الشركة في بيئة
 * الاختبار تُعرَف من الجلسة إن لم يُعرِّفها النطاق، وهو طريقٌ مغلق في
 * الإنتاج عمداً. فتُجرى هنا في بيئة production، على نطاقين فرعيين كما
 * يُنشَر النظام: admin.{النطاق} للمنصّة و{الشركة}.{النطاق} للشركة.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->company = $this->makeCompany('barq', 'البرق');
        $this->owner = $this->makeUser($this->company, UserRole::CompanyOwner);
    }

    private function platform(string $path = ''): string
    {
        return 'http://admin.'.config('zajel.tenant_domain').$path;
    }

    private function tenant(string $slug = 'barq', string $path = ''): string
    {
        return "http://{$slug}.".config('zajel.tenant_domain').$path;
    }

    /**
     * بعد البذر: أوامر البذر في production تطلب تأكيداً فتتوقّف. وحماية
     * CSRF تعمل خارج بيئة testing وحدها، وليست موضوع الاختبار.
     */
    private function inProduction(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    }

    private function startFromThePlatform(): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post($this->platform("/admin/companies/{$this->company->id}/impersonate"));
    }

    public function test_the_platform_admin_enters_a_company_on_its_own_subdomain(): void
    {
        $this->inProduction();

        $start = $this->startFromThePlatform();
        $ticket = $start->headers->get('Location');

        // التذكرة على نطاق الشركة، وجلسة المنصّة ما زالت للمدير
        $this->assertMatchesRegularExpression('#^'.preg_quote($this->tenant('barq', '/impersonate/'), '#').'[A-Za-z0-9]{64}$#', $ticket);
        $this->assertSame($this->admin->id, auth()->id());

        $this->get($ticket)->assertRedirect($this->tenant('barq', '/shipments'));

        $this->assertSame($this->owner->id, auth()->id());
        $this->assertSame($this->admin->id, session(ImpersonateCompany::SESSION_KEY));

        // الصفحة التي كانت 404 في الإنتاج
        $this->get($this->tenant('barq', '/shipments'))
            ->assertOk()
            ->assertSee('أنت داخل نظام البرق من لوحة المنصّة');

        $entry = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'impersonation_started')->firstOrFail());
        $this->assertSame($this->company->id, $entry->company_id);
        $this->assertSame($this->admin->id, $entry->impersonator_user_id);
        $this->assertSame($this->owner->id, $entry->user_id);
    }

    public function test_a_ticket_opens_once(): void
    {
        $this->inProduction();
        $ticket = $this->startFromThePlatform()->headers->get('Location');

        $this->get($ticket)->assertRedirect();
        auth()->logout();

        $this->get($ticket)->assertNotFound();
        $this->assertGuest();
    }

    public function test_a_ticket_lives_one_minute(): void
    {
        $this->inProduction();
        $ticket = $this->startFromThePlatform()->headers->get('Location');
        auth()->logout();

        $this->travel(ImpersonateCompany::TICKET_SECONDS + 1)->seconds();

        $this->get($ticket)->assertNotFound();
        $this->assertGuest();
    }

    public function test_a_ticket_for_one_company_does_not_open_another(): void
    {
        $other = $this->makeCompany('zajel', 'الزاجل');
        $this->makeUser($other, UserRole::CompanyOwner);

        $this->inProduction();
        $ticket = $this->startFromThePlatform()->headers->get('Location');
        auth()->logout();

        $elsewhere = str_replace($this->tenant('barq'), $this->tenant('zajel'), $ticket);

        $this->get($elsewhere)->assertNotFound();
        $this->assertGuest();
    }

    public function test_leaving_ends_the_company_session_and_returns_to_the_platform(): void
    {
        $this->inProduction();
        $this->get($this->startFromThePlatform()->headers->get('Location'));

        $this->post($this->tenant('barq', '/stop-impersonating'))
            ->assertRedirect($this->platform('/admin'));

        // جلسة الشركة انتهت. (جلسة المنصّة على نطاقها لم تُمسّ — كعكة كل
        // نطاقٍ له وحده، وهذا لا يُرى في بيئة الاختبار حيث الجلسة واحدة.)
        $this->assertGuest();

        Tenancy::runAsPlatform(fn () => $this->assertSame(
            1, AuditLog::where('action', 'impersonation_ended')->where('impersonator_user_id', $this->admin->id)->count()
        ));
    }

    public function test_a_company_user_leaving_without_impersonating_just_goes_home(): void
    {
        $this->actingAs($this->owner)
            ->post($this->tenant('barq', '/stop-impersonating'))
            ->assertRedirect($this->tenant('barq'));

        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_a_company_user_cannot_impersonate(): void
    {
        $this->actingAs($this->owner)
            ->post($this->platform("/admin/companies/{$this->company->id}/impersonate"))
            ->assertForbidden();
    }

    /** التطوير بلا نطاقاتٍ فرعية: المضيف نفسه، والشركة من ?company= */
    public function test_it_still_works_on_a_single_development_host(): void
    {
        $ticket = $this->actingAs($this->admin)
            ->post("/admin/companies/{$this->company->id}/impersonate")
            ->headers->get('Location');

        $this->assertMatchesRegularExpression(
            '#^'.preg_quote(rtrim(config('app.url'), '/'), '#').'/impersonate/[A-Za-z0-9]{64}\?company=barq$#',
            $ticket,
        );

        $this->get($ticket)->assertRedirect();
        $this->assertSame($this->owner->id, auth()->id());
    }
}

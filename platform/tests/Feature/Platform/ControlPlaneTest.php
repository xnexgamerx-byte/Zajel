<?php

namespace Tests\Feature\Platform;

use App\Actions\Platform\ImpersonateCompany;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Hub;
use App\Models\Plan;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة النواة هي التجاوز الصريح الوحيد لعزل المستأجرين، فاختبارها
 * يركّز على من يُسمح له بالدخول قبل ما يستطيع فعله.
 */
class ControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'           => 'البرق للتوصيل',
            'slug'           => 'barq',
            'status'         => 'trial',
            'owner_name'     => 'سيف البرق',
            'owner_phone'    => '07711112222',
            'owner_password' => 'secret123',
            'plan_id'        => Plan::where('code', 'growth')->value('id'),
            'billing_cycle'  => 'monthly',
        ], $overrides);
    }

    // --------------------------------------------------------- من يدخل

    public function test_a_guest_is_sent_to_the_platform_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_company_user_cannot_reach_the_platform_panel(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeUser($company);

        $this->actingAs($owner)->get('/admin')->assertForbidden();
        $this->actingAs($owner)->get('/admin/companies')->assertForbidden();
    }

    public function test_a_company_user_cannot_log_in_through_the_platform_login(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeUser($company);

        $this->post('/admin/login', ['phone' => $owner->phone, 'password' => 'password'])
            ->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    public function test_the_platform_admin_logs_in_and_sees_the_dashboard(): void
    {
        $this->post('/admin/login', ['phone' => '07700000000', 'password' => 'password'])
            ->assertRedirect('/admin');

        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('نظرة عامة');
    }

    // ------------------------------------------------------ تسجيل شركة

    public function test_registering_a_company_hands_over_a_system_that_works(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/companies', $this->payload())
            ->assertSessionHas('success');

        $company = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());

        $this->assertSame('trial', $company->status);
        $this->assertNotNull($company->trial_ends_at);

        // نظام لا ينقصه شيء ليستقبل أول شحنة
        Tenancy::runFor($company, function () {
            $this->assertSame(1, Branch::where('is_main', true)->count());
            $this->assertSame(1, Hub::count());
            $this->assertSame(1, PriceList::where('is_default', true)->count());
            $this->assertSame(1, PriceListRule::count());

            $owner = User::where('role', UserRole::CompanyOwner)->firstOrFail();
            $this->assertSame('07711112222', $owner->phone);

            $this->assertSame('trialing', Subscription::firstOrFail()->status);
        });
    }

    public function test_the_owner_can_log_into_the_new_system_and_create_a_shipment(): void
    {
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload());

        $company = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());
        $host = 'http://barq.'.config('zajel.tenant_domain');

        // العميل يصل بجلسة نظيفة، لا بجلسة مدير المنصّة
        auth()->logout();
        $this->flushSession();

        // الموظّف يدخل على لوحة اليوم
        $this->post($host.'/login', ['phone' => '07711112222', 'password' => 'secret123'])
            ->assertRedirect($host);

        $owner = Tenancy::runFor($company, fn () => User::where('phone', '07711112222')->firstOrFail());
        $merchant = $this->makeMerchant($company, 'M0001');

        $this->actingAs($owner)->post($host.'/shipments', [
            'merchant_id'     => $merchant->id,
            'recipient_name'  => 'زبون أول',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'pieces_count'    => 1,
            'cod_amount'      => 50_000,
            'fees_paid_by'    => 'merchant',
        ])->assertSessionHasNoErrors();

        // السعر جاء من التسعيرة التي أُنشئت مع الشركة، لا صفراً
        $shipment = Tenancy::runFor($company, fn () => Shipment::firstOrFail());
        $this->assertSame(5000, $shipment->delivery_fee);
        $this->assertSame(45_000, $shipment->merchant_due);
    }

    public function test_a_taken_or_reserved_subdomain_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload());

        $this->actingAs($this->admin)
            ->post('/admin/companies', $this->payload(['name' => 'شركة أخرى', 'owner_phone' => '07711113333']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)
            ->post('/admin/companies', $this->payload(['slug' => 'admin', 'owner_phone' => '07711114444']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)
            ->post('/admin/companies', $this->payload(['slug' => 'Al Zaeem', 'owner_phone' => '07711115555']))
            ->assertSessionHasErrors('slug');
    }

    // ---------------------------------------------------------- الإيقاف

    public function test_suspending_locks_the_company_out_on_the_next_request(): void
    {
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload(['status' => 'active']));

        $company = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());
        $owner = Tenancy::runFor($company, fn () => User::where('phone', '07711112222')->firstOrFail());
        $host = 'http://barq.'.config('zajel.tenant_domain');

        $this->actingAs($owner)->get($host.'/shipments')->assertOk();

        $this->actingAs($this->admin)
            ->post("/admin/companies/{$company->id}/suspend", ['reason' => 'تأخّر السداد'])
            ->assertSessionHas('success');

        $this->actingAs($owner)->get($host.'/shipments')->assertForbidden();

        $this->actingAs($this->admin)->post("/admin/companies/{$company->id}/activate");
        $this->actingAs($owner)->get($host.'/shipments')->assertOk();
    }

    public function test_suspending_without_a_reason_is_refused(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->admin)
            ->post("/admin/companies/{$company->id}/suspend", [])
            ->assertSessionHasErrors('reason');
    }

    // ----------------------------------------------------- الانتحال

    public function test_impersonation_logs_who_entered_which_company(): void
    {
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload(['status' => 'active']));
        $company = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());

        $this->actingAs($this->admin)
            ->post("/admin/companies/{$company->id}/impersonate")
            ->assertRedirect();

        $entry = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'impersonation_started')->firstOrFail());

        $this->assertSame($company->id, $entry->company_id);
        $this->assertSame($this->admin->id, $entry->impersonator_user_id);

        // صار المستخدم الحالي صاحب الشركة، والجلسة تحمل من انتحل
        $this->assertSame('07711112222', auth()->user()->phone);
        $this->assertSame($this->admin->id, session(ImpersonateCompany::SESSION_KEY));
    }

    public function test_stopping_impersonation_returns_the_platform_admin(): void
    {
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload(['status' => 'active']));
        $company = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());

        $this->actingAs($this->admin)->post("/admin/companies/{$company->id}/impersonate");

        $this->post('http://barq.'.config('zajel.tenant_domain').'/stop-impersonating')
            ->assertRedirect('/admin');

        $this->assertSame($this->admin->id, auth()->id());
        $this->assertNull(session(ImpersonateCompany::SESSION_KEY));

        Tenancy::runAsPlatform(fn () => $this->assertSame(
            1, AuditLog::where('action', 'impersonation_ended')->count()
        ));
    }

    public function test_a_company_user_cannot_impersonate(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeUser($company);

        $this->actingAs($owner)
            ->post("/admin/companies/{$company->id}/impersonate")
            ->assertForbidden();
    }

    // ------------------------------------------------------------ الباقات

    public function test_editing_a_plan_leaves_existing_subscriptions_priced_as_signed(): void
    {
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload());

        $company = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());
        $plan = Plan::where('code', 'growth')->firstOrFail();
        $signedPrice = Tenancy::runFor($company, fn () => Subscription::firstOrFail()->price);

        $this->actingAs($this->admin)->put("/admin/plans/{$plan->id}", [
            'code' => 'growth', 'name' => 'النمو',
            'price_monthly' => 900_000, 'price_yearly' => 9_000_000,
            'commission_per_shipment' => 0, 'commission_percent' => 0,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(900_000, (int) $plan->fresh()->price_monthly);

        Tenancy::runFor($company, fn () => $this->assertSame(
            $signedPrice, Subscription::firstOrFail()->price
        ));
    }

    public function test_the_platform_sees_across_companies_while_a_tenant_never_does(): void
    {
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload(['status' => 'active']));
        $this->actingAs($this->admin)->post('/admin/companies', $this->payload([
            'name' => 'الزعيم', 'slug' => 'zaeem', 'owner_phone' => '07711119999', 'status' => 'active',
        ]));

        $this->actingAs($this->admin)
            ->get('/admin/companies')
            ->assertOk()
            ->assertSee('البرق للتوصيل')
            ->assertSee('الزعيم');

        $barq = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());
        $owner = Tenancy::runFor($barq, fn () => User::where('phone', '07711112222')->firstOrFail());

        // داخل شركة، جدول الشركات نفسه لا يكشف إلا صاحبته
        Tenancy::runFor($barq, function () use ($barq) {
            $this->assertSame(1, Company::count());
            $this->assertSame($barq->id, Company::firstOrFail()->id);
            $this->assertNull(Company::where('slug', 'zaeem')->first());
        });

        $this->assertSame($barq->id, $owner->company_id);
    }
}

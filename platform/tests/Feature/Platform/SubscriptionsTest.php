<?php

namespace Tests\Feature\Platform;

use App\Actions\Billing\GenerateInvoice;
use App\Actions\Billing\RenewSubscriptions;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PriceListRule;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «الاشتراكات»: مال المنصّة مع الشركات في خانةٍ وحدها، بعيداً عن أسعار
 * التوصيل التي هي شأن كل شركةٍ مع تجّارها.
 *
 * الاشتراك يبدأ بسعر الباقة أو بسعرٍ متّفقٍ عليه، ويحلّ محلّ القائم ولا
 * يمحوه — فاتورة شهرٍ مضى تُحسب بما كان سارياً فيه — ويُلغى فيتوقّف تجديده.
 */
class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->company = $this->makeCompany('zajel', 'الزاجل');
    }

    private function plan(string $code): Plan
    {
        return Plan::where('code', $code)->firstOrFail();
    }

    private function start(array $overrides = [], ?Company $company = null): \Illuminate\Testing\TestResponse
    {
        $company ??= $this->company;

        return $this->actingAs($this->admin)->post("/admin/subscriptions/{$company->id}", array_merge([
            'plan_id'       => $this->plan('basic')->id,
            'billing_cycle' => 'monthly',
            'price'         => '',
            'starts_at'     => now()->toDateString(),
            'notes'         => '',
        ], $overrides));
    }

    /** @return \Illuminate\Support\Collection<int, Subscription> */
    private function subscriptions(?Company $company = null): \Illuminate\Support\Collection
    {
        return Tenancy::runFor($company ?? $this->company, fn () => Subscription::orderBy('id')->get());
    }

    // ------------------------------------------------------------ الخانة

    public function test_subscriptions_have_their_own_tab_apart_from_pricing(): void
    {
        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertSee(route('admin.subscriptions.index'), false)
            ->assertSee('الاشتراكات');

        $this->actingAs($this->admin)->get('/admin/subscriptions')
            ->assertOk()
            ->assertSee('الزاجل')
            ->assertSee('أضف اشتراكاً')
            ->assertSee('أسعار التوصيل ليست هنا');
    }

    public function test_the_list_shows_what_each_company_pays_and_still_owes(): void
    {
        $this->start(['plan_id' => $this->plan('basic')->id]);
        $barq = $this->makeCompany('barq', 'البرق');

        Tenancy::runFor($this->company, function () {
            $invoice = Invoice::create([
                'number' => 'INV-TEST-1', 'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
                'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
                'total' => 250_000, 'amount_paid' => 100_000, 'status' => 'issued',
            ]);
            Payment::create(['invoice_id' => $invoice->id, 'amount' => 100_000, 'method' => 'cash', 'paid_at' => now()]);
        });

        $this->actingAs($this->admin)->get('/admin/subscriptions')
            ->assertOk()
            ->assertSee('الأساسية')
            ->assertSee('250,000')          // تدفع شهرياً، والدخل الشهري نفسه
            ->assertSee('150,000')          // ما بقي من فاتورتها
            ->assertSee('100,000')          // محصَّل هذا الشهر
            ->assertSee('البرق')
            ->assertSee('لا اشتراك: لا تدفع شيئاً الآن.');

        $this->assertNotNull($barq);
    }

    // ------------------------------------------------------------ البدء

    public function test_a_company_without_a_subscription_starts_one_at_an_agreed_price(): void
    {
        // كما يُكتب على لوحة المفاتيح العربية: أرقامٌ هندية وفاصل آلاف
        $this->start(['plan_id' => $this->plan('growth')->id, 'price' => '٣٠٠٬٠٠٠', 'notes' => 'خصم الإطلاق'])
            ->assertRedirect("/admin/subscriptions/{$this->company->id}")
            ->assertSessionHas('success', 'بدأ اشتراك الزاجل: 300,000 د.ع شهرياً.');

        $subscription = $this->subscriptions()->sole();
        $this->assertSame('active', $subscription->status);
        $this->assertSame(300_000, (int) $subscription->price);
        $this->assertSame($this->plan('growth')->id, $subscription->plan_id);
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $subscription->ends_at->toDateString());
        $this->assertTrue($subscription->auto_renew);
        $this->assertSame('خصم الإطلاق', $subscription->notes);

        $audit = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'subscription_started')->sole());
        $this->assertNull($audit->old_values);
        $this->assertSame(['plan' => 'النمو', 'price' => 300_000, 'billing_cycle' => 'monthly'],
            array_intersect_key($audit->new_values, array_flip(['plan', 'price', 'billing_cycle'])));
    }

    public function test_an_empty_price_is_the_plans_price_for_the_cycle(): void
    {
        $this->start(['plan_id' => $this->plan('growth')->id, 'billing_cycle' => 'yearly'])->assertSessionHasNoErrors();

        $subscription = $this->subscriptions()->sole();
        $this->assertSame((int) $this->plan('growth')->price_yearly, (int) $subscription->price);
        $this->assertSame(now()->addYearNoOverflow()->toDateString(), $subscription->ends_at->toDateString());
    }

    public function test_a_month_from_the_thirty_first_ends_on_the_last_day_of_february(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-20 10:00'));

        $this->start(['starts_at' => '2027-01-31'])->assertSessionHasNoErrors();

        $this->assertSame('2027-02-28', $this->subscriptions()->sole()->ends_at->toDateString());
    }

    public function test_a_paid_subscription_ends_the_trial(): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->update(['status' => 'trial', 'trial_ends_at' => now()->addDays(10)]));

        $this->start()->assertSessionHasNoErrors();

        $company = Tenancy::runAsPlatform(fn () => $this->company->fresh());
        $this->assertSame('active', $company->status);
        $this->assertNull($company->trial_ends_at);
    }

    // ----------------------------------------------------------- التغيير

    public function test_a_new_subscription_replaces_the_old_one_which_stays_in_the_history(): void
    {
        $this->start(['plan_id' => $this->plan('basic')->id]);
        $this->start(['plan_id' => $this->plan('growth')->id, 'price' => '400000']);

        [$old, $new] = $this->subscriptions()->all();

        $this->assertSame('cancelled', $old->status);
        $this->assertNotNull($old->cancelled_at);
        $this->assertFalse($old->auto_renew);
        $this->assertSame('active', $new->status);

        // وفاتورة هذا الشهر بالجديد: الأحدث يغلب في شهر التغيير
        $invoice = app(GenerateInvoice::class)->handle($this->company, CarbonImmutable::now()->startOfMonth());
        $this->assertSame(400_000, (int) $invoice->subscription_amount);

        $this->actingAs($this->admin)->get("/admin/subscriptions/{$this->company->id}")
            ->assertOk()
            ->assertSee('سجلّ اشتراكاتها')
            ->assertSee('الأساسية')
            ->assertSee('ملغى')
            ->assertSee('400,000');
    }

    // ------------------------------------------------------------ الإلغاء

    public function test_cancelling_stops_renewal_and_leaves_the_company_working(): void
    {
        $this->start();

        $this->actingAs($this->admin)->post("/admin/subscriptions/{$this->company->id}/cancel")
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'أُلغي اشتراك الزاجل'));

        $subscription = $this->subscriptions()->sole();
        $this->assertSame('cancelled', $subscription->status);
        $this->assertFalse($subscription->auto_renew);
        $this->assertSame('active', Tenancy::runAsPlatform(fn () => $this->company->fresh()->status));
        $this->assertSame(1, Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'subscription_cancelled')->count()));

        // بعد أجله لا يُجدَّد، وصفحة الشركة لا تعرضه قائماً
        $endsAt = $subscription->ends_at->toDateString();
        app(RenewSubscriptions::class)->handle(now()->addMonths(2));
        $this->assertSame($endsAt, $this->subscriptions()->sole()->ends_at->toDateString());

        $this->actingAs($this->admin)->get("/admin/companies/{$this->company->id}")
            ->assertOk()
            ->assertSee('لا اشتراك فعّال.');

        // ولا شيء يُلغى مرّتين
        $this->actingAs($this->admin)->post("/admin/subscriptions/{$this->company->id}/cancel")
            ->assertSessionHas('success', 'لا اشتراك قائم لإلغائه.');
    }

    // ------------------------------------------------------------ الحراسة

    public function test_bad_input_is_refused_and_nothing_starts(): void
    {
        $retired = Plan::create([
            'code' => 'old', 'name' => 'قديمة', 'price_monthly' => 1, 'price_yearly' => 1, 'is_active' => false,
        ]);

        $this->start(['plan_id' => $retired->id])->assertSessionHasErrors('plan_id');
        $this->start(['billing_cycle' => 'weekly'])->assertSessionHasErrors('billing_cycle');
        $this->start(['price' => 'مجاناً'])->assertSessionHasErrors('price');
        $this->start(['starts_at' => now()->subMonths(3)->toDateString()])->assertSessionHasErrors('starts_at');

        $this->assertCount(0, $this->subscriptions());
    }

    public function test_a_company_user_cannot_reach_the_subscriptions(): void
    {
        $owner = $this->makeUser($this->company);

        $this->actingAs($owner)->get('/admin/subscriptions')->assertForbidden();
        $this->actingAs($owner)->get("/admin/subscriptions/{$this->company->id}")->assertForbidden();
        $this->actingAs($owner)->post("/admin/subscriptions/{$this->company->id}", [
            'plan_id' => $this->plan('basic')->id, 'billing_cycle' => 'monthly',
            'price' => '0', 'starts_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertCount(0, $this->subscriptions());
    }

    // -------------------------------------------------- التسجيل بلا تسعير

    public function test_registering_a_company_leaves_delivery_prices_to_the_company(): void
    {
        $this->actingAs($this->admin)->get('/admin/companies/create')
            ->assertOk()
            ->assertDontSee('name="default_delivery_fee"', false)
            ->assertDontSee('name="default_return_fee"', false)
            ->assertSee('«التسعيرات» في نظامها');

        // وتبدأ مع ذلك بتسعيرةٍ تعمل من أوّل شحنة
        $this->actingAs($this->admin)->post('/admin/companies', [
            'name' => 'البرق للتوصيل', 'slug' => 'barq', 'status' => 'trial',
            'owner_name' => 'سيف', 'owner_phone' => '07711112222', 'owner_password' => 'secret123',
        ])->assertSessionHasNoErrors();

        $barq = Tenancy::runAsPlatform(fn () => Company::where('slug', 'barq')->firstOrFail());
        $rule = Tenancy::runFor($barq, fn () => PriceListRule::sole());
        $this->assertSame(5000, (int) $rule->delivery_fee);
        $this->assertSame(2500, (int) $rule->return_fee);
    }
}

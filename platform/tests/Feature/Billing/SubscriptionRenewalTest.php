<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\RenewSubscriptions;
use App\Actions\Platform\RegisterCompany;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تجديد الاشتراكات، وخطّ انتهائها في لوحة المنصّة.
 *
 * كان auto_renew علَماً لا يقرؤه شيء، فكل اشتراكٍ شهريّ «ينتهي» بعد شهره
 * الأوّل ويبقى منتهياً إلى الأبد — واللوحة تعرضه «ينتهي اليوم».
 */
class SubscriptionRenewalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->plan = Plan::where('code', 'growth')->firstOrFail();
    }

    private function subscription(array $overrides = []): Subscription
    {
        return Tenancy::runFor($this->company, fn () => Subscription::create(array_merge([
            'plan_id' => $this->plan->id, 'status' => 'active', 'billing_cycle' => 'monthly',
            'price' => 100_000, 'starts_at' => '2026-01-15', 'ends_at' => '2026-02-15', 'auto_renew' => true,
        ], $overrides)));
    }

    private function renew(string $today): void
    {
        app(RenewSubscriptions::class)->handle(Carbon::parse($today));
    }

    private function endsAt(Subscription $subscription): string
    {
        return Tenancy::runAsPlatform(fn () => Subscription::acrossCompanies()->findOrFail($subscription->id)->ends_at->toDateString());
    }

    // ── التجديد ─────────────────────────────────────────────────────

    public function test_a_lapsed_auto_renewing_subscription_rolls_to_its_next_term(): void
    {
        $subscription = $this->subscription();

        $this->renew('2026-02-16');

        $this->assertSame('2026-03-15', $this->endsAt($subscription));
    }

    /** ثلاثة أشهر فاتته تتقدّم ثلاث دورات لا واحدة. */
    public function test_several_missed_terms_are_caught_up_in_one_run(): void
    {
        $subscription = $this->subscription();

        $this->renew('2026-05-20');

        $this->assertSame('2026-06-15', $this->endsAt($subscription));

        $log = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'subscription_renewed')->firstOrFail());
        $this->assertSame(4, $log->new_values['cycles']);
    }

    /** ٣١ كانون الثاني: آخر شباط، ثم يعود آذار إلى ٣١ — لا ٣ آذار ولا ٢٨ إلى الأبد. */
    public function test_a_month_end_anchor_neither_overflows_nor_drifts(): void
    {
        $subscription = $this->subscription(['starts_at' => '2026-01-31', 'ends_at' => '2026-02-28']);

        $this->renew('2026-03-01');
        $this->assertSame('2026-03-31', $this->endsAt($subscription));

        $this->renew('2026-04-01');
        $this->assertSame('2026-04-30', $this->endsAt($subscription));
    }

    public function test_a_subscription_still_in_term_is_left_alone(): void
    {
        $subscription = $this->subscription();

        $this->renew('2026-02-10');

        $this->assertSame('2026-02-15', $this->endsAt($subscription));
    }

    /** تحويل التجربة إلى مدفوع قرارٌ لا ساعة، والملغى ملغى، وغير التلقائيّ ينتظر. */
    public function test_trials_cancelled_and_manual_subscriptions_do_not_renew(): void
    {
        $trial = $this->subscription(['status' => 'trialing']);
        $manual = $this->subscription(['auto_renew' => false]);
        $cancelled = $this->subscription(['cancelled_at' => '2026-02-01 10:00:00']);

        $this->renew('2026-03-01');

        foreach ([$trial, $manual, $cancelled] as $subscription) {
            $this->assertSame('2026-02-15', $this->endsAt($subscription));
        }
    }

    public function test_a_new_monthly_subscription_on_the_31st_ends_on_the_last_day_of_next_month(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31 10:00'));

        $subscription = app(RegisterCompany::class)->subscribe($this->company, $this->plan);

        $this->assertSame('2026-02-28', $subscription->ends_at->toDateString());
    }

    // ── الخطّ الزمنيّ ───────────────────────────────────────────────

    public function test_the_platform_timeline_separates_lapsed_today_and_soon(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 09:00'));

        $admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000', 'password' => 'password',
            'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        // لا يتجدّد: انتهى قبل ثلاثة أيام — يحتاج قراراً
        $this->subscription(['auto_renew' => false, 'starts_at' => '2026-08-17', 'ends_at' => '2026-09-17']);
        $this->subscription(['starts_at' => '2026-08-20', 'ends_at' => '2026-09-20']);
        $this->subscription(['status' => 'trialing', 'starts_at' => '2026-09-06', 'ends_at' => '2026-09-25']);

        $response = $this->actingAs($admin)->get('/admin')->assertOk();
        $timeline = $response->viewData('expiring');

        $this->assertEqualsCanonicalizing(['lapsed', 'today', 'soon'], $timeline->keys()->all());
        $response->assertSee('انتهى منذ 3 أيام')   // لا «ينتهي اليوم» ولا «3 يوم»
            ->assertSee('باقٍ 5 أيام')
            ->assertDontSee('يوم"', false);
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\EnforceDues;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentNotice;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\BillingPolicy;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * دورة المال بين المنصّة والشركات (docs/plan/36): الشركة ترى ما عليها وكيف تدفع، وتُبلغ عن
 * دفعتها فتؤكّدها المنصّة؛ والتأخّر يُنبَّه إليه بتاريخه، ثم يوقف النظام بعد المهلة إلّا صفحة
 * الفواتير، ويعود وحده حين يُسدَّد.
 */
class MoneyLoopTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Company $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        Storage::fake('local');

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        $this->other = $this->makeCompany('barq', 'البرق');
        $this->makeMerchant($this->other);
    }

    private function host(?Company $company = null): string
    {
        return 'http://'.($company ?? $this->company)->slug.'.'.config('zajel.tenant_domain');
    }

    /** فاتورةٌ صادرة بموعدها بعد اليوم بـ $dueIn يوماً (سالبٌ: فات) */
    private function invoice(int $total = 275_000, int $dueIn = 5, ?Company $company = null, string $status = 'issued', string $month = '2026-09'): Invoice
    {
        $company ??= $this->company;

        return Tenancy::runFor($company, function () use ($company, $total, $dueIn, $status, $month) {
            $start = CarbonImmutable::parse($month.'-01');
            $invoice = Invoice::create([
                'number' => 'INV-'.$start->format('Ym').'-'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT),
                'period_start' => $start->toDateString(), 'period_end' => $start->endOfMonth()->toDateString(),
                'subscription_amount' => $total, 'total' => $total, 'status' => $status,
                'issued_at' => now(), 'due_at' => CarbonImmutable::today()->addDays($dueIn)->toDateString(),
            ]);
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'type' => 'subscription', 'description' => 'اشتراك شهري — '.$month,
                'quantity' => 1, 'unit_price' => $total, 'amount' => $total,
            ]);

            return $invoice;
        });
    }

    private function fresh(Company $company): Company
    {
        return Tenancy::runAsPlatform(fn () => $company->fresh());
    }

    private function merchantUser(): User
    {
        $merchant = Tenancy::runFor($this->company, fn () => \App\Models\Merchant::first());

        return Tenancy::runFor($this->company, fn () => User::firstOrCreate(['phone' => '07790000001'], [
            'name' => 'تاجر', 'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
    }

    // ------------------------------------------------------------ ما ترى الشركة

    public function test_the_owner_sees_what_the_company_owes_and_how_to_pay(): void
    {
        PlatformSetting::put(BillingPolicy::PAYMENT_METHODS, "زين كاش: 07800000000 — باسم وهج\nحوالة آسيا");
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->get($this->host().'/billing')->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('275,000')
            ->assertSee('زين كاش: 07800000000 — باسم وهج')
            ->assertSee('ميزات نظامك')
            ->assertSee('أبلغ عن الدفعة');

        $this->actingAs($this->owner)->get($this->host().'/billing/invoices/'.$invoice->id)->assertOk()
            ->assertSee('اشتراك شهري — 2026-09')
            ->assertSee('الباقي');

        // وأعلى كل صفحة: صدرت ومتى تُستحقّ
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('صدرت فاتورة المنصّة')
            ->assertSee($invoice->due_at->format('Y-m-d'));
    }

    public function test_only_who_runs_the_whole_company_reaches_its_bills(): void
    {
        $invoice = $this->invoice();
        $theirs = $this->invoice(company: $this->other);
        $draft = $this->invoice(status: 'draft', month: '2026-08');

        $operations = $this->makeUser($this->company, UserRole::Operations);
        $this->actingAs($operations)->get($this->host().'/billing')->assertForbidden();
        $this->actingAs($operations)->get($this->host().'/')->assertOk()->assertDontSee('فاتورة المنصّة');

        // صاحب فرعٍ غير الرئيسي: كل شيءٍ في فرعه، لا مال الشركة مع المنصّة
        $branch = Tenancy::runFor($this->company, fn () => Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_main' => false]));
        $branchOwner = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'صاحب فرع', 'phone' => '07700000077', 'password' => 'password',
            'role' => UserRole::BranchOwner, 'branch_id' => $branch->id, 'is_active' => true,
        ]));
        $this->actingAs($branchOwner)->get($this->host().'/billing')->assertForbidden();

        $this->actingAs($this->owner)->get($this->host().'/billing/invoices/'.$theirs->id)->assertNotFound();
        $this->actingAs($this->owner)->get($this->host().'/billing/invoices/'.$draft->id)->assertNotFound();
        $this->actingAs($this->owner)->get($this->host().'/billing')->assertOk()->assertDontSee($draft->number);
    }

    // ------------------------------------------------------------ التأخّر

    public function test_a_late_invoice_warns_with_the_day_the_system_stops(): void
    {
        PlatformSetting::put(BillingPolicy::GRACE_DAYS, 15);
        $invoice = $this->invoice(dueIn: -3);

        app(EnforceDues::class)->handle();

        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame('active', $this->fresh($this->company)->status);

        $stops = CarbonImmutable::parse($invoice->due_at)->addDays(16)->format('Y-m-d');
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('متأخّرة منذ 3 أيام')
            ->assertSee('يتوقّف النظام في')
            ->assertSee($stops);
    }

    public function test_past_the_grace_the_system_stops_but_its_bills_and_payment_bring_it_back(): void
    {
        PlatformSetting::put(BillingPolicy::GRACE_DAYS, 15);
        $invoice = $this->invoice(dueIn: -16);
        $merchant = $this->merchantUser();

        $this->artisan('zajel:dues')->assertSuccessful();

        $company = $this->fresh($this->company);
        $this->assertSame('suspended', $company->status);
        $this->assertSame('billing', $company->suspension_source);
        $audit = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'company_suspended')->sole());
        $this->assertTrue($audit->new_values['automatic']);
        $this->assertSame($invoice->number, $audit->new_values['invoice']);

        // الدخول يعمل، وصاحبها يُوجَّه إلى فواتيره، وغيره يُقال له لماذا
        $this->get($this->host().'/login')->assertOk();
        $this->post($this->host().'/login', ['username' => $this->owner->username, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->owner);
        $this->post($this->host().'/logout');
        $this->actingAs($this->owner)->get($this->host().'/')->assertRedirect($this->host().'/billing');
        $this->actingAs($this->owner)->get($this->host().'/shipments')->assertRedirect($this->host().'/billing');
        $this->actingAs($this->owner)->get($this->host().'/billing')->assertOk()->assertSee('متوقّف منذ');
        $this->actingAs($merchant)->get($this->host().'/portal')->assertForbidden()
            ->assertSee('نظام هذه الشركة متوقّفٌ مؤقتاً لتأخّر سداد اشتراكه');

        // المنصّة تسجّل الدفعة: يعود الآن لا الليلة
        $this->actingAs($this->admin)->post('/admin/invoices/'.$invoice->id.'/pay', ['amount' => 275_000, 'method' => 'zaincash'])
            ->assertSessionHasNoErrors();

        $company = $this->fresh($this->company);
        $this->assertSame('active', $company->status);
        $this->assertNull($company->suspension_source);
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk();
        $this->actingAs($merchant)->get($this->host().'/portal')->assertOk();
    }

    public function test_a_partial_payment_keeps_it_stopped_until_nothing_is_past_the_grace(): void
    {
        PlatformSetting::put(BillingPolicy::GRACE_DAYS, 15);
        $invoice = $this->invoice(dueIn: -20);
        app(EnforceDues::class)->handle();

        $this->actingAs($this->admin)->post('/admin/invoices/'.$invoice->id.'/pay', ['amount' => 100_000, 'method' => 'cash']);
        $this->assertSame('suspended', $this->fresh($this->company)->status);

        $this->actingAs($this->admin)->post('/admin/invoices/'.$invoice->id.'/pay', ['amount' => 175_000, 'method' => 'cash']);
        $this->assertSame('active', $this->fresh($this->company)->status);
    }

    public function test_without_a_grace_nothing_stops_and_an_exempt_company_never_stops(): void
    {
        $this->invoice(dueIn: -40);
        app(EnforceDues::class)->handle();
        $this->assertSame('active', $this->fresh($this->company)->status);

        PlatformSetting::put(BillingPolicy::GRACE_DAYS, 15);
        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/billing-exempt', ['exempt' => '1'])
            ->assertSessionHas('success', 'أُعفيت الزاجل من الإيقاف التلقائي.');
        app(EnforceDues::class)->handle();
        $this->assertSame('active', $this->fresh($this->company)->status);

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertSee('معفاة من الإيقاف');

        // رفع الإعفاء: تتوقّف في الليلة التالية، وإعادته تعيدها فوراً
        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/billing-exempt', ['exempt' => '0']);
        app(EnforceDues::class)->handle();
        $this->assertSame('suspended', $this->fresh($this->company)->status);

        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/billing-exempt', ['exempt' => '1'])
            ->assertSessionHas('success', 'أُعفيت الزاجل من الإيقاف التلقائي، وعاد نظامها.');
        $this->assertSame('active', $this->fresh($this->company)->status);
    }

    public function test_a_suspension_by_the_platform_is_not_lifted_by_payment(): void
    {
        $invoice = $this->invoice(dueIn: -30);
        $this->actingAs($this->admin)->post('/admin/companies/'.$this->company->id.'/suspend', ['reason' => 'مخالفة'])
            ->assertSessionHasNoErrors();
        $this->assertSame('platform', $this->fresh($this->company)->suspension_source);

        $this->actingAs($this->admin)->post('/admin/invoices/'.$invoice->id.'/pay', ['amount' => 275_000, 'method' => 'cash']);
        app(EnforceDues::class)->handle();

        $this->assertSame('suspended', $this->fresh($this->company)->status);
        $this->actingAs($this->owner)->get($this->host().'/billing')->assertForbidden();
    }

    public function test_a_company_stopped_for_late_payment_is_still_billed_but_one_stopped_by_hand_is_not(): void
    {
        $month = CarbonImmutable::now()->subMonth()->startOfMonth();
        $this->travelTo($month->subDays(2));
        $this->setFeature($this->company, \App\Enums\Feature::OrderReading, true, 30_000);
        $this->setFeature($this->other, \App\Enums\Feature::OrderReading, true, 30_000);
        $this->travelBack();

        Tenancy::runAsPlatform(function () {
            $this->company->update(['status' => 'suspended', 'suspension_source' => 'billing', 'suspended_at' => now()]);
            $this->other->update(['status' => 'suspended', 'suspension_source' => 'platform', 'suspended_at' => now()]);
        });

        $this->artisan('zajel:bill', ['--month' => $month->format('Y-m')])->assertSuccessful();

        $billed = Tenancy::runAsPlatform(fn () => Invoice::pluck('company_id')->all());
        $this->assertSame([$this->company->id], $billed);
    }

    // ------------------------------------------------------------ «دفعتُ»

    public function test_a_company_reports_a_payment_and_the_platform_confirms_it(): void
    {
        PlatformSetting::put(BillingPolicy::GRACE_DAYS, 15);
        $invoice = $this->invoice(dueIn: -20);
        app(EnforceDues::class)->handle();

        $this->actingAs($this->owner)->post($this->host().'/billing/notices', [
            'invoice_id' => $invoice->id, 'amount' => '275 000', 'method' => 'zaincash',
            'reference' => 'ZC-778899', 'paid_on' => now()->toDateString(),
            'proof' => UploadedFile::fake()->image('receipt.jpg', 300, 600),
        ])->assertRedirect($this->host().'/billing')
            ->assertSessionHas('success', 'وصل إبلاغك بدفع 275,000 د.ع — يُسجَّل على الفاتورة حين تؤكّده إدارة المنصّة.');

        $notice = Tenancy::runAsPlatform(fn () => PaymentNotice::sole());
        $this->assertSame('pending', $notice->status);
        Storage::disk('local')->assertExists($notice->proof_path);
        $this->assertStringStartsWith('attachments/'.$this->company->id.'/payments/', $notice->proof_path);

        // المنصّة تراه بإيصاله، وعدّاده على «الفواتير»
        $this->actingAs($this->admin)->get('/admin/invoices')->assertOk()
            ->assertSee('دفعاتٌ أبلغت عنها الشركات')
            ->assertSee('ZC-778899')
            ->assertSee(route('admin.notices.proof', $notice), false);
        $this->actingAs($this->admin)->get('/admin/payment-notices/'.$notice->id.'/proof')->assertOk();

        $this->actingAs($this->admin)->post('/admin/payment-notices/'.$notice->id.'/confirm')->assertSessionHasNoErrors();

        $payment = Tenancy::runAsPlatform(fn () => Payment::sole());
        $this->assertSame(['zaincash', 'ZC-778899', 275_000], [$payment->method, $payment->reference, (int) $payment->amount]);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(['confirmed', $payment->id], [$notice->fresh()->status, $notice->fresh()->payment_id]);
        $this->assertSame('active', $this->fresh($this->company)->status);

        // لا تُسجَّل مرّتين
        $this->actingAs($this->admin)->post('/admin/payment-notices/'.$notice->id.'/confirm')->assertSessionHasErrors('notice');
        $this->assertSame(1, Tenancy::runAsPlatform(fn () => Payment::count()));

        $this->actingAs($this->owner)->get($this->host().'/billing')->assertOk()->assertSee('أُكّدت');
    }

    public function test_a_rejected_report_tells_the_company_why(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->owner)->post($this->host().'/billing/notices', [
            'invoice_id' => $invoice->id, 'amount' => 100_000, 'method' => 'asiahawala', 'paid_on' => now()->toDateString(),
        ]);
        $notice = Tenancy::runAsPlatform(fn () => PaymentNotice::sole());

        $this->actingAs($this->admin)->post('/admin/payment-notices/'.$notice->id.'/reject', ['reason' => 'لم تصل الحوالة'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $notice->fresh()->status);
        $this->assertSame(0, Tenancy::runAsPlatform(fn () => Payment::count()));
        $this->actingAs($this->owner)->get($this->host().'/billing')->assertOk()
            ->assertSee('رُفضت')
            ->assertSee('السبب: لم تصل الحوالة');
    }

    public function test_a_report_stays_within_what_is_left_on_the_company_own_invoice(): void
    {
        $invoice = $this->invoice(100_000);
        $theirs = $this->invoice(company: $this->other);
        $paid = $this->invoice(status: 'paid', month: '2026-08');
        $report = fn (array $data) => $this->actingAs($this->owner)->post($this->host().'/billing/notices', $data + [
            'invoice_id' => $invoice->id, 'amount' => 50_000, 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);

        $report(['amount' => 150_000])->assertSessionHasErrors(['amount' => 'المبلغ أكبر من الباقي على الفاتورة (100,000 د.ع).']);
        $report(['amount' => 80_000])->assertSessionHasNoErrors();
        $report(['amount' => 30_000])->assertSessionHasErrors('amount');
        $report(['invoice_id' => $theirs->id])->assertSessionHasErrors(['invoice_id' => 'اختر فاتورةً عليها باقٍ.']);
        $report(['invoice_id' => $paid->id])->assertSessionHasErrors('invoice_id');
        $report(['paid_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('paid_on');
        $report(['proof' => UploadedFile::fake()->create('receipt.txt', 10, 'text/plain')])->assertSessionHasErrors('proof');

        $this->assertSame(1, Tenancy::runAsPlatform(fn () => PaymentNotice::count()));
    }

    public function test_a_receipt_stays_inside_its_company(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->owner)->post($this->host().'/billing/notices', [
            'invoice_id' => $invoice->id, 'amount' => 50_000, 'method' => 'cash', 'paid_on' => now()->toDateString(),
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ]);
        $notice = Tenancy::runAsPlatform(fn () => PaymentNotice::sole());

        $this->actingAs($this->owner)->get($this->host().'/billing/notices/'.$notice->id.'/proof')->assertOk();
        $this->actingAs($this->makeUser($this->other))->get($this->host($this->other).'/billing/notices/'.$notice->id.'/proof')->assertNotFound();
    }

    // ------------------------------------------------------------ قبل الانتهاء

    public function test_a_trial_or_a_subscription_that_will_not_renew_warns_before_it_ends(): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->update(['status' => 'trial', 'trial_ends_at' => now()->addDays(3)]));
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()->assertSee('الفترة التجريبية تنتهي بعد 3 أيام');

        $this->seed(\Database\Seeders\PlanSeeder::class);
        Tenancy::runAsPlatform(fn () => $this->company->update(['status' => 'active', 'trial_ends_at' => null]));
        $subscription = Tenancy::runFor($this->company, fn () => Subscription::create([
            'plan_id' => Plan::where('code', 'basic')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly',
            'price' => 250_000, 'starts_at' => now()->subDays(25)->toDateString(), 'ends_at' => now()->addDays(5)->toDateString(),
            'auto_renew' => true,
        ]));

        // يتجدّد وحده: لا تنبيه
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()->assertDontSee('ينتهي');

        Tenancy::runFor($this->company, fn () => $subscription->update(['auto_renew' => false]));
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()->assertSee('الاشتراك ينتهي ولا يتجدّد بعد 5 أيام');
    }

    // ------------------------------------------------------------ إعدادات المنصّة

    public function test_the_platform_sets_how_companies_pay_and_sees_who_would_stop(): void
    {
        $this->invoice(dueIn: -12);

        $this->actingAs($this->admin)->put('/admin/settings', [
            'payment_methods' => "  زين كاش: 07800000000  \n\n حوالة آسيا ",
            'grace_days' => '10', 'reminder_days' => '5',
        ])->assertRedirect(route('admin.settings'));

        $this->assertSame("زين كاش: 07800000000\nحوالة آسيا", BillingPolicy::paymentMethods());
        $this->assertSame([10, 5], [BillingPolicy::graceDays(), BillingPolicy::reminderDays()]);

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()
            ->assertSee('الزاجل')
            ->assertSee('تتوقّف الليلة');

        // فارغةً: لا إيقاف
        $this->actingAs($this->admin)->put('/admin/settings', ['payment_methods' => '', 'grace_days' => '', 'reminder_days' => '7']);
        $this->assertNull(BillingPolicy::graceDays());

        $this->actingAs($this->owner)->get($this->host().'/admin/settings')->assertForbidden();
    }
}

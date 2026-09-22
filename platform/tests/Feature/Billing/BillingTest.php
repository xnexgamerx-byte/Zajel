<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\GenerateInvoice;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الفوترة. الخطأ هنا لا يظهر كعطل بل كرقم خاطئ في فاتورة عميل —
 * وهذا أسوأ، لأنه يُكتشف بعد إرسالها.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $staff;

    private CarbonImmutable $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->period = CarbonImmutable::now()->subMonth()->startOfMonth();

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
        ]));
    }

    private function subscribe(string $planCode = 'growth', array $overrides = []): Subscription
    {
        $plan = Plan::where('code', $planCode)->firstOrFail();

        return Tenancy::runFor($this->company, fn () => Subscription::create(array_merge([
            'plan_id'                 => $plan->id,
            'status'                  => 'active',
            'billing_cycle'           => 'monthly',
            'price'                   => $plan->price_monthly,
            'commission_per_shipment' => $plan->commission_per_shipment,
            'commission_percent'      => $plan->commission_percent,
            'starts_at'               => $this->period->subMonth()->toDateString(),
            'ends_at'                 => $this->period->addYear()->toDateString(),
        ], $overrides)));
    }

    /** يُسلّم شحنة داخل فترة الفوترة. */
    private function deliverInPeriod(int $cod = 50_000, ?CarbonImmutable $on = null): Shipment
    {
        $on ??= $this->period->addDays(5)->setTime(12, 0);

        return Tenancy::runFor($this->company, function () use ($cod, $on) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff, [
                'courier_id' => $this->courier->id,
            ]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff);

            // ضبط تاريخ التسليم داخل الفترة المفوترة
            $shipment->refresh()->forceFill(['delivered_at' => $on])->save();

            return $shipment->refresh();
        });
    }

    // ------------------------------------------------------ التوليد

    public function test_an_invoice_charges_the_subscription_and_the_per_shipment_commission(): void
    {
        $this->subscribe('commission', ['price' => 250_000, 'commission_per_shipment' => 250]);

        $this->deliverInPeriod();
        $this->deliverInPeriod(80_000);
        $this->deliverInPeriod(30_000);

        $invoice = app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->assertSame(250_000, $invoice->subscription_amount);
        $this->assertSame(3, $invoice->billable_shipments);
        $this->assertSame(750, $invoice->commission_amount);     // 3 × 250
        $this->assertSame(250_750, $invoice->total);
        $this->assertSame('issued', $invoice->status);
    }

    public function test_a_percentage_commission_is_taken_from_what_was_collected(): void
    {
        $this->subscribe('growth', [
            'price' => 0, 'commission_per_shipment' => 0, 'commission_percent' => 2.5,
        ]);

        $this->deliverInPeriod(100_000);
        $this->deliverInPeriod(40_000);

        $invoice = app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->assertSame(3500, $invoice->commission_amount);   // 2,500 + 1,000
        $this->assertSame(2, $invoice->billable_shipments);
    }

    public function test_a_shipment_is_never_billed_twice(): void
    {
        $this->subscribe('commission', ['price' => 250_000, 'commission_per_shipment' => 250]);

        $this->deliverInPeriod();
        $first = app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->assertSame(1, $first->billable_shipments);
        $this->assertSame(250, $first->commission_amount);
        Tenancy::runFor($this->company, fn () => $this->assertTrue(Shipment::firstOrFail()->is_invoiced));

        // شهر تالٍ: الاشتراك يُفوتَر، والشحنة الموسومة لا تُحتسب مجدداً
        $next = app(GenerateInvoice::class)->handle($this->company, $this->period->addMonth());

        $this->assertSame(250_000, $next->total);
        $this->assertSame(0, $next->billable_shipments);
        $this->assertSame(0, $next->commission_amount);
    }

    public function test_billing_the_same_period_twice_is_refused(): void
    {
        $this->subscribe();
        app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->expectException(ValidationException::class);
        app(GenerateInvoice::class)->handle($this->company, $this->period);
    }

    public function test_shipments_outside_the_period_are_not_billed(): void
    {
        $this->subscribe('commission', ['price' => 0, 'commission_per_shipment' => 250]);

        $this->deliverInPeriod(50_000, $this->period->addDays(5));
        $this->deliverInPeriod(50_000, $this->period->addMonth()->addDays(5));   // الشهر التالي

        $invoice = app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->assertSame(1, $invoice->billable_shipments);
    }

    public function test_a_yearly_subscription_is_only_charged_in_its_anniversary_month(): void
    {
        $this->subscribe('enterprise', [
            'billing_cycle' => 'yearly',
            'price'         => 10_000_000,
            'starts_at'     => $this->period->toDateString(),
        ]);

        $onMonth = app(GenerateInvoice::class)->handle($this->company, $this->period);
        $this->assertSame(10_000_000, $onMonth->subscription_amount);

        // الشهر التالي لا يحمل اشتراكاً ولا شحنات، فلا فاتورة له أصلاً
        $this->expectException(ValidationException::class);
        app(GenerateInvoice::class)->handle($this->company, $this->period->addMonth());
    }

    public function test_a_yearly_subscriber_is_still_billed_for_commission_in_other_months(): void
    {
        $this->subscribe('enterprise', [
            'billing_cycle'           => 'yearly',
            'price'                   => 10_000_000,
            'commission_per_shipment' => 250,
            'starts_at'               => $this->period->toDateString(),
        ]);

        $offMonth = $this->period->addMonth();
        $this->deliverInPeriod(50_000, $offMonth->addDays(4));

        $invoice = app(GenerateInvoice::class)->handle($this->company, $offMonth);

        $this->assertSame(0, $invoice->subscription_amount);
        $this->assertSame(250, $invoice->commission_amount);
        $this->assertSame(250, $invoice->total);
    }

    public function test_the_commission_is_frozen_on_each_shipment_for_auditing(): void
    {
        $this->subscribe('commission', ['price' => 0, 'commission_per_shipment' => 250]);
        $this->deliverInPeriod();

        app(GenerateInvoice::class)->handle($this->company, $this->period);

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            250, (int) Shipment::firstOrFail()->platform_commission
        ));
    }

    // -------------------------------------------------------- الدفع

    public function test_a_partial_payment_leaves_the_invoice_open(): void
    {
        $this->subscribe('commission', ['price' => 250_000, 'commission_per_shipment' => 0]);
        $invoice = app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->actingAs($this->admin)
            ->post("/admin/invoices/{$invoice->id}/pay", ['amount' => 100_000, 'method' => 'zaincash'])
            ->assertSessionHas('success');

        $invoice->refresh();

        $this->assertSame(100_000, $invoice->amount_paid);
        $this->assertSame(150_000, $invoice->balanceDue());
        $this->assertSame('issued', $invoice->status);
    }

    public function test_paying_the_rest_marks_the_invoice_paid(): void
    {
        $this->subscribe('commission', ['price' => 250_000, 'commission_per_shipment' => 0]);
        $invoice = app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->actingAs($this->admin)->post("/admin/invoices/{$invoice->id}/pay",
            ['amount' => 100_000, 'method' => 'cash']);
        $this->actingAs($this->admin)->post("/admin/invoices/{$invoice->id}/pay",
            ['amount' => 150_000, 'method' => 'fib', 'reference' => 'FIB-8812']);

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0, $invoice->balanceDue());
        $this->assertNotNull($invoice->paid_at);

        Tenancy::runFor($this->company, fn () => $this->assertSame(2, Payment::count()));
    }

    public function test_overpaying_is_refused(): void
    {
        $this->subscribe('commission', ['price' => 250_000, 'commission_per_shipment' => 0]);
        $invoice = app(GenerateInvoice::class)->handle($this->company, $this->period);

        $this->actingAs($this->admin)
            ->post("/admin/invoices/{$invoice->id}/pay", ['amount' => 300_000, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, (int) $invoice->fresh()->amount_paid);
    }

    // ------------------------------------------------------- اللوحة

    public function test_a_company_with_nothing_to_bill_gets_no_invoice(): void
    {
        // اشتراك بدأ بعد الفترة، ولا شحنات: لا تُصدَر ورقة فارغة
        $this->subscribe('growth', ['starts_at' => $this->period->addMonths(2)->toDateString()]);

        $this->actingAs($this->admin)->post('/admin/invoices/generate', [
            'month' => $this->period->format('Y-m'),
        ])->assertSessionHas('success');

        Tenancy::runAsPlatform(fn () => $this->assertSame(0, Invoice::count()));
    }

    public function test_generating_a_month_from_the_panel_skips_companies_already_billed(): void
    {
        $this->subscribe();
        $other = $this->makeCompany('barq', 'البرق');
        Tenancy::runFor($other, fn () => Subscription::create([
            'plan_id' => Plan::where('code', 'basic')->value('id'),
            'status' => 'active', 'billing_cycle' => 'monthly', 'price' => 250_000,
            'starts_at' => $this->period->toDateString(), 'ends_at' => $this->period->addYear()->toDateString(),
        ]));

        $month = $this->period->format('Y-m');

        $this->actingAs($this->admin)->post('/admin/invoices/generate', ['month' => $month]);
        Tenancy::runAsPlatform(fn () => $this->assertSame(2, Invoice::count()));

        // إعادة التوليد لا تُصدر شيئاً
        $this->actingAs($this->admin)->post('/admin/invoices/generate', ['month' => $month]);
        Tenancy::runAsPlatform(fn () => $this->assertSame(2, Invoice::count()));
    }

    public function test_a_company_only_sees_its_own_invoices(): void
    {
        $this->subscribe();
        app(GenerateInvoice::class)->handle($this->company, $this->period);

        $other = $this->makeCompany('barq', 'البرق');

        Tenancy::runFor($other, fn () => $this->assertSame(0, Invoice::count()));
        Tenancy::runFor($this->company, fn () => $this->assertSame(1, Invoice::count()));
        Tenancy::runAsPlatform(fn () => $this->assertSame(1, Invoice::count()));
    }

    public function test_a_company_user_cannot_reach_the_invoices_panel(): void
    {
        $this->actingAs($this->staff)->get('/admin/invoices')->assertForbidden();
    }
}

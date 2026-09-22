<?php

namespace Tests\Feature\Reports;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SqlDate;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * التقارير الستّة.
 *
 * التقرير الذي يكذب أسوأ من غياب التقرير: الأول يُتّخذ عليه قرار.
 * فالاختبار هنا على الأرقام نفسها لا على أن الصفحة تفتح.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function make(?Merchant $merchant = null, int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => ($merchant ?? $this->alpha)->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => $cod,
        ], $this->staff));
    }

    private function walk(Shipment $shipment, array $path, array $options = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $path, $options) {
            $change = app(ChangeShipmentStatus::class);

            foreach ($path as $status) {
                $change->handle($shipment->refresh(), $status, $this->staff, array_merge([
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ], $options[$status->value] ?? []));
            }

            return $shipment->refresh();
        });
    }

    private function delivered(?Merchant $merchant = null, int $cod = 50_000): Shipment
    {
        return $this->walk($this->make($merchant, $cod), [
            ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered,
        ]);
    }

    private function failed(string $reasonCode = 'no_answer', ?Merchant $merchant = null): Shipment
    {
        $reason = FailureReason::where('code', $reasonCode)->firstOrFail();

        return $this->walk($this->make($merchant), [
            ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt,
        ], [ShipmentStatus::FailedAttempt->value => ['failure_reason_id' => $reason->id]]);
    }

    // ── المدّة ──────────────────────────────────────────────────────

    public function test_a_reversed_period_is_read_the_right_way_round(): void
    {
        $period = ReportPeriod::fromRequest(Request::create('/', 'GET', [
            'from' => '2026-09-30', 'to' => '2026-09-01',
        ]));

        $this->assertSame('2026-09-01', $period->from->toDateString());
        $this->assertSame('2026-09-30', $period->to->toDateString());
        $this->assertSame(30, $period->days());
    }

    public function test_the_period_covers_the_whole_last_day(): void
    {
        $period = ReportPeriod::fromRequest(Request::create('/', 'GET', [
            'from' => '2026-09-01', 'to' => '2026-09-01',
        ]));

        // شحنة أُنشئت الساعة الحادية عشرة ليلاً تدخل يومها
        $this->assertSame('2026-09-01 23:59:59', $period->to->toDateTimeString());
        $this->assertSame(1, $period->days());
    }

    public function test_the_month_expression_matches_the_database_driver(): void
    {
        // التعبير يختلف بين المحرّكين، والإنتاج MySQL والاختبارات SQLite
        $this->assertSame("strftime('%Y-%m', created_at)", SqlDate::month('created_at'));
        $this->assertSame('date(created_at)', SqlDate::day('created_at'));
    }

    // ── ١ لماذا ترجع شحناتي ─────────────────────────────────────────

    public function test_the_returns_report_ranks_reasons_and_names_the_category(): void
    {
        $this->failed('no_answer');
        $this->failed('no_answer');
        $this->failed('wrong_number');

        $this->actingAs($this->staff)
            ->get($this->host().'/reports/returns')
            ->assertOk()
            ->assertSeeInOrder(['لا يرد على الهاتف', 'رقم الهاتف خطأ'])
            ->assertSee('الزبون')
            ->assertSee('العنوان');
    }

    public function test_the_returns_report_counts_which_failures_actually_came_back(): void
    {
        $failed = $this->failed('no_answer');
        $this->failed('no_answer');

        // واحدة منها فقط رجعت فعلاً
        Tenancy::runFor($this->company, function () use ($failed) {
            app(ChangeShipmentStatus::class)->handle($failed, ShipmentStatus::Returning, $this->staff);
            app(\App\Actions\Returns\ReceiveReturns::class)->handle([$failed->id], $this->staff);
            app(ChangeShipmentStatus::class)->handle($failed->refresh(), ShipmentStatus::Returned, $this->staff);
        });

        $response = $this->actingAs($this->staff)->get($this->host().'/reports/returns')->assertOk();

        $reasons = $response->viewData('reasons');
        $row = $reasons->firstWhere('name', 'لا يرد على الهاتف');

        $this->assertSame(2, (int) $row->total);
        $this->assertSame(1, (int) $row->returned, 'محاولة فاشلة ليست راجعاً');
    }

    public function test_a_shipment_outside_the_period_is_not_counted(): void
    {
        $old = $this->failed('no_answer');

        Tenancy::runFor($this->company, fn () => $old
            ->forceFill(['status_changed_at' => now()->subMonths(3)])->save());

        $response = $this->actingAs($this->staff)
            ->get($this->host().'/reports/returns?from='.now()->startOfMonth()->toDateString()
                  .'&to='.now()->toDateString())
            ->assertOk();

        $this->assertSame(0, $response->viewData('reasons')->sum('total'));
    }

    // ── ٢ أداء المندوبين ────────────────────────────────────────────

    public function test_the_courier_report_separates_delivered_from_returned(): void
    {
        $this->delivered(cod: 60_000);
        $this->delivered(cod: 40_000);
        $this->failed();

        $response = $this->actingAs($this->staff)->get($this->host().'/reports/couriers')->assertOk();
        $row = $response->viewData('rows')->firstWhere('name', 'أحمد الساعدي');

        $this->assertSame(2, (int) $row->delivered);
        $this->assertSame(1, (int) $row->failed);
        $this->assertSame(0, (int) $row->returned);
        $this->assertSame(100_000, (int) $row->collected);
        $this->assertSame(3_000, (int) $row->commission, 'عمولة تسليمين');
    }

    // ── ٣ أداء التجّار ──────────────────────────────────────────────

    public function test_the_merchant_report_counts_each_merchant_apart(): void
    {
        $this->delivered($this->alpha);
        $this->delivered($this->alpha);
        $this->delivered($this->beta);
        $this->make($this->alpha);   // ما تزال مفتوحة

        $response = $this->actingAs($this->staff)->get($this->host().'/reports/merchants')->assertOk();
        $rows = $response->viewData('rows');

        $alpha = $rows->firstWhere('merchant_id', $this->alpha->id);
        $beta = $rows->firstWhere('merchant_id', $this->beta->id);

        $this->assertSame(3, (int) $alpha->total);
        $this->assertSame(2, (int) $alpha->delivered);
        $this->assertSame(1, (int) $alpha->still_open);
        $this->assertSame(1, (int) $beta->total);
    }

    // ── ٤ المحافظات ─────────────────────────────────────────────────

    public function test_the_governorate_report_groups_by_destination(): void
    {
        $this->delivered();
        $this->delivered();

        $response = $this->actingAs($this->staff)->get($this->host().'/reports/governorates')->assertOk();
        $row = $response->viewData('rows')->first();

        $this->assertSame('بغداد', $row->name);
        $this->assertSame(2, (int) $row->total);
        $this->assertSame(2, (int) $row->delivered);
    }

    // ── ٥ الحركة اليومية ────────────────────────────────────────────

    public function test_the_daily_report_has_a_row_for_every_day_including_empty_ones(): void
    {
        $this->delivered();

        $response = $this->actingAs($this->staff)
            ->get($this->host().'/reports/daily?from='.now()->subDays(6)->toDateString()
                  .'&to='.now()->toDateString())
            ->assertOk();

        $days = $response->viewData('days');

        $this->assertCount(7, $days, 'يوم بلا حركة يبقى صفّاً بصفر لا فجوة في الرسم');
        $this->assertSame(1, $days->sum('created'));
        $this->assertSame(1, $days->sum('delivered'));
        $this->assertSame(0, $days->sum('returned'));
        $this->assertSame(0, $days->first()['created']);
    }

    public function test_the_daily_chart_ships_its_values_as_a_table_too(): void
    {
        $this->delivered();

        $this->actingAs($this->staff)
            ->get($this->host().'/reports/daily')
            ->assertOk()
            ->assertSee('القيم يوماً بيوم')
            ->assertSee(now()->toDateString());
    }

    // ── ٦ الأرباح ───────────────────────────────────────────────────

    public function test_the_profit_report_nets_fees_against_commissions(): void
    {
        $one = $this->delivered();
        $this->delivered();

        $response = $this->actingAs($this->staff)->get($this->host().'/reports/profit')->assertOk();
        $totals = $response->viewData('totals');

        $fees = Tenancy::runFor($this->company, fn () => (int) $one->refresh()->total_fees);

        $this->assertSame(2, $totals->total);
        $this->assertSame($fees * 2, $totals->revenue);
        $this->assertSame(3_000, $totals->commission);
        $this->assertSame($fees * 2 - 3_000, $totals->net);
    }

    public function test_a_return_earns_the_return_fee_not_the_delivery_fee(): void
    {
        $shipment = $this->walk($this->make(), [
            ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt, ShipmentStatus::Returning,
        ]);

        Tenancy::runFor($this->company, function () use ($shipment) {
            app(\App\Actions\Returns\ReceiveReturns::class)->handle([$shipment->id], $this->staff);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::Returned, $this->staff);
        });

        $response = $this->actingAs($this->staff)->get($this->host().'/reports/profit')->assertOk();
        $totals = $response->viewData('totals');

        $returnFee = Tenancy::runFor($this->company, fn () => (int) $shipment->refresh()->return_fee);

        $this->assertSame($returnFee, $totals->revenue, 'الراجع يكسب أجرة الراجع لا أجرة التوصيل');
        $this->assertSame(750, $totals->commission);
    }

    // ── العزل ───────────────────────────────────────────────────────

    public function test_a_merchant_login_cannot_reach_the_reports(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        foreach (['', '/returns', '/couriers', '/merchants', '/governorates', '/daily', '/profit'] as $path) {
            $this->actingAs($user)->get($this->host().'/reports'.$path)->assertForbidden();
        }
    }

    public function test_one_companys_numbers_never_appear_in_anothers_report(): void
    {
        $this->delivered();
        $this->delivered();

        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);

        $response = $this->actingAs($otherStaff)
            ->get('http://barq.'.config('zajel.tenant_domain').'/reports/couriers')
            ->assertOk();

        $this->assertCount(0, $response->viewData('rows'));
    }
}

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
use App\Models\ShipmentEvent;
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


    // ── المنقطعون ───────────────────────────────────────────────────

    public function test_a_merchant_who_shipped_within_the_window_is_not_called_dormant(): void
    {
        $this->delivered($this->alpha);

        $rows = $this->actingAs($this->staff)
            ->get($this->host().'/reports/dormant?days=30')
            ->assertOk()
            ->viewData('rows');

        $this->assertNotContains($this->alpha->id, $rows->pluck('id'));
    }

    public function test_a_merchant_whose_last_shipment_predates_the_window_is_dormant(): void
    {
        $shipment = $this->delivered($this->alpha);

        // شحنته الوحيدة عمرها ٤٥ يوماً: منقطع عند ٣٠ لا عند ٦٠
        Tenancy::runFor($this->company, fn () => Shipment::where('id', $shipment->id)
            ->update(['created_at' => now()->subDays(45)]));

        $at30 = $this->actingAs($this->staff)->get($this->host().'/reports/dormant?days=30')->viewData('rows');
        $at60 = $this->actingAs($this->staff)->get($this->host().'/reports/dormant?days=60')->viewData('rows');

        $row = $at30->firstWhere('id', $this->alpha->id);

        $this->assertNotNull($row, 'التاجر الصامت منذ ٤٥ يوماً يجب أن يظهر عند ٣٠.');
        $this->assertSame(1, $row->total);
        $this->assertSame(45, (int) $row->last_at->diffInDays(now()));
        $this->assertNotContains($this->alpha->id, $at60->pluck('id'));
    }

    public function test_a_merchant_who_never_shipped_is_dormant_with_no_last_date(): void
    {
        $row = $this->actingAs($this->staff)
            ->get($this->host().'/reports/dormant')
            ->viewData('rows')
            ->firstWhere('id', $this->beta->id);

        $this->assertNotNull($row);
        $this->assertNull($row->last_at);
        $this->assertSame(0, $row->total);
    }

    public function test_a_suspended_merchant_is_not_chased(): void
    {
        Tenancy::runFor($this->company, fn () => $this->beta->update(['status' => 'suspended']));

        $rows = $this->actingAs($this->staff)->get($this->host().'/reports/dormant')->viewData('rows');

        $this->assertNotContains($this->beta->id, $rows->pluck('id'));
    }

    // ── الأرصدة المدينة ─────────────────────────────────────────────

    public function test_only_merchants_in_the_red_are_listed_and_the_deposit_offsets_the_exposure(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->alpha->forceFill(['balance' => -300_000, 'deposit_balance' => 100_000])->save();
            $this->beta->forceFill(['balance' => 250_000])->save();
        });

        $data = $this->actingAs($this->staff)->get($this->host().'/reports/debtors')->assertOk();

        $merchants = $data->viewData('merchants');

        $this->assertSame([$this->alpha->id], $merchants->pluck('id')->all());
        // المكشوف ما بقي بعد الوديعة لا الرصيد كلّه
        $this->assertSame(200_000, $data->viewData('totals')->owed);
        $this->assertSame(100_000, $data->viewData('totals')->deposits);
    }

    public function test_a_deposit_larger_than_the_debt_leaves_nothing_exposed(): void
    {
        Tenancy::runFor($this->company, fn () => $this->alpha
            ->forceFill(['balance' => -50_000, 'deposit_balance' => 80_000])->save());

        $totals = $this->actingAs($this->staff)->get($this->host().'/reports/debtors')->viewData('totals');

        // ولا يصير سالباً فيُطرح من مكشوف تاجر آخر
        $this->assertSame(0, $totals->owed);
    }

    public function test_couriers_over_their_cash_limit_are_counted(): void
    {
        Tenancy::runFor($this->company, fn () => $this->courier
            ->forceFill(['cash_in_hand' => 4_000_000, 'cash_limit' => 3_000_000])->save());

        $data = $this->actingAs($this->staff)->get($this->host().'/reports/debtors')->assertOk();

        $this->assertSame(4_000_000, $data->viewData('totals')->cash);
        $this->assertSame(1, $data->viewData('totals')->over);
    }

    public function test_a_courier_with_no_limit_is_never_counted_as_over(): void
    {
        Tenancy::runFor($this->company, fn () => $this->courier
            ->forceFill(['cash_in_hand' => 9_000_000, 'cash_limit' => 0])->save());

        $this->assertSame(0, $this->actingAs($this->staff)
            ->get($this->host().'/reports/debtors')->viewData('totals')->over);
    }

    // ── تتبّع التغييرات ─────────────────────────────────────────────

    public function test_the_trail_shows_the_status_change_with_who_made_it(): void
    {
        $shipment = $this->delivered();

        $events = $this->actingAs($this->staff)
            ->get($this->host().'/reports/changes')
            ->assertOk()
            ->viewData('events');

        $delivery = $events->first(fn ($e) => $e->to_status === ShipmentStatus::Delivered->value);

        $this->assertNotNull($delivery, 'حدث التسليم يجب أن يظهر في التتبّع.');
        $this->assertSame($shipment->id, $delivery->shipment_id);
        $this->assertSame($shipment->number, $delivery->shipment->number);
        $this->assertSame($this->staff->name, $delivery->actor_name);
    }

    /**
     * تسويةٌ واحدة تكتب عشرات آلاف الأحداث بالنظام فاعلاً، فتدفن
     * ما صنعه بشر — وعنه السؤال. فالإخفاء افتراضيّ ومُعلَن لا صامت.
     */
    public function test_system_events_are_hidden_by_default_and_shown_on_request(): void
    {
        $shipment = $this->delivered();

        Tenancy::runFor($this->company, fn () => ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'from_status' => ShipmentStatus::Delivered->value,
            'to_status'   => 'settled_with_merchant',
            'event_type'  => 'money',
            'actor_type'  => 'system',
            'note'        => 'دخلت كشف التاجر',
        ]));

        $default = $this->actingAs($this->staff)->get($this->host().'/reports/changes')->viewData('events');
        $all = $this->actingAs($this->staff)->get($this->host().'/reports/changes?actor=all')->viewData('events');

        $this->assertNotContains('system', $default->pluck('actor_type'));
        $this->assertContains('system', $all->pluck('actor_type'));
    }

    public function test_the_trail_can_be_narrowed_to_one_waybill(): void
    {
        $mine = $this->delivered();
        $this->delivered($this->beta);

        $events = $this->actingAs($this->staff)
            ->get($this->host().'/reports/changes?number='.$mine->number)
            ->viewData('events');

        $this->assertNotEmpty($events);
        $this->assertSame([$mine->id], $events->pluck('shipment_id')->unique()->values()->all());
    }

    public function test_every_event_type_the_trail_shows_has_an_arabic_label(): void
    {
        $this->delivered();
        $this->failed();

        $events = $this->actingAs($this->staff)
            ->get($this->host().'/reports/changes?actor=all')
            ->viewData('events');

        // فحصٌ على مجموعة فارغة يمرّ دائماً ولا يحرس شيئاً
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertArrayHasKey(
                $event->event_type,
                ShipmentEvent::TYPES,
                "النوع {$event->event_type} يظهر في الشاشة بلا اسم عربيّ.",
            );
        }
    }

    // ── مال الرواجع ─────────────────────────────────────────────────

    /** راجعٌ حتى «قيد الإرجاع»، ويُسلَّم للتاجر إن طُلب. */
    private function returned(?Merchant $merchant = null, bool $handOver = true): Shipment
    {
        $reason = Tenancy::runFor($this->company, fn () => FailureReason::where('code', 'no_answer')->value('id'));

        $shipment = $this->walk($this->make($merchant), [
            ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt, ShipmentStatus::Returning,
        ], [ShipmentStatus::FailedAttempt->value => ['failure_reason_id' => $reason]]);

        return Tenancy::runFor($this->company, function () use ($shipment, $handOver) {
            if ($handOver) {
                app(\App\Actions\Returns\ReceiveReturns::class)->handle([$shipment->id], $this->staff);
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::Returned, $this->staff);
            }

            return $shipment->refresh();
        });
    }

    public function test_returns_money_nets_the_fee_against_the_commission(): void
    {
        $this->returned();
        $this->returned();

        $totals = $this->actingAs($this->staff)
            ->get($this->host().'/reports/returns-money')
            ->assertOk()
            ->viewData('totals');

        // أجرة الرجوع ٢٥٠٠ من التسعيرة، وعمولة الإرجاع ٧٥٠
        $this->assertSame(2, $totals->total);
        $this->assertSame(5_000, $totals->fees);
        $this->assertSame(1_500, $totals->commission);
        $this->assertSame(3_500, $totals->net);
    }

    /** المعلّق لم تُقيَّد أجرته: يُعَدّ معلّقاً لا مكسوباً. */
    public function test_a_return_not_yet_handed_over_is_pending_not_earned(): void
    {
        $this->returned();
        $this->returned(handOver: false);

        $response = $this->actingAs($this->staff)->get($this->host().'/reports/returns-money');

        $this->assertSame(1, $response->viewData('totals')->total);
        $this->assertSame(1, $response->viewData('pending')->total);
        $this->assertSame(2_500, $response->viewData('pending')->fees);
        $this->assertSame(1, $response->viewData('pending')->with_courier);
    }

    public function test_a_free_return_is_counted_as_a_leak(): void
    {
        $shipment = $this->returned(handOver: false);

        Tenancy::runFor($this->company, function () use ($shipment) {
            Shipment::whereKey($shipment->id)->update(['return_fee' => 0]);
            app(\App\Actions\Returns\ReceiveReturns::class)->handle([$shipment->id], $this->staff);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::Returned, $this->staff);
        });

        $totals = $this->actingAs($this->staff)->get($this->host().'/reports/returns-money')->viewData('totals');

        $this->assertSame(1, $totals->free);
        // عمولته خرجت ولم يقابلها شيء
        $this->assertSame(-750, $totals->net);
    }

    /** النسبة من المُقفَل: مسلَّمتان وراجع = ٣٣٪، لا راجع من ثلاثة أُنشئت. */
    public function test_a_merchants_return_rate_is_of_closed_shipments(): void
    {
        $this->delivered($this->beta);
        $this->delivered($this->beta);
        $this->returned($this->beta);
        $this->make($this->beta);   // مفتوحة: لا تدخل النسبة

        $row = $this->actingAs($this->staff)
            ->get($this->host().'/reports/returns-money')
            ->viewData('merchants')
            ->firstWhere('id', $this->beta->id);

        $this->assertSame(1, $row->returned);
        $this->assertSame(33, $row->rate);
    }

    // ── العزل ───────────────────────────────────────────────────────

    public function test_a_merchant_login_cannot_reach_the_reports(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        foreach ([
            '', '/returns', '/couriers', '/merchants', '/governorates', '/daily', '/profit',
            '/dormant', '/debtors', '/changes', '/returns-money',
        ] as $path) {
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

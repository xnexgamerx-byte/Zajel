<?php

namespace Tests\Feature\Reports;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Merchant;
use App\Models\PickupPayout;
use App\Models\PickupShare;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تقارير تتبّع الدفق (docs/plan/17 · المرحلة ٦): مندوب الاستلام وما بيده، والواصل
 * الذي لم يُحاسَب عليه، وما تغيّرت أجوره، وتوزيع النتائج، وحركة الفروع.
 */
class FlowReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $picker;

    private Courier $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->picker, $this->driver] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'P1', 'name' => 'سامر الاستلام', 'phone' => '07720000011', 'type' => 'pickup', 'status' => 'active']),
            Courier::create(['code' => 'D1', 'name' => 'أحمد التوصيل', 'phone' => '07720000012', 'type' => 'delivery', 'status' => 'active']),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** شحنةٌ تمرّ بالحالات المعطاة، ومندوب استلامها إن جُمعت */
    private function shipment(array $path = [], ?Merchant $merchant = null, int $cod = 50_000, ?Courier $picker = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($path, $merchant, $cod, $picker) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => ($merchant ?? $this->merchant)->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->driver->id : null]);

                if ($status === ShipmentStatus::PickedUp) {
                    $shipment->forceFill(['pickup_courier_id' => ($picker ?? $this->picker)->id])->save();
                }
            }

            return $shipment->refresh();
        });
    }

    private function delivered(?Merchant $merchant = null, int $cod = 50_000): Shipment
    {
        return $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered], $merchant, $cod);
    }

    // ------------------------------------------------ المستلمة من مندوب الاستلام

    public function test_pickup_received_shows_what_each_pickup_agent_still_holds(): void
    {
        $this->shipment([ShipmentStatus::PickedUp], cod: 30_000);                         // ما زال بيده
        $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub]);               // استلمناه
        $this->delivered();                                                               // استلمناه ثم سُلّم
        $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::Cancelled]);           // أُلغي
        $this->shipment();                                                                // لم يُجمع بعد: لا يُعدّ

        $response = $this->actingAs($this->owner)->get($this->host().'/reports/pickup-received')->assertOk()
            ->assertSee('سامر الاستلام');

        $row = $response->viewData('rows')->sole();
        $this->assertSame([4, 2, 1, 1, 30_000], [(int) $row->total, $row->received, (int) $row->remaining, (int) $row->cancelled, (int) $row->remaining_amount]);
        $this->assertNotNull($row->oldest);

        // وخارج المدّة لا شيء: المدّة بتاريخ الاستلام
        $this->actingAs($this->owner)->get($this->host().'/reports/pickup-received?from=2020-01-01&to=2020-01-31')
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
    }

    // ------------------------------------------------ أداء مندوبي الاستلام

    public function test_pickup_performance_follows_each_pickup_to_its_outcome_and_earnings(): void
    {
        $this->delivered();
        $this->delivered();
        $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::Returning]);
        $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery]);

        Tenancy::runFor($this->company, function () {
            PickupShare::create(['courier_id' => $this->picker->id, 'shipments_count' => 4, 'rate' => 1000, 'amount' => 4000, 'adjustment' => -1000]);
            PickupPayout::create(['courier_id' => $this->picker->id, 'number' => 'PP-1', 'earned' => 3000, 'paid_amount' => 2500]);
        });

        $row = $this->actingAs($this->owner)->get($this->host().'/reports/pickup-performance')->assertOk()
            ->assertSee('استحقّ')
            ->viewData('rows')->sole();

        $this->assertSame([4, 2, 1, 1, 3000, 2500],
            [$row->total, $row->delivered, $row->returned, $row->in_delivery, $row->earned, $row->paid]);

        // أرباح المندوب لمن يرى حسابه وحده: موظّف العمليات يرى المصير بلا مال
        $operations = $this->makeUser($this->company, UserRole::Operations);
        $this->actingAs($operations)->get($this->host().'/reports/pickup-performance')->assertOk()
            ->assertViewHas('money', false)
            ->assertDontSee('استحقّ')
            ->assertViewHas('rows', fn ($rows) => $rows->sole()->earned === 0 && $rows->sole()->delivered === 2);
    }

    // ------------------------------------------------ واصلة لم يُحاسَب عليها

    public function test_unsettled_lists_delivered_money_still_owed_to_merchants_oldest_first(): void
    {
        $other = $this->makeMerchant($this->company, 'M0002');

        Carbon::setTestNow(now()->subDays(10));
        $old = $this->delivered($other, 20_000);
        Carbon::setTestNow();

        $first = $this->delivered(cod: 40_000);
        $second = $this->delivered(cod: 60_000);
        $settled = $this->delivered(cod: 90_000);
        Tenancy::runFor($this->company, fn () => $settled->forceFill(['merchant_settled_at' => now()])->save());
        $this->shipment([ShipmentStatus::PickedUp]); // لم يصل: ليس هنا

        $response = $this->actingAs($this->owner)->get($this->host().'/reports/unsettled')->assertOk();
        $rows = collect($response->viewData('rows')->items());

        // الأقدم أوّلاً: تاجرٌ وصله قبل عشرة أيام قبل تاجر اليوم
        $this->assertSame([$other->id, $this->merchant->id], $rows->pluck('merchant_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([1, 2], $rows->pluck('shipments')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([20_000, 100_000], $rows->pluck('collected')->map(fn ($n) => (int) $n)->all());

        $totals = $response->viewData('totals');
        $this->assertSame(3, (int) $totals->shipments);
        // الصافي للتاجر بعد أجورنا، لا المحصَّل
        $this->assertSame($old->merchant_due + $first->merchant_due + $second->merchant_due, (int) $totals->net);
        $this->assertLessThan(120_000, (int) $totals->net);

        // «سُلّمت قبل أكثر من ٧ أيام» يعزل المتأخّر
        $late = $this->actingAs($this->owner)->get($this->host().'/reports/unsettled?days=7')->assertOk()->viewData('rows');
        $this->assertSame([$other->id], collect($late->items())->pluck('merchant_id')->map(fn ($id) => (int) $id)->all());
    }

    // ------------------------------------------------ تغيّرت أسعارها

    public function test_repriced_shows_fee_changes_not_yet_settled_with_merchants(): void
    {
        $edit = fn (Shipment $shipment, int $extra) => Tenancy::runFor($this->company, fn () => app(UpdateShipment::class)->handle($shipment->refresh(), [
            ...$shipment->only(['recipient_name', 'recipient_phone', 'governorate_id', 'city_id', 'address',
                'landmark', 'pieces_count', 'weight_grams', 'cod_amount', 'fees_paid_by', 'discount']),
            'extra_fee' => $extra,
        ], $this->owner));

        $raised = $this->shipment();
        $edit($raised, 2000);

        $undone = $this->shipment();
        $edit($undone, 1500);
        $edit($undone, 0);       // أُعيد كما كان: لا أثر له

        $paid = $this->shipment();
        $edit($paid, 3000);
        Tenancy::runFor($this->company, fn () => $paid->forceFill(['merchant_settled_at' => now()])->save());

        $response = $this->actingAs($this->owner)->get($this->host().'/reports/repriced')->assertOk()
            ->assertSee($raised->number)
            ->assertDontSee($undone->number)
            ->assertDontSee($paid->number);

        $row = collect($response->viewData('rows')->items())->sole();
        $this->assertSame([2000, 1], [$row->diff, $row->edits]);
        $this->assertSame($row->before + 2000, $row->after);
        $this->assertSame(2000, $response->viewData('net'));
        $this->assertFalse($response->viewData('truncated'));
    }

    // ------------------------------------------------ التوزيع

    public function test_distribution_splits_each_merchants_shipments_by_outcome(): void
    {
        $other = $this->makeMerchant($this->company, 'M0002');

        $this->delivered();
        $this->delivered();
        $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::Returning]);
        $this->shipment([ShipmentStatus::PickedUp]);
        $this->shipment([ShipmentStatus::Cancelled]);
        $this->delivered($other);

        $response = $this->actingAs($this->owner)->get($this->host().'/reports/distribution')->assertOk()
            ->assertSee('متجر M0001');

        $totals = $response->viewData('totals');
        $this->assertSame([6, 3, 1, 1, 1], [(int) $totals->total, (int) $totals->delivered, (int) $totals->returned, $totals->open, (int) $totals->other]);

        $mine = $response->viewData('merchants')->firstWhere('id', $this->merchant->id);
        $this->assertSame([5, 2, 1, 1, 1], [$mine->total, $mine->delivered, $mine->returned, $mine->open, $mine->other]);

        // لتاجرٍ بعينه: يوماً بيوم، وكل يومٍ في المدّة له عمود ولو فارغاً
        $today = now()->toDateString();
        $daily = $this->actingAs($this->owner)
            ->get($this->host()."/reports/distribution?merchant_id={$this->merchant->id}&from=".now()->subDays(6)->toDateString()."&to={$today}")
            ->assertOk()->viewData('buckets');
        $this->assertCount(7, $daily);
        $this->assertSame(5, $daily->firstWhere('key', $today)->total);

        // وشهراً بشهر إن طالت المدّة — من آخر يومٍ في شهرٍ طويل لا يُقفز شهرٌ قصير
        $monthly = $this->actingAs($this->owner)
            ->get($this->host()."/reports/distribution?merchant_id={$this->merchant->id}&from=2025-01-31&to=2025-04-05")
            ->assertOk()->viewData('buckets');
        $this->assertSame(['2025-01', '2025-02', '2025-03', '2025-04'], $monthly->pluck('key')->all());

        // وتاجرٌ من شركةٍ أخرى لا يُختار
        $stranger = $this->makeMerchant($this->makeCompany('other', 'أخرى'), 'X0001');
        $this->actingAs($this->owner)->get($this->host()."/reports/distribution?merchant_id={$stranger->id}")
            ->assertOk()->assertViewHas('merchant', null);
    }

    // ------------------------------------------------ حركة الفروع

    public function test_branch_traffic_counts_manifests_in_and_out_and_a_branch_sees_only_its_own(): void
    {
        [$basra, $mosul] = Tenancy::runFor($this->company, function () {
            $main = Hub::create(['code' => 'H1', 'name' => 'مركز بغداد', 'type' => 'main', 'branch_id' => Branch::where('code', 'B1')->value('id'), 'is_active' => true]);
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']);
            $mosul = Branch::create(['code' => 'B3', 'name' => 'فرع الموصل']);
            $basraHub = Hub::create(['code' => 'H2', 'name' => 'مركز البصرة', 'type' => 'main', 'branch_id' => $basra->id, 'is_active' => true]);
            $mosulHub = Hub::create(['code' => 'H3', 'name' => 'مركز الموصل', 'type' => 'main', 'branch_id' => $mosul->id, 'is_active' => true]);

            $nine = now()->setTime(9, 15);
            Manifest::create(['code' => 'MF1', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'arrived',
                'departed_at' => $nine, 'arrived_at' => $nine->copy()->addHours(5), 'shipments_count' => 12]);
            Manifest::create(['code' => 'MF2', 'from_hub_id' => $basraHub->id, 'to_hub_id' => $main->id, 'status' => 'dispatched',
                'departed_at' => $nine->copy()->addHour(), 'shipments_count' => 4]);
            Manifest::create(['code' => 'MF3', 'from_hub_id' => $main->id, 'to_hub_id' => $mosulHub->id, 'status' => 'dispatched',
                'departed_at' => $nine, 'shipments_count' => 7]);

            return [$basra, $mosul];
        });

        // الشركة كلّها: كل فرعٍ بما خرج منه وما وصل إليه
        $all = $this->actingAs($this->owner)->get($this->host().'/reports/branch-traffic')->assertOk();
        $this->assertSame(23, $all->viewData('outTotal'));
        $this->assertSame(12, $all->viewData('inTotal'));
        $this->assertSame(19, $all->viewData('outByHour')[9]);
        $this->assertSame(4, $all->viewData('outByHour')[10]);
        $this->assertSame(12, $all->viewData('inByHour')[14]);
        $this->assertSame(19, $all->viewData('overview')->firstWhere('name', 'الفرع الرئيسي')->out);

        // موظّف البصرة يرى فرعه وحده — ولو طلب الموصل
        $clerk = Tenancy::runFor($this->company, fn () => User::create(['name' => 'موظّف البصرة', 'phone' => '07730000099',
            'password' => 'password', 'role' => UserRole::Operations, 'branch_id' => $basra->id, 'is_active' => true]));

        $mine = $this->actingAs($clerk)->get($this->host()."/reports/branch-traffic?branch_id={$mosul->id}")->assertOk()
            ->assertDontSee('id="branch_id"', false);
        $this->assertSame($basra->id, $mine->viewData('branch')->id);
        $this->assertSame([4, 12], [$mine->viewData('outTotal'), $mine->viewData('inTotal')]);
        $this->assertSame([12], $mine->viewData('inFrom')->pluck('shipments')->values()->all());
    }

    public function test_the_reports_need_the_reports_permission(): void
    {
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر', 'phone' => '07790000001',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]));

        foreach (['pickup-received', 'pickup-performance', 'unsettled', 'repriced', 'distribution', 'branch-traffic'] as $report) {
            $this->actingAs($this->owner)->get($this->host()."/reports/{$report}")->assertOk();
            $this->actingAs($merchantUser)->get($this->host()."/reports/{$report}")->assertForbidden();
        }

        // ولكلٍّ بطاقته في «كل التقارير»
        $this->actingAs($this->owner)->get($this->host().'/reports')->assertOk()
            ->assertSee('المستلمة من مندوب الاستلام')->assertSee('شحنات الفروع القادمة والخارجة');
    }
}

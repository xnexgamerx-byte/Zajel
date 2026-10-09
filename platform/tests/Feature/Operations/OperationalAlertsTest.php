<?php

namespace Tests\Feature\Operations;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\Bag;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Courier;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Operations\OperationalAlerts;
use App\Support\DeliveryDeadline;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «التنبيهات التشغيلية» بعد مرور آخر موعدٍ للتوصيل — ٢٤ ساعة (docs/plan/39).
 */
class OperationalAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(array $state = [], ?Merchant $merchant = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($state, $merchant) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => ($merchant ?? $this->merchant)->id, 'recipient_name' => 'علي',
                'recipient_phone' => '0780'.random_int(1000000, 9999999), 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            $shipment->forceFill($state)->save();

            return $shipment->refresh();
        });
    }

    private function courier(string $code, string $name, array $extra = []): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create($extra + [
            'code' => $code, 'name' => $name, 'phone' => '077200000'.substr($code, -2), 'type' => 'delivery', 'status' => 'active',
        ]));
    }

    public function test_overdue_shipments_appear_after_24_hours_ordered_by_priority(): void
    {
        $vip = $this->makeMerchant($this->company, 'M0002');
        Tenancy::runFor($this->company, fn () => $vip->forceFill(['is_vip' => true])->save());

        // عادية: تأخّرت ست ساعات بعد الموعد ولا علامة أهمّية
        $plain = $this->shipment(['status' => 'at_hub', 'picked_up_at' => now()->subHours(30)]);
        // مرتفعة: تاجرها مميّز وسأل عنها — نقطتان
        $asked = $this->shipment(['status' => 'out_for_delivery', 'picked_up_at' => now()->subHours(29)], $vip);
        Tenancy::runFor($this->company, fn () => Conversation::create([
            'merchant_id' => $vip->id, 'shipment_id' => $asked->id, 'subject' => 'وين صارت؟', 'status' => 'open',
            'last_author' => 'merchant', 'last_message_at' => now(), 'staff_unread' => true, 'merchant_unread' => false,
        ]));
        // عاجلة: أكثر من أربعة أيام على استلامها = ثلاثة أيام بعد الموعد
        $urgent = $this->shipment(['status' => 'failed_attempt', 'picked_up_at' => now()->subDays(4)->subHour(), 'attempts_count' => 1]);

        // ما لا يُحسب: لم يمرّ الموعد · أجّلها الزبون إلى يومٍ قادم · واصلة · راجعة · لم تُستلم من التاجر
        $fresh = $this->shipment(['status' => 'at_hub', 'picked_up_at' => now()->subHours(10)]);
        $postponed = $this->shipment(['status' => 'postponed', 'picked_up_at' => now()->subDays(3), 'scheduled_at' => today()->addDays(2)]);
        $delivered = $this->shipment(['status' => 'delivered', 'picked_up_at' => now()->subDays(3), 'delivered_at' => now()->subDay()]);
        $returning = $this->shipment(['status' => 'returning', 'picked_up_at' => now()->subDays(5)]);

        $ranked = Tenancy::runFor($this->company, fn () => app(OperationalAlerts::class)->overdueRanked($this->owner)->get());
        $this->assertSame([$urgent->id, $asked->id, $plain->id], $ranked->pluck('id')->all());
        $this->assertSame(['عاجلة', 'مرتفعة', 'عادية'], $ranked->map(fn ($s) => OperationalAlerts::priority($s)[1])->all());
        $this->assertSame(['تاجر مميّز', 'التاجر سأل عنها'], OperationalAlerts::priority($ranked[1])[3]);

        $this->actingAs($this->owner)->get($this->host().'/operational-alerts')
            ->assertOk()
            ->assertSee('التنبيهات التشغيلية')
            ->assertSee('24 ساعة')
            ->assertSeeInOrder([$urgent->number, $asked->number, $plain->number])
            ->assertSee('أجّلها الزبون إلى يومٍ قادم')
            ->assertDontSee($fresh->number)->assertDontSee($postponed->number)
            ->assertDontSee($delivered->number)->assertDontSee($returning->number);

        // «الأطول تأخيراً»: بترتيب الاستلام وحده
        $this->actingAs($this->owner)->get($this->host().'/operational-alerts?sort=late')
            ->assertSeeInOrder([$urgent->number, $plain->number, $asked->number]);

        // وصفحة الشحنة تقول متى حلّ موعدها، وكم تأخّرت
        $this->assertSame(6, Tenancy::runFor($this->company, fn () => DeliveryDeadline::lateHours($plain->refresh())));
        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$plain->id)
            ->assertOk()->assertSee('متأخرة 6 ساعات')->assertSee('كان آخر موعدٍ للتوصيل');
        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$fresh->id)
            ->assertOk()->assertSee('آخر موعدٍ للتوصيل')->assertSee($fresh->picked_up_at->addDay()->format('Y-m-d H:i'));
    }

    public function test_the_deadline_comes_from_the_company_setting(): void
    {
        $plain = $this->shipment(['status' => 'at_hub', 'picked_up_at' => now()->subHours(30)]);

        $this->company->forceFill(['settings' => ['delivery' => ['deadline_hours' => 48]]])->save();

        $this->actingAs($this->owner)->get($this->host().'/operational-alerts')
            ->assertOk()->assertSee('48 ساعة')->assertDontSee($plain->number);
    }

    public function test_shipments_stuck_at_a_checkpoint_show_where_they_are_and_who_answers_for_them(): void
    {
        $pickupAgent = $this->courier('C10', 'مندوب استلام الكرادة', ['type' => 'pickup']);
        $deliverer = $this->courier('C11', 'مندوب المنصور');
        Tenancy::runFor($this->company, fn () => $this->merchant->forceFill(['pickup_courier_id' => $pickupAgent->id])->save());

        [$bag, $lostBag] = Tenancy::runFor($this->company, function () {
            $main = Hub::create(['code' => 'H1', 'name' => 'مركز بغداد', 'type' => 'main', 'branch_id' => Branch::where('code', 'B1')->value('id'), 'is_active' => true]);
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']);
            $basraHub = Hub::create(['code' => 'H2', 'name' => 'مركز البصرة', 'type' => 'main', 'branch_id' => $basra->id, 'is_active' => true]);

            $bag = Bag::create(['code' => 'BG1', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'in_transit', 'sealed_at' => now()->subDays(2)]);
            $onTheRoad = Manifest::create(['code' => 'MF1', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'dispatched',
                'driver_name' => 'سائق الخط', 'driver_phone' => '07730000001', 'departed_at' => now()->subDays(2)]);
            $onTheRoad->bags()->attach($bag->id, ['company_id' => $this->company->id, 'loaded_at' => now()->subDays(2)]);

            $lostBag = Bag::create(['code' => 'BG2', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'in_transit', 'sealed_at' => now()->subDays(3)]);
            $arrived = Manifest::create(['code' => 'MF2', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'arrived',
                'driver_name' => 'سائق آخر', 'departed_at' => now()->subDays(3), 'arrived_at' => now()->subDays(2)]);
            $arrived->bags()->attach($lostBag->id, ['company_id' => $this->company->id, 'is_missing' => true]);

            return [$bag, $lostBag];
        });

        $notCollected = $this->shipment(['created_at' => now()->subHours(30)]);
        $withPickup = $this->shipment(['status' => 'picked_up', 'picked_up_at' => now()->subHours(28), 'pickup_courier_id' => $pickupAgent->id]);
        $inTransit = $this->shipment(['status' => 'in_transit', 'picked_up_at' => now()->subDays(3), 'current_bag_id' => $bag->id, 'status_changed_at' => now()->subDays(2)]);
        $lost = $this->shipment(['status' => 'in_transit', 'picked_up_at' => now()->subDays(4), 'current_bag_id' => $lostBag->id, 'status_changed_at' => now()->subDays(3)]);
        $returnWithCourier = $this->shipment(['status' => 'returning', 'picked_up_at' => now()->subDays(3),
            'delivery_courier_id' => $deliverer->id, 'status_changed_at' => now()->subHours(30)]);
        // وما لم يمرّ عليه الموعد لا يظهر
        $recent = $this->shipment(['status' => 'picked_up', 'picked_up_at' => now()->subHours(3), 'pickup_courier_id' => $pickupAgent->id]);

        $counts = Tenancy::runFor($this->company, fn () => app(OperationalAlerts::class)->unscannedCounts($this->owner));
        $this->assertSame(['pickup' => 1, 'to_hub' => 1, 'transit' => 2, 'return_courier' => 1, 'return_bag' => 0], $counts);

        $page = fn (string $kind) => $this->actingAs($this->owner)->get($this->host().'/operational-alerts?tab=unscanned&kind='.$kind)->assertOk();

        $page('pickup')->assertSee($notCollected->number)->assertSee('عند التاجر')->assertSee('مندوب استلام الكرادة');
        $page('to_hub')->assertSee($withPickup->number)->assertSee('مع مندوب الاستلام')->assertDontSee($recent->number);
        $page('transit')
            ->assertSee($inTransit->number)->assertSee('على الكشف MF1')->assertSee('سائق الخط')->assertSee('07730000001')
            ->assertSee($lost->number)->assertSee('مفقودة من الكشف MF2');
        $page('return_courier')->assertSee($returnWithCourier->number)->assertSee('مع مندوب التوصيل')->assertSee('مندوب المنصور');
    }

    public function test_unsettled_collections_by_branch_and_courier_for_accounting_only(): void
    {
        $baghdadCourier = $this->courier('C21', 'مندوب الكرخ', ['branch_id' => Tenancy::runFor($this->company, fn () => Branch::where('code', 'B1')->value('id'))]);

        $this->shipment(['status' => 'delivered', 'delivered_at' => now()->subDays(2), 'collected_amount' => 75_000, 'delivery_courier_id' => $baghdadCourier->id]);
        $this->shipment(['status' => 'delivered', 'delivered_at' => now()->subHours(30), 'collected_amount' => 25_000, 'delivery_courier_id' => $baghdadCourier->id]);
        // سُلّمت قبل ساعتين: لم يمرّ الموعد · وما سُوّي لا يُعرض
        $this->shipment(['status' => 'delivered', 'delivered_at' => now()->subHours(2), 'collected_amount' => 40_000, 'delivery_courier_id' => $baghdadCourier->id]);
        $this->shipment(['status' => 'delivered', 'delivered_at' => now()->subDays(3), 'collected_amount' => 60_000,
            'delivery_courier_id' => $baghdadCourier->id, 'courier_settled_at' => now()->subDay()]);

        $rows = Tenancy::runFor($this->company, fn () => app(OperationalAlerts::class)->unsettled($this->owner));
        $this->assertCount(1, $rows);
        $this->assertSame([2, 100_000], [$rows[0]->shipments, $rows[0]->amount]);

        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        $this->actingAs($accountant)->get($this->host().'/operational-alerts?tab=unsettled')
            ->assertOk()->assertSee('مندوب الكرخ')->assertSee('100,000')->assertSee('كشف المندوب');

        // الكول سنتر لا يرى المال: لا تبويب له، والرابط يعود إلى المتأخرة
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)->get($this->host().'/operational-alerts?tab=unsettled')
            ->assertOk()->assertDontSee('تحصيلات لم تُسوَّ')->assertDontSee('مندوب الكرخ')->assertSee('تجاوزت موعد التوصيل');
    }

    public function test_the_home_card_counts_what_is_waiting(): void
    {
        $this->shipment(['status' => 'at_hub', 'picked_up_at' => now()->subHours(30)]);
        $this->shipment(['status' => 'picked_up', 'picked_up_at' => now()->subHours(30)]);

        $this->actingAs($this->owner)->get($this->host().'/')
            ->assertOk()->assertSee('التنبيهات التشغيلية')->assertSee('تجاوزت موعد التوصيل')->assertSee('لم تُمسح عند نقطة انتقال');
    }
}

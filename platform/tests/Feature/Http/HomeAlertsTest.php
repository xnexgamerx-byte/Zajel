<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\Bag;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Dashboard\HomeAlerts;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البطاقات السبع في الرئيسية كما في المعتاد: كلٌّ تجد ما تسأل عنه، ولا تظهر
 * إلّا لمن يفتح ما خلفها.
 */
class HomeAlertsTest extends TestCase
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

    private function shipment(array $state = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($state) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '0780'.random_int(1000000, 9999999), 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            $shipment->forceFill($state)->save();

            return $shipment->refresh();
        });
    }

    public function test_each_card_finds_what_it_asks_about(): void
    {
        [$courier, $basraHub, $bag] = Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C1', 'name' => 'مندوب المنصور', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']);
            $main = Hub::create(['code' => 'H1', 'name' => 'مركز بغداد', 'type' => 'main', 'branch_id' => Branch::where('code', 'B1')->value('id'), 'is_active' => true]);
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']);
            $basraHub = Hub::create(['code' => 'H2', 'name' => 'مركز البصرة', 'type' => 'main', 'branch_id' => $basra->id, 'is_active' => true]);
            $bag = Bag::create(['code' => 'BG1', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'in_transit']);
            Manifest::create(['code' => 'MF1', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'dispatched',
                'departed_at' => now()->subHours(2), 'shipments_count' => 12]);
            Manifest::create(['code' => 'MF0', 'from_hub_id' => $main->id, 'to_hub_id' => $basraHub->id, 'status' => 'arrived',
                'departed_at' => now()->subDays(3)]);

            return [$courier, $basraHub, $bag];
        });

        $original = $this->shipment();
        $this->shipment(['duplicate_of_id' => $original->id]);
        $this->shipment(['status' => 'out_for_delivery', 'delivery_courier_id' => $courier->id, 'status_changed_at' => now()->subDays(4)]);
        $this->shipment(['status' => 'out_for_delivery', 'delivery_courier_id' => $courier->id, 'status_changed_at' => now()->subHours(5)]);
        $this->shipment(['status' => 'delivered', 'is_forced' => true, 'forced_reason' => 'الزبون استلم ولم يُسجَّل',
                         'delivery_courier_id' => $courier->id, 'status_changed_at' => now()->subHours(3)]);
        $this->shipment(['status' => 'delivered', 'delivered_at' => now()->subDays(5), 'collected_amount' => 75_000,
                         'delivery_courier_id' => $courier->id]);
        $this->shipment(['status' => 'in_transit', 'current_bag_id' => $bag->id, 'status_changed_at' => now()->subDays(2)]);
        $this->shipment(['status' => 'returning', 'return_received_at' => now(), 'current_bag_id' => $bag->id]);

        $alerts = collect(Tenancy::runFor($this->company, fn () => app(HomeAlerts::class)->for($this->owner)))->keyBy('key');

        $this->assertSame(['duplicates', 'with_courier', 'forced', 'unpaid', 'in_transit', 'returns_away', 'manifests'], $alerts->keys()->all());
        $this->assertSame(1, $alerts['duplicates']['total']);
        $this->assertSame(1, $alerts['with_courier']['total']);
        $this->assertSame(['مندوب المنصور', 'شحنة واحدة'], $alerts['with_courier']['rows'][0]['cells']);
        $this->assertSame(1, $alerts['forced']['total']);
        $this->assertSame('الزبون استلم ولم يُسجَّل', $alerts['forced']['rows'][0]['cells'][2]);
        // المبلغ المحصَّل وأيام تأخيره — وخمسة أيام تأخيرٌ يُلوَّن
        $this->assertSame(['مندوب المنصور', '75,000 د.ع', '5 أيام'], $alerts['unpaid']['rows'][0]['cells']);
        $this->assertTrue($alerts['unpaid']['rows'][0]['late']);
        $this->assertSame(['مركز البصرة', 'شحنة واحدة'], $alerts['in_transit']['rows'][0]['cells']);
        $this->assertSame(['مركز البصرة', 'شحنة واحدة'], $alerts['returns_away']['rows'][0]['cells']);
        $this->assertSame(1, $alerts['manifests']['total']);
        $this->assertSame('MF1', $alerts['manifests']['rows'][0]['cells'][0]);

        $this->actingAs($this->owner)->get($this->host().'/')
            ->assertOk()
            ->assertSee('تنبيهات')
            ->assertSee('7 من 7 تستحقّ النظر')
            ->assertSee('طلبات عند المندوب منذ ٧٢ ساعة')
            ->assertSee('75,000 د.ع');
    }

    public function test_cards_show_only_to_whoever_opens_what_is_behind_them(): void
    {
        $agent = $this->makeUser($this->company, UserRole::CustomerService);

        $keys = collect(Tenancy::runFor($this->company, fn () => app(HomeAlerts::class)->for($agent)))->pluck('key')->all();
        $this->assertSame(['with_courier'], $keys);

        // والفارغة تنطوي سطراً
        $this->actingAs($agent)->get($this->host().'/')
            ->assertOk()->assertSee('لا شيء يستحقّ النظر');
    }
}

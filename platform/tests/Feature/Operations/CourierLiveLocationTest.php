<?php

namespace Tests\Feature\Operations;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ShipmentLocation;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * موقع هاتف المندوب (docs/plan/62): يُرسله ما دامت شاشته مفتوحة — حدّث شيئاً أو لم يحدّث — فيُرى
 * في «تتبّع المناديب» حيث هو، والشركة تُلزمه بتفعيله ما لم تُطفئ الإلزام.
 */
class CourierLiveLocationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Courier $courier;

    private User $courierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);
        Tenancy::runFor($this->company, function () {
            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'نوفل', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
            ]);
            $this->courierUser = User::create([
                'name' => 'نوفل', 'phone' => '07720000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true,
            ]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function points(): array
    {
        $page = $this->actingAs($this->owner)->get($this->host().'/couriers/tracking')->assertOk();

        return json_decode(html_entity_decode((string) str($page->getContent())->match("/data-points='([^']*)'/")), true);
    }

    public function test_the_phone_sends_its_place_and_a_wide_guess_is_dropped(): void
    {
        $this->actingAs($this->courierUser)->postJson($this->host().'/courier/location', ['lat' => 33.3, 'lng' => 44.4, 'accuracy' => 25])
            ->assertNoContent();
        $this->courier->refresh();
        $this->assertEqualsWithDelta(33.3, (float) $this->courier->last_lat, 0.00001);
        $this->assertTrue($this->courier->last_seen_at->gt(now()->subMinute()));

        // تقديرٌ بكيلومترات (شبكة بلا GPS) لا يُحفظ
        $this->actingAs($this->courierUser)->postJson($this->host().'/courier/location', ['lat' => 30.5, 'lng' => 47.8, 'accuracy' => 5000])
            ->assertNoContent();
        $this->assertEqualsWithDelta(33.3, (float) $this->courier->refresh()->last_lat, 0.00001);

        $this->actingAs($this->courierUser)->postJson($this->host().'/courier/location', ['lat' => 200, 'lng' => 44.4])
            ->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($this->host().'/courier/location', ['lat' => 33.3, 'lng' => 44.4])
            ->assertForbidden();
    }

    public function test_tracking_shows_the_phone_even_before_any_shipment(): void
    {
        $this->assertSame([], $this->points());

        $this->actingAs($this->courierUser)->postJson($this->host().'/courier/location', ['lat' => 33.29, 'lng' => 44.37]);
        $points = $this->points();
        $this->assertSame('نوفل', $points[0]['name']);
        $this->assertTrue($points[0]['exact']);
        $this->assertFalse($points[0]['stale']);
        $this->assertStringContainsString('موقع هاتفه الآن', $points[0]['note']);
        $this->actingAs($this->owner)->get($this->host().'/couriers/tracking')->assertSee('يرسل موقعه الآن: 1');

        // أغلق الشاشة منذ ساعة: آخر موقعه بلون السكوت
        $this->travel(61)->minutes();
        $points = $this->points();
        $this->assertTrue($points[0]['stale']);
        $this->assertStringContainsString('آخر موقعٍ من هاتفه', $points[0]['note']);
    }

    public function test_the_courier_screen_asks_for_the_location_unless_the_company_turns_it_off(): void
    {
        $this->actingAs($this->courierUser)->get($this->host().'/courier')->assertOk()
            ->assertSee('data-required="1"', false)->assertSee('فعّل الموقع لتكمل');

        $this->actingAs($this->owner)->get($this->host().'/settings/company')->assertOk()
            ->assertSee('المندوب لا يعمل إلّا وموقع هاتفه مفعّل');

        $this->company->update(['settings' => ['courier' => ['require_location' => false]]]);
        $this->actingAs($this->courierUser)->get($this->host().'/courier')->assertOk()
            ->assertSee('data-required="0"', false);
    }

    public function test_a_shipment_on_its_way_is_where_its_courier_is_now(): void
    {
        $merchant = $this->makeMerchant($this->company);
        $shipment = Tenancy::runFor($this->company, function () use ($merchant) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 31_000,
            ], $this->owner);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner,
                ['courier_id' => $this->courier->id, 'lat' => 33.1, 'lng' => 44.1]);

            return $shipment->refresh();
        });

        $this->actingAs($this->courierUser)->postJson($this->host().'/courier/location', ['lat' => 33.35, 'lng' => 44.45]);
        $at = Tenancy::runFor($this->company, fn () => ShipmentLocation::of(Shipment::find($shipment->id)));
        $this->assertEqualsWithDelta(33.35, $at['lat'], 0.00001);
        $this->assertTrue($at['exact']);

        // هاتفه سكت طويلاً: يرجع إلى آخر موقعٍ مع حدثٍ عليها
        $this->travel(2)->hours();
        $at = Tenancy::runFor($this->company, fn () => ShipmentLocation::of(Shipment::find($shipment->id)));
        $this->assertEqualsWithDelta(33.1, $at['lat'], 0.00001);
    }
}

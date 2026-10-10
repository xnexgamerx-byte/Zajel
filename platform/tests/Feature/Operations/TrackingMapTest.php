<?php

namespace Tests\Feature\Operations;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الخرائط (docs/plan/61): المناديب على خريطة محافظة الفرع، والشحنة على خريطة التاجر والزبون —
 * من آخر موقعٍ أرسله هاتف المندوب، أو تقريبياً بمحافظتها.
 */
class TrackingMapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'نوفل', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** رابط التتبّع على عنوان الشركة: يُبنى بلا طلبٍ قبله فيأخذ عنوان التطبيق */
    private function trackingUrl(Shipment $shipment): string
    {
        return $this->host().parse_url(Tenancy::runFor($this->company, fn () => Tracking::url($shipment)), PHP_URL_PATH);
    }

    private function outWith(?array $at = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($at) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 31_000,
            ], $this->owner);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner,
                ['courier_id' => $this->courier->id] + ($at ? ['lat' => $at[0], 'lng' => $at[1]] : []));

            return $shipment->refresh();
        });
    }

    public function test_couriers_show_on_the_map_where_their_phone_last_was_or_roughly_at_the_governorate(): void
    {
        $this->outWith([33.30, 44.40]);

        $page = $this->actingAs($this->owner)->get($this->host().'/couriers/tracking')->assertOk()->assertSee('data-map', false);
        $points = json_decode(html_entity_decode((string) str($page->getContent())->match("/data-points='([^']*)'/")), true);
        $this->assertSame('نوفل', $points[0]['name']);
        $this->assertTrue($points[0]['exact']);
        $this->assertEqualsWithDelta(33.30, $points[0]['lat'], 0.0001);
        // والخريطة على بغداد: محافظة الفرع الرئيسي
        $this->assertStringContainsString("data-center='[33.3152,44.3661]'", $page->getContent());
    }

    public function test_the_merchant_app_and_the_customer_see_the_shipment_on_the_map(): void
    {
        $shipment = $this->outWith([33.31, 44.42]);
        $user = Tenancy::runFor($this->company, fn () => User::create(['name' => 'ت', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]));
        $api = $this->host().'/api/v1';
        $token = $this->postJson($api.'/login', ['username' => $user->username, 'password' => 'password', 'app' => 'merchant'])->json('token');

        $this->withToken($token)->getJson($api.'/merchant/shipments/'.$shipment->id)->assertOk()
            ->assertJsonPath('tracking.location.exact', true)
            ->assertJsonPath('tracking.location.lat', 33.31);

        $this->get($this->trackingUrl($shipment))
            ->assertOk()->assertSee('أين شحنتك الآن')->assertSee('data-map', false);
    }

    public function test_without_a_phone_position_the_customer_sees_no_map(): void
    {
        $shipment = $this->outWith();

        $this->get($this->trackingUrl($shipment))
            ->assertOk()->assertDontSee('أين شحنتك الآن');
    }
}

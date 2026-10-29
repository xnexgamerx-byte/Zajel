<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «إخفاء المندوب عن التاجر» (docs/plan/81، 95): خيارٌ للتاجر، مُعلَّمٌ لكلّ تاجر — لا يرى اسم المندوب ولا هاتفه
 * ولا موقعه حتى يُفتح له.
 */
class CourierHiddenFromMerchantTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Merchant $other;

    private User $user;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->other = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر', 'phone' => '07790000001',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function headers(): array
    {
        $token = $this->postJson($this->host().'/api/v1/login', [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function ship(Merchant $merchant, string $name, bool $pickedUp = false): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $name, $pickedUp) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 40_000,
            ], $this->staff);
            if ($pickedUp) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            }

            return $shipment->refresh();
        });
    }

    private function out(): Shipment
    {
        return Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C1', 'name' => 'مندوب الكرادة', 'phone' => '07720000071', 'type' => 'both',
                'status' => 'active', 'last_lat' => 33.3, 'last_lng' => 44.4, 'last_seen_at' => now()]);
            $shipment = $this->ship($this->merchant, 'زبون', pickedUp: true);
            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $courier->id]);
            PickupRequest::create(['number' => 'PR-1', 'merchant_id' => $this->merchant->id, 'courier_id' => $courier->id,
                'status' => 'assigned', 'expected_count' => 3, 'requested_at' => now()]);

            return $shipment->refresh();
        });
    }

    public function test_the_courier_is_hidden_from_every_merchant_until_opened_for_one(): void
    {
        $shipment = $this->out();
        $headers = $this->headers();

        // مُعلَّمٌ للتاجر: لا اسم ولا هاتف ولا موقع
        $this->assertTrue($this->merchant->refresh()->hidesCourier());
        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$shipment->id, $headers)->assertOk()
            ->assertJsonPath('tracking.courier', null)->assertJsonPath('tracking.location', null)
            ->assertDontSee('مندوب الكرادة');
        $this->getJson($this->host().'/api/v1/merchant/pickups', $headers)->assertOk()
            ->assertJsonPath('data.0.courier', null)->assertDontSee('07720000071');
        $this->actingAs($this->user)->get($this->host().'/portal/pickups')->assertOk()
            ->assertDontSee('مندوب الكرادة')->assertDontSee('07720000071');

        // تُفتح لهذا التاجر وحده
        Tenancy::runFor($this->company, fn () => $this->merchant->update(['hides_courier' => false]));
        $this->app['auth']->forgetGuards();
        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$shipment->id, $headers)->assertOk()
            ->assertJsonPath('tracking.courier', 'مندوب الكرادة')
            ->assertJsonPath('tracking.location.exact', true);
        $this->getJson($this->host().'/api/v1/merchant/pickups', $headers)->assertOk()
            ->assertJsonPath('data.0.courier.name', 'مندوب الكرادة');
        $this->actingAs($this->user->fresh())->get($this->host().'/portal/pickups')->assertOk()->assertSee('مندوب الكرادة');

        // والتاجر الآخر ما زال مخفيّاً عنه
        $this->assertTrue($this->other->refresh()->hidesCourier());
    }

    public function test_staff_set_the_option_on_the_merchant_form_not_the_courier_form(): void
    {
        $this->actingAs($this->staff)->get($this->host().'/merchants/'.$this->merchant->id.'/edit')->assertOk()
            ->assertSee('إخفاء المندوب عن التاجر');
        $this->actingAs($this->staff)->get($this->host().'/couriers/create')->assertOk()->assertDontSee('إخفاء المندوب');
    }
}

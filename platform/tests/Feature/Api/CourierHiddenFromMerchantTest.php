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
 * «إخفاء المندوب عن العميل» (docs/plan/81): مندوبٌ أخفته الشركة لا يرى التاجر اسمه ولا هاتفه ولا موقعه —
 * والظاهر يُرى كما كان.
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

    private function out(bool $hidden): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($hidden) {
            $courier = Courier::create(['code' => 'C'.($hidden ? 'H' : 'V'), 'name' => $hidden ? 'مندوب سرّي' : 'مندوب ظاهر',
                'phone' => $hidden ? '07720000071' : '07720000072', 'type' => 'both', 'status' => 'active',
                'hidden_from_merchants' => $hidden, 'last_lat' => 33.3, 'last_lng' => 44.4, 'last_seen_at' => now()]);
            $shipment = $this->ship($this->merchant, $hidden ? 'زبون أ' : 'زبون ب', pickedUp: true);
            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $courier->id]);
            PickupRequest::create(['number' => 'PR-'.($hidden ? '1' : '2'), 'merchant_id' => $this->merchant->id, 'courier_id' => $courier->id,
                'status' => 'assigned', 'expected_count' => 3, 'requested_at' => now()]);

            return $shipment->refresh();
        });
    }

    public function test_a_hidden_courier_is_not_shown_to_the_merchant(): void
    {
        $hidden = $this->out(true);
        $shown = $this->out(false);
        $headers = $this->headers();

        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$hidden->id, $headers)->assertOk()
            ->assertJsonPath('tracking.courier', null)->assertJsonPath('tracking.location', null)
            ->assertDontSee('مندوب سرّي');
        $this->getJson($this->host().'/api/v1/merchant/shipments/'.$shown->id, $headers)->assertOk()
            ->assertJsonPath('tracking.courier', 'مندوب ظاهر')
            ->assertJsonPath('tracking.location.exact', true);

        $this->getJson($this->host().'/api/v1/merchant/pickups', $headers)->assertOk()
            ->assertJsonPath('data.0.courier.name', 'مندوب ظاهر')->assertJsonPath('data.1.courier', null)
            ->assertDontSee('07720000071');

        $this->actingAs($this->user)->get($this->host().'/portal/pickups')->assertOk()
            ->assertDontSee('مندوب سرّي')->assertDontSee('07720000071')->assertSee('مندوب ظاهر');
    }

    public function test_staff_set_the_option_on_the_courier_form(): void
    {
        $this->actingAs($this->staff)->get($this->host().'/couriers/create')->assertOk()->assertSee('إخفاء المندوب عن العميل');
    }
}

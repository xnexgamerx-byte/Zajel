<?php

namespace Tests\Feature\Portal;

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

class MerchantPortalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $alphaUser;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
        $this->alphaUser = $this->merchantUser($this->alpha);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchantUser(Merchant $merchant): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name'        => 'تاجر '.$merchant->code,
            'phone'       => '0779'.str_pad((string) $merchant->id, 7, '0', STR_PAD_LEFT),
            'password'    => 'password',
            'role'        => UserRole::Merchant,
            'merchant_id' => $merchant->id,
            'is_active'   => true,
        ]));
    }

    private function shipmentFor(Merchant $merchant, string $recipient = 'زبون'): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => $merchant->id,
            'recipient_name'  => $recipient,
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => 50_000,
        ], $this->staff));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'recipient_name'  => 'زينب كاظم',
            'recipient_phone' => '07712345678',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - المنصور',
            'landmark'        => 'قرب سوق المنصور',
            'pieces_count'    => 1,
            'weight_grams'    => 1000,
            'cod_amount'      => 60_000,
            'fees_paid_by'    => 'merchant',
        ], $overrides);
    }

    // ------------------------------------------------------- من يدخل

    public function test_a_merchant_logging_in_lands_on_their_portal(): void
    {
        $this->post($this->host().'/login', [
            'phone' => $this->alphaUser->phone, 'password' => 'password',
        ])->assertRedirect($this->host().'/portal');
    }

    public function test_staff_still_land_on_the_operations_panel(): void
    {
        $this->post($this->host().'/login', [
            'phone' => $this->staff->phone, 'password' => 'password',
        ])->assertRedirect($this->host());   // لوحة اليوم
    }

    public function test_staff_cannot_open_the_merchant_portal(): void
    {
        $this->actingAs($this->staff)->get($this->host().'/portal')->assertForbidden();
    }

    public function test_a_suspended_merchant_is_locked_out(): void
    {
        Tenancy::runFor($this->company, fn () => $this->alpha->update(['status' => 'suspended']));

        $this->actingAs($this->alphaUser)->get($this->host().'/portal')->assertForbidden();
    }

    // -------------------------------------------------------- ما يرى

    public function test_the_portal_shows_only_this_merchants_shipments(): void
    {
        $this->shipmentFor($this->alpha, 'زبون ألفا');
        $this->shipmentFor($this->beta, 'زبون بيتا');

        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal/shipments')
            ->assertOk()
            ->assertSee('زبون ألفا')
            ->assertDontSee('زبون بيتا');
    }

    public function test_a_merchant_cannot_open_another_merchants_shipment(): void
    {
        $foreign = $this->shipmentFor($this->beta, 'زبون بيتا');

        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal/shipments/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_the_statement_shows_only_this_merchants_ledger(): void
    {
        $courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
        ]));

        $numbers = [];

        foreach ([$this->alpha, $this->beta] as $merchant) {
            $shipment = $this->shipmentFor($merchant, 'زبون '.$merchant->code);
            $numbers[$merchant->code] = $shipment->number;

            Tenancy::runFor($this->company, function () use ($shipment, $courier) {
                $change = app(ChangeShipmentStatus::class);
                $change->handle($shipment, ShipmentStatus::PickedUp, $this->staff);
                $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff,
                    ['courier_id' => $courier->id]);
                $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff);
            });
        }

        // كشف الحساب يربط كل سطر برقم وصله، فالتحقّق عليه لا على اسم الزبون
        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal/statement')
            ->assertOk()
            ->assertSee('45,000')                       // مستحقّه هو
            ->assertSee($numbers['M0001'])
            ->assertDontSee($numbers['M0002']);
    }

    // ------------------------------------------------------ ما يفعل

    public function test_a_merchant_creates_a_shipment_priced_by_the_carrier(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments', $this->payload())
            ->assertSessionHas('success');

        $shipment = Tenancy::runFor($this->company, fn () => Shipment::firstOrFail());

        $this->assertSame($this->alpha->id, $shipment->merchant_id);
        $this->assertSame(5000, $shipment->delivery_fee);     // من تسعيرة الشركة
        $this->assertSame(55_000, $shipment->merchant_due);
        $this->assertSame('merchant_portal', $shipment->source);
    }

    public function test_a_merchant_cannot_create_a_shipment_for_another_merchant(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments', $this->payload(['merchant_id' => $this->beta->id]))
            ->assertSessionHas('success');

        // merchant_id من الحساب لا من الطلب
        $shipment = Tenancy::runFor($this->company, fn () => Shipment::firstOrFail());
        $this->assertSame($this->alpha->id, $shipment->merchant_id);
    }

    public function test_a_merchant_cannot_set_their_own_delivery_fee(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments', $this->payload([
                'delivery_fee' => 0, 'discount' => 5000,
            ]))
            ->assertSessionHas('success');

        $shipment = Tenancy::runFor($this->company, fn () => Shipment::firstOrFail());

        $this->assertSame(5000, $shipment->delivery_fee);
        $this->assertSame(0, $shipment->discount);
    }

    public function test_a_shipment_without_a_landmark_is_refused_in_the_portal_too(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments', $this->payload(['landmark' => '']))
            ->assertSessionHasErrors('landmark');
    }

    public function test_a_merchant_requests_a_pickup(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/pickups', ['expected_count' => 12])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () {
            $pickup = PickupRequest::firstOrFail();

            $this->assertSame($this->alpha->id, $pickup->merchant_id);
            $this->assertSame(12, $pickup->expected_count);
            $this->assertSame('pending', $pickup->status);
            $this->assertSame('PU000001', $pickup->number);
        });
    }

    public function test_a_second_open_pickup_request_is_refused(): void
    {
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/pickups', ['expected_count' => 12]);

        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/pickups', ['expected_count' => 5])
            ->assertSessionHasErrors('expected_count');

        Tenancy::runFor($this->company, fn () => $this->assertSame(1, PickupRequest::count()));
    }

    public function test_the_dashboard_renders_with_the_carriers_branding(): void
    {
        $this->shipmentFor($this->alpha, 'زبون ألفا');

        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal')
            ->assertOk()
            ->assertSee('الزاجل')              // علامة الناقل لا علامة المنصّة
            ->assertSee('<span class="brand-tile">ز</span>', false)
            ->assertDontSee('وهج')
            ->assertSee('متجر M0001')
            ->assertSee('زبون ألفا');
    }
}

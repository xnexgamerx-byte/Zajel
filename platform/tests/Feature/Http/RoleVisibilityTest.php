<?php

namespace Tests\Feature\Http;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * العزل الثاني: داخل الشركة الواحدة.
 *
 * CompanyScope يمنع شركة من رؤية أخرى. هذه الاختبارات تمنع تاجراً
 * من رؤية تاجر آخر في الشركة نفسها — وهذا انكشاف لا يقلّ خطراً:
 * أسعار المنافس وأرقام زبائنه.
 */
class RoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchantUser(Merchant $merchant): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر '.$merchant->code, 'phone' => '0779'.substr((string) (1000000 + $merchant->id), 0, 7),
            'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
    }

    private function shipmentFor(Merchant $merchant, string $recipient): Shipment
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

    public function test_a_merchant_sees_only_their_own_shipments(): void
    {
        $this->shipmentFor($this->alpha, 'زبون ألفا');
        $this->shipmentFor($this->beta, 'زبون بيتا');

        $this->actingAs($this->merchantUser($this->alpha))
            ->get($this->host().'/shipments')
            ->assertOk()
            ->assertSee('زبون ألفا')
            ->assertDontSee('زبون بيتا');
    }

    public function test_a_merchant_cannot_open_another_merchants_shipment_by_id(): void
    {
        $foreign = $this->shipmentFor($this->beta, 'زبون بيتا');

        $this->actingAs($this->merchantUser($this->alpha))
            ->get($this->host().'/shipments/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_a_merchant_cannot_reach_the_merchants_list(): void
    {
        $this->actingAs($this->merchantUser($this->alpha))
            ->get($this->host().'/merchants')
            ->assertForbidden();
    }

    public function test_a_merchant_cannot_reach_the_couriers_list_or_the_cash_board(): void
    {
        $user = $this->merchantUser($this->alpha);

        $this->actingAs($user)->get($this->host().'/couriers')->assertForbidden();
        $this->actingAs($user)->get($this->host().'/couriers/cash')->assertForbidden();
    }

    public function test_a_merchant_cannot_change_a_shipment_status(): void
    {
        $shipment = $this->shipmentFor($this->alpha, 'زبون ألفا');

        $this->actingAs($this->merchantUser($this->alpha))
            ->post($this->host().'/shipments/'.$shipment->id.'/status', ['status' => 'picked_up'])
            ->assertForbidden();

        $this->assertSame(ShipmentStatus::Created, $shipment->fresh()->status);
    }

    public function test_a_courier_sees_only_the_shipments_assigned_to_them(): void
    {
        $courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
        ]));

        $mine = $this->shipmentFor($this->alpha, 'زبون المندوب');
        $other = $this->shipmentFor($this->alpha, 'زبون غيره');

        Tenancy::runFor($this->company, function () use ($mine, $courier) {
            $change = app(ChangeShipmentStatus::class);
            $change->handle($mine, ShipmentStatus::PickedUp, $this->staff);
            $change->handle($mine->refresh(), ShipmentStatus::OutForDelivery, $this->staff, [
                'courier_id' => $courier->id,
            ]);
        });

        $courierUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
        ]));

        $this->actingAs($courierUser)
            ->get($this->host().'/shipments')
            ->assertOk()
            ->assertSee('زبون المندوب')
            ->assertDontSee('زبون غيره');

        $this->assertNotNull($other);
    }

    public function test_staff_still_see_everything_in_their_company(): void
    {
        $this->shipmentFor($this->alpha, 'زبون ألفا');
        $this->shipmentFor($this->beta, 'زبون بيتا');

        $this->actingAs($this->staff)
            ->get($this->host().'/shipments')
            ->assertOk()
            ->assertSee('زبون ألفا')
            ->assertSee('زبون بيتا');
    }
}

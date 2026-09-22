<?php

namespace Tests\Feature\Courier;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierAppTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $ahmed;

    private Courier $haider;

    private User $ahmedUser;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        $this->ahmed = $this->makeCourier('C1', 'أحمد', '07720000001');
        $this->haider = $this->makeCourier('C2', 'حيدر', '07720000002');
        $this->ahmedUser = $this->courierUser($this->ahmed);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function makeCourier(string $code, string $name, string $phone, string $type = 'delivery'): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => $code, 'name' => $name, 'phone' => $phone,
            'type' => $type, 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_pickup' => 500,
        ]));
    }

    private function courierUser(Courier $courier): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name' => $courier->name, 'phone' => $courier->phone, 'password' => 'password',
            'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
        ]));
    }

    private function assigned(Courier $courier, int $cod = 50_000, string $recipient = 'علي حسين'): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier, $cod, $recipient) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => $recipient,
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff,
                ['courier_id' => $courier->id]);

            return $shipment->refresh();
        });
    }

    // -------------------------------------------------------- من يدخل

    public function test_a_courier_logging_in_lands_on_their_tasks(): void
    {
        $this->post($this->host().'/login', [
            'phone' => $this->ahmedUser->phone, 'password' => 'password',
        ])->assertRedirect($this->host().'/courier');
    }

    public function test_staff_and_merchants_cannot_open_the_courier_screen(): void
    {
        $this->actingAs($this->staff)->get($this->host().'/courier')->assertForbidden();
    }

    public function test_a_suspended_courier_is_locked_out(): void
    {
        Tenancy::runFor($this->company, fn () => $this->ahmed->update(['status' => 'suspended']));

        $this->actingAs($this->ahmedUser)->get($this->host().'/courier')->assertForbidden();
    }

    // --------------------------------------------------------- ما يرى

    public function test_a_courier_sees_only_what_is_in_their_hands(): void
    {
        $this->assigned($this->ahmed, 50_000, 'زبون أحمد');
        $this->assigned($this->haider, 50_000, 'زبون حيدر');

        $this->actingAs($this->ahmedUser)
            ->get($this->host().'/courier')
            ->assertOk()
            ->assertSee('زبون أحمد')
            ->assertDontSee('زبون حيدر');
    }

    public function test_a_courier_cannot_open_another_couriers_shipment(): void
    {
        $foreign = $this->assigned($this->haider);

        $this->actingAs($this->ahmedUser)
            ->get($this->host().'/courier/shipments/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_search_only_finds_shipments_in_their_hands(): void
    {
        $mine = $this->assigned($this->ahmed);
        $foreign = $this->assigned($this->haider);

        $this->actingAs($this->ahmedUser)
            ->get($this->host().'/courier/search?q='.$mine->number)
            ->assertRedirect($this->host().'/courier/shipments/'.$mine->id);

        $this->actingAs($this->ahmedUser)
            ->get($this->host().'/courier/search?q='.$foreign->number)
            ->assertSessionHasErrors('q');
    }

    // -------------------------------------------------------- ما يفعل

    public function test_delivering_records_the_money_and_the_courier_as_actor(): void
    {
        $shipment = $this->assigned($this->ahmed, 50_000);

        $this->actingAs($this->ahmedUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, [
                'action' => 'delivered', 'collected_amount' => 50_000,
                'lat' => 33.3152, 'lng' => 44.3661,
            ])
            ->assertRedirect($this->host().'/courier');

        $shipment->refresh();

        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertSame(50_000, $shipment->collected_amount);
        $this->assertSame(1500, $shipment->courier_commission);
        $this->assertSame(50_000, (int) $this->ahmed->fresh()->cash_in_hand);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $event = $shipment->events()->where('to_status', 'delivered')->firstOrFail();

            $this->assertSame('courier', $event->actor_type);
            $this->assertEqualsWithDelta(33.3152, (float) $event->lat, 0.00001);
        });
    }

    public function test_a_courier_cannot_collect_more_than_is_owed(): void
    {
        $shipment = $this->assigned($this->ahmed, 50_000);

        $this->actingAs($this->ahmedUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, [
                'action' => 'delivered', 'collected_amount' => 90_000,
            ])
            ->assertSessionHasErrors('collected_amount');

        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);
    }

    public function test_a_failed_attempt_needs_a_reason(): void
    {
        $shipment = $this->assigned($this->ahmed);

        $this->actingAs($this->ahmedUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, ['action' => 'failed_attempt'])
            ->assertSessionHasErrors('failure_reason_id');
    }

    public function test_a_reason_requiring_a_note_refuses_an_empty_one(): void
    {
        $shipment = $this->assigned($this->ahmed);
        $reason = FailureReason::where('code', 'address_not_found')->firstOrFail();

        $this->actingAs($this->ahmedUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, [
                'action' => 'failed_attempt', 'failure_reason_id' => $reason->id,
            ])
            ->assertSessionHasErrors('note');
    }

    public function test_a_failed_attempt_is_recorded_and_counted(): void
    {
        $shipment = $this->assigned($this->ahmed);
        $reason = FailureReason::where('code', 'no_answer')->firstOrFail();

        $this->actingAs($this->ahmedUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, [
                'action' => 'failed_attempt', 'failure_reason_id' => $reason->id,
                'note' => 'اتصلت ثلاث مرات',
            ])
            ->assertSessionHas('success');

        $shipment->refresh();

        $this->assertSame(ShipmentStatus::FailedAttempt, $shipment->status);
        $this->assertSame(1, $shipment->attempts_count);
        $this->assertSame($reason->id, $shipment->last_failure_reason_id);
    }

    public function test_a_courier_cannot_act_on_a_shipment_no_longer_in_their_hands(): void
    {
        $shipment = $this->assigned($this->ahmed);

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($shipment, ShipmentStatus::Delivered, $this->staff));

        $this->actingAs($this->ahmedUser)
            ->post($this->host().'/courier/shipments/'.$shipment->id, ['action' => 'delivered'])
            ->assertSessionHasErrors('action');
    }

    public function test_a_courier_cannot_return_or_cancel_a_shipment(): void
    {
        $shipment = $this->assigned($this->ahmed);

        foreach (['returned', 'cancelled', 'lost'] as $forbidden) {
            $this->actingAs($this->ahmedUser)
                ->post($this->host().'/courier/shipments/'.$shipment->id, ['action' => $forbidden])
                ->assertSessionHasErrors('action');
        }

        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);
    }

    // ------------------------------------------------------ الاستلام

    public function test_a_pickup_moves_every_pending_shipment_of_that_merchant_at_once(): void
    {
        $picker = $this->makeCourier('C3', 'سجاد', '07720000003', 'pickup');
        $pickerUser = $this->courierUser($picker);

        // ثلاث شحنات معلّقة عند التاجر
        $pending = Tenancy::runFor($this->company, function () {
            $created = collect();

            foreach (range(1, 3) as $i) {
                $created->push(app(CreateShipment::class)->handle([
                    'merchant_id'     => $this->merchant->id,
                    'recipient_name'  => "زبون {$i}",
                    'recipient_phone' => '07801234567',
                    'governorate_id'  => $this->baghdad()->id,
                    'address'         => 'بغداد', 'landmark' => 'قرب الجامع',
                    'cod_amount'      => 30_000,
                ], $this->staff));
            }

            return $created;
        });

        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'courier_id' => $picker->id,
            'number' => 'PU000001', 'status' => 'assigned', 'expected_count' => 3,
        ]));

        $this->actingAs($pickerUser)
            ->post($this->host()."/courier/pickups/{$pickup->id}/complete", ['actual_count' => 3])
            ->assertSessionHas('success');

        $this->assertSame('completed', $pickup->fresh()->status);
        $this->assertSame(3, $pickup->fresh()->actual_count);

        foreach ($pending as $shipment) {
            $shipment->refresh();
            $this->assertSame(ShipmentStatus::PickedUp, $shipment->status);
            $this->assertSame($picker->id, $shipment->pickup_courier_id);
            $this->assertSame($pickup->id, $shipment->pickup_request_id);
        }
    }

    public function test_a_courier_cannot_close_another_couriers_pickup(): void
    {
        $picker = $this->makeCourier('C3', 'سجاد', '07720000003', 'pickup');

        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'courier_id' => $picker->id,
            'number' => 'PU000001', 'status' => 'assigned', 'expected_count' => 3,
        ]));

        $this->actingAs($this->ahmedUser)
            ->post($this->host()."/courier/pickups/{$pickup->id}/complete", ['actual_count' => 3])
            ->assertNotFound();
    }

    // ---------------------------------------------------- الإسناد من الشركة

    public function test_staff_assign_a_pending_pickup_to_a_pickup_courier(): void
    {
        $picker = $this->makeCourier('C3', 'سجاد', '07720000003', 'pickup');

        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'number' => 'PU000001',
            'status' => 'pending', 'expected_count' => 8,
        ]));

        $this->actingAs($this->staff)
            ->post($this->host()."/pickups/{$pickup->id}/assign", ['courier_id' => $picker->id])
            ->assertSessionHas('success');

        $pickup->refresh();

        $this->assertSame('assigned', $pickup->status);
        $this->assertSame($picker->id, $pickup->courier_id);
        $this->assertNotNull($pickup->assigned_at);
    }

    public function test_a_delivery_courier_cannot_be_assigned_a_pickup(): void
    {
        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'number' => 'PU000001',
            'status' => 'pending', 'expected_count' => 8,
        ]));

        $this->actingAs($this->staff)
            ->post($this->host()."/pickups/{$pickup->id}/assign", ['courier_id' => $this->ahmed->id])
            ->assertSessionHasErrors('courier_id');

        $this->assertSame('pending', $pickup->fresh()->status);
    }

    // -------------------------------------------------------- حسابه

    public function test_the_cash_screen_shows_what_the_courier_owes(): void
    {
        $shipment = $this->assigned($this->ahmed, 50_000);

        $this->actingAs($this->ahmedUser)->post($this->host().'/courier/shipments/'.$shipment->id, [
            'action' => 'delivered',
        ]);

        $this->actingAs($this->ahmedUser)
            ->get($this->host().'/courier/cash')
            ->assertOk()
            ->assertSee('50,000')      // نقد بيده
            ->assertSee('1,500')       // عمولته
            ->assertSee('48,500');     // الواجب تسليمه
    }
}

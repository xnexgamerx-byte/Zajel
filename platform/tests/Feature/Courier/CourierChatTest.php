<?php

namespace Tests\Feature\Courier;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierMessage;
use App\Models\CourierThread;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * محادثة الكول سنتر مع المندوب (docs/plan/38).
 */
class CourierChatTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $agent;

    private Courier $courier;

    private User $courierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->agent = $this->makeUser($this->company, UserRole::CustomerService);

        [$this->courier, $this->courierUser] = Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']);

            return [$courier, User::create([
                'name' => $courier->name, 'phone' => $courier->phone, 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
            ])];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipmentWithCourier(?Courier $courier = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 25_000,
            ], $this->agent);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->agent);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->agent, ['courier_id' => ($courier ?? $this->courier)->id]);

            return $shipment->refresh();
        });
    }

    public function test_the_courier_writes_about_a_shipment_and_the_call_centre_answers(): void
    {
        $shipment = $this->shipmentWithCourier();

        // من صفحة الشحنة في تطبيقه
        $this->actingAs($this->courierUser)->get($this->host()."/courier/shipments/{$shipment->id}")
            ->assertOk()->assertSee('راسل المكتب عن هذه الشحنة');

        $this->actingAs($this->courierUser)->post($this->host().'/courier/chat', [
            'body' => 'الزبون لا يردّ', 'shipment_id' => $shipment->id,
        ])->assertRedirect();

        $thread = Tenancy::runFor($this->company, fn () => CourierThread::sole());
        $this->assertTrue($thread->staff_unread);
        $this->assertSame($shipment->id, (int) Tenancy::runFor($this->company, fn () => CourierMessage::sole()->shipment_id));

        // الكول سنتر يرى المحادثة تنتظره، ويردّ
        $this->actingAs($this->agent)->get($this->host().'/courier-chat')
            ->assertOk()->assertSee('أحمد')->assertSee('ينتظر ردّنا');

        $this->actingAs($this->agent)->get($this->host().'/courier-chat?courier='.$this->courier->id)
            ->assertOk()->assertSee('الزبون لا يردّ')->assertSee($shipment->number);
        $this->assertFalse($thread->refresh()->staff_unread);

        $this->actingAs($this->agent)->post($this->host().'/courier-chat', [
            'courier_id' => $this->courier->id, 'body' => 'اتّصل بعد الخامسة، هو في البيت', 'shipment' => $shipment->number,
        ])->assertRedirect($this->host().'/courier-chat?courier='.$this->courier->id);

        $this->assertTrue($thread->refresh()->courier_unread);

        // المندوب يرى الردّ، وتُقرأ المحادثة
        $this->actingAs($this->courierUser)->get($this->host().'/courier/chat')
            ->assertOk()->assertSee('اتّصل بعد الخامسة، هو في البيت');
        $this->assertFalse($thread->refresh()->courier_unread);
    }

    public function test_a_courier_cannot_tag_someone_elses_shipment(): void
    {
        $other = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C2', 'name' => 'حسن', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active',
        ]));
        $theirs = $this->shipmentWithCourier($other);

        $this->actingAs($this->courierUser)->post($this->host().'/courier/chat', [
            'body' => 'سؤال', 'shipment_id' => $theirs->id,
        ])->assertRedirect();

        $this->assertNull(Tenancy::runFor($this->company, fn () => CourierMessage::sole()->shipment_id));
    }

    public function test_staff_without_the_conversations_permission_cannot_open_it(): void
    {
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($accountant)->get($this->host().'/courier-chat')->assertForbidden();
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->actingAs($this->agent)->post($this->host().'/courier-chat', [
            'courier_id' => $this->courier->id, 'body' => '   ',
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => CourierMessage::count()));
    }
}

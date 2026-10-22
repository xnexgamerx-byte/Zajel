<?php

namespace Tests\Feature\Support;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ShipmentTickets;
use App\Actions\Support\Claims;
use App\Actions\Support\Converse;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Models\WorkClaim;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «من فتح الطلب يعالجه» (docs/plan/73): تذكرة تغيير المبلغ، وشحنة المعالجة، ومحادثة التاجر —
 * من فتحتها حجزتها، وزميلتها ترى اسمها ولا تعالجها حتى تحسمها أو تفلتها أو تمضي ربع ساعةٍ بلا عمل.
 */
class ClaimsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $owner;

    private User $sara;

    private User $zainab;

    protected function setUp(): void
    {
        parent::setUp();

        // داخل ساعات المراسلة (docs/plan/39)
        $this->travelTo(now()->setTime(12, 0));
        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        Tenancy::runFor($this->company, function () {
            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
            ]);
            $this->sara = $this->agent('سارة', '07701110011');
            $this->zainab = $this->agent('زينب', '07701110012');
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function agent(string $name, string $phone): User
    {
        return User::create([
            'name' => $name, 'phone' => $phone, 'password' => 'password',
            'role' => UserRole::CustomerService, 'is_active' => true,
        ]);
    }

    private function withCourier(): Shipment
    {
        return Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'العنوان', 'cod_amount' => 50_000,
            ], $this->owner);
            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);

            return $shipment->refresh();
        });
    }

    private function ticket(): ShipmentTicket
    {
        $shipment = $this->withCourier();

        return Tenancy::runFor($this->company, fn () => app(ShipmentTickets::class)
            ->open($shipment, $this->courier, $this->owner, 'price', 45_000, 'الزبون يقول السعر 45'));
    }

    private function failed(): Shipment
    {
        $shipment = $this->withCourier();
        Tenancy::runFor($this->company, fn () => $shipment->forceFill([
            'status' => 'failed_attempt', 'status_changed_at' => now()->subHour(), 'attempts_count' => 1,
        ])->save());

        return $shipment;
    }

    public function test_a_ticket_opened_by_one_agent_cannot_be_decided_by_another(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->sara)->post($this->host()."/claims/ticket/{$ticket->id}")->assertSessionHasNoErrors();

        // زينب ترى اسم سارة ولا ترى أزرار القرار
        $this->actingAs($this->zainab)->get($this->host().'/tickets')->assertOk()
            ->assertSee('تعالجها سارة')->assertDontSee('name="approved_amount"', false);

        $this->actingAs($this->zainab)->post($this->host()."/tickets/{$ticket->id}/approve", ['approved_amount' => 45_000])
            ->assertSessionHasErrors('claim');
        $this->assertSame('open', Tenancy::runFor($this->company, fn () => $ticket->refresh()->status));

        // سارة تحسمها، والحجز يُرفع معها
        $this->actingAs($this->sara)->post($this->host()."/tickets/{$ticket->id}/approve", ['approved_amount' => 45_000])
            ->assertSessionHasNoErrors();
        Tenancy::runFor($this->company, function () use ($ticket) {
            $this->assertSame('approved', $ticket->refresh()->status);
            $this->assertSame($this->sara->id, (int) $ticket->handled_by_user_id);
            $this->assertSame(0, WorkClaim::count());
        });
    }

    public function test_a_claim_is_released_by_its_holder_or_after_fifteen_idle_minutes(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->sara)->post($this->host()."/claims/ticket/{$ticket->id}");

        // زينب لا تفلت حجز سارة
        $this->actingAs($this->zainab)->post($this->host()."/claims/ticket/{$ticket->id}/release");
        $this->actingAs($this->zainab)->post($this->host()."/claims/ticket/{$ticket->id}")->assertSessionHasErrors('claim');

        // ربع ساعةٍ بلا عمل: يسقط، وتأخذه زينب
        $this->travel(Claims::IDLE_MINUTES + 1)->minutes();
        $this->actingAs($this->zainab)->post($this->host()."/claims/ticket/{$ticket->id}")->assertSessionHasNoErrors();
        $this->actingAs($this->sara)->post($this->host()."/tickets/{$ticket->id}/reject", ['reply' => 'سلّم بالأصلي'])
            ->assertSessionHasErrors('claim');

        // «أفلتها»: تعود لأيّ زميلة
        $this->actingAs($this->zainab)->post($this->host()."/claims/ticket/{$ticket->id}/release");
        $this->actingAs($this->sara)->post($this->host()."/tickets/{$ticket->id}/reject", ['reply' => 'سلّم بالأصلي'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_processing_case_followed_by_one_agent_is_hers_alone(): void
    {
        $shipment = $this->failed();

        $this->actingAs($this->sara)->post($this->host()."/processing/{$shipment->id}/follow")->assertSessionHasNoErrors();

        $this->actingAs($this->zainab)->get($this->host().'/processing')->assertOk()->assertSee('تعالجها سارة');
        $this->actingAs($this->zainab)->post($this->host()."/processing/{$shipment->id}", ['action' => 'return'])
            ->assertSessionHasErrors('claim');
        $this->actingAs($this->zainab)->post($this->host()."/processing/{$shipment->id}/follow")->assertSessionHasErrors('claim');
        $this->assertSame(ShipmentStatus::FailedAttempt, Tenancy::runFor($this->company, fn () => $shipment->refresh()->status));

        $this->actingAs($this->sara)->post($this->host()."/processing/{$shipment->id}", ['action' => 'redeliver'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => WorkClaim::count()));
    }

    public function test_a_merchant_conversation_opened_by_one_agent_is_answered_by_her(): void
    {
        $conversation = Tenancy::runFor($this->company, fn () => app(Converse::class)
            ->start($this->merchant, 'شحنتي تأخرت', 'وين وصلت؟', $this->owner, Converse::MERCHANT));

        // سارة تفتحها فتصير لها
        $this->actingAs($this->sara)->get($this->host()."/conversations/{$conversation->id}")->assertOk()
            ->assertSee('تعالجها أنت');
        $this->actingAs($this->zainab)->get($this->host()."/conversations/{$conversation->id}")->assertOk()
            ->assertSee('تعالجها سارة')->assertDontSee('name="body"', false);
        $this->actingAs($this->zainab)->post($this->host()."/conversations/{$conversation->id}/reply", ['body' => 'أهلاً'])
            ->assertSessionHasErrors('claim');

        $this->actingAs($this->sara)->post($this->host()."/conversations/{$conversation->id}/reply", ['body' => 'وصلت المخزن'])
            ->assertSessionHasNoErrors();
    }
}

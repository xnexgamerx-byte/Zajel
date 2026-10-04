<?php

namespace Tests\Feature\Courier;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Support\Converse;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المبلغ بيد الكول سنتر لا المندوب (docs/plan/30): المندوب يسلّم بالمبلغ الأصلي، وإن
 * قال الزبون غيره فتح طلباً تحسمه موظّفة الكول سنتر المختصّة بمحافظة الشحنة وحدها.
 */
class AmountTicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $courierUser;

    private User $owner;

    private User $baghdadAgent;

    private User $basraAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () {
            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
            ]);
            $this->courierUser = User::create([
                'name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true,
            ]);
            $this->baghdadAgent = $this->agent('سارة', '07701110011', [$this->baghdad()->id]);
            $this->basraAgent = $this->agent('زينب', '07701110012', [$this->basra()->id]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function basra(): Governorate
    {
        return Governorate::where('code', 'BSR')->firstOrFail();
    }

    /** @param list<int> $governorates */
    private function agent(string $name, string $phone, array $governorates): User
    {
        $user = User::create([
            'name' => $name, 'phone' => $phone, 'password' => 'password',
            'role' => UserRole::CustomerService, 'is_active' => true,
        ]);
        $user->governorates()->sync($governorates);

        return $user;
    }

    private function withCourier(int $cod = 50_000, ?Governorate $to = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod, $to) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => ($to ?? $this->baghdad())->id,
                'address'         => 'العنوان',
                'cod_amount'      => $cod,
            ], $this->owner);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);

            return $shipment->refresh();
        });
    }

    private function act(Shipment $shipment, array $data)
    {
        return $this->actingAs($this->courierUser)->post($this->host().'/courier/shipments/'.$shipment->id, $data);
    }

    private function ask(Shipment $shipment, string $kind, int $amount, string $reason = 'الزبون قال هذا')
    {
        return $this->actingAs($this->courierUser)->post($this->host().'/courier/shipments/'.$shipment->id.'/ticket', [
            'kind' => $kind, 'requested_amount' => $amount, 'reason' => $reason,
        ]);
    }

    private function ticket(Shipment $shipment): ShipmentTicket
    {
        return Tenancy::runFor($this->company, fn () => ShipmentTicket::where('shipment_id', $shipment->id)->latest('id')->firstOrFail());
    }

    private function fresh(Shipment $shipment): Shipment
    {
        return Tenancy::runFor($this->company, fn () => $shipment->refresh());
    }

    // ---------------------------------------------------- المندوب لا يغيّر المبلغ

    public function test_the_courier_page_has_no_amount_box_and_no_free_partial_delivery(): void
    {
        $shipment = $this->withCourier();

        $this->actingAs($this->courierUser)->get($this->host().'/courier/shipments/'.$shipment->id)
            ->assertOk()
            ->assertDontSee('name="collected_amount"', false)
            ->assertDontSee('value="partially_delivered"', false)
            ->assertSee('تستلم من الزبون')
            ->assertSee('الزبون يريد يدفع مبلغاً آخر؟');
    }

    public function test_delivering_takes_the_shipments_amount_and_refuses_another(): void
    {
        $shipment = $this->withCourier(50_000);

        // صفحةٌ قديمة كُتب فيها مبلغٌ أقلّ: لا تُسلَّم به
        $this->act($shipment, ['action' => 'delivered', 'collected_amount' => 40_000])
            ->assertSessionHasErrors('collected_amount');
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        $this->act($shipment, ['action' => 'delivered'])->assertSessionHasNoErrors();

        $shipment = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertSame(50_000, (int) $shipment->collected_amount);
    }

    public function test_partial_delivery_needs_the_call_centres_approval(): void
    {
        $shipment = $this->withCourier();

        $this->act($shipment, ['action' => 'partially_delivered', 'collected_amount' => 20_000])
            ->assertSessionHasErrors('action');

        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);
    }

    // ---------------------------------------------------- الطلب

    public function test_the_courier_asks_and_the_call_centre_of_that_governorate_sees_it(): void
    {
        $shipment = $this->withCourier(50_000);

        $this->ask($shipment, 'price', 45_000, 'اتّفق مع التاجر على خصم')
            ->assertRedirect($this->host().'/courier/shipments/'.$shipment->id)
            ->assertSessionHas('success');

        $ticket = $this->ticket($shipment);
        $this->assertSame('open', $ticket->status);
        $this->assertSame($this->baghdad()->id, (int) $ticket->governorate_id);
        $this->assertSame(50_000, $ticket->current_amount);
        $this->assertSame(45_000, $ticket->requested_amount);
        $this->assertStringStartsWith('TK', $ticket->number);

        // في سجلّ الشحنة، باسم المندوب
        $event = Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $shipment->id)
            ->where('event_type', 'ticket')->firstOrFail());
        $this->assertSame('courier', $event->actor_type);
        $this->assertStringContainsString('اتّفق مع التاجر على خصم', $event->note);

        // المندوب ينتظر الجواب في الصفحة، وطلبٌ ثانٍ لا يُفتح فوقه
        $this->actingAs($this->courierUser)->get($this->host().'/courier/shipments/'.$shipment->id)
            ->assertSee('بانتظار الكول سنتر')->assertSee('data-ticket-poll', false)
            ->assertDontSee('الزبون يريد يدفع مبلغاً آخر؟');
        $this->actingAs($this->courierUser)->get($this->host().'/courier')->assertSee('المبلغ: بانتظار الكول سنتر');
        $this->ask($shipment, 'price', 40_000)->assertSessionHasErrors('requested_amount');
        $this->actingAs($this->courierUser)->getJson($this->host().'/courier/shipments/'.$shipment->id.'/ticket')
            ->assertExactJson(['status' => 'open']);

        // موظّفة بغداد تراه، وعلى الشريط عدّاده؛ وموظّفة البصرة لا
        $this->actingAs($this->baghdadAgent)->get($this->host().'/tickets')
            ->assertOk()->assertSee($ticket->number)->assertSee('اتّفق مع التاجر على خصم');
        $this->actingAs($this->basraAgent)->get($this->host().'/tickets')
            ->assertOk()->assertDontSee($ticket->number)->assertSee('لا طلب ينتظر جوابك');
        $this->actingAs($this->basraAgent)->post($this->host().'/tickets/'.$ticket->id.'/approve', ['approved_amount' => 45_000])
            ->assertNotFound();

        // ومن لا محافظات له يرى كلّها
        $this->actingAs($this->owner)->get($this->host().'/tickets')->assertSee($ticket->number);
    }

    public function test_the_amount_and_the_reason_are_checked(): void
    {
        $shipment = $this->withCourier(50_000);

        $this->ask($shipment, 'price', 50_000)->assertSessionHasErrors(['requested_amount' => 'هذا المبلغ نفسه — لا تغيير فيه.']);
        $this->ask($shipment, 'partial', 60_000)->assertSessionHasErrors('requested_amount');
        $this->actingAs($this->courierUser)->post($this->host().'/courier/shipments/'.$shipment->id.'/ticket', [
            'kind' => 'price', 'requested_amount' => 40_000, 'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => ShipmentTicket::count()));
    }

    public function test_approving_a_new_price_changes_the_shipment_and_the_courier_delivers_with_it(): void
    {
        $shipment = $this->withCourier(50_000);
        $dueBefore = (int) $shipment->merchant_due;
        $this->ask($shipment, 'price', 45_000);
        $ticket = $this->ticket($shipment);

        $this->actingAs($this->baghdadAgent)
            ->post($this->host().'/tickets/'.$ticket->id.'/approve', ['approved_amount' => 45_000, 'reply' => 'التاجر وافق'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $shipment = $this->fresh($shipment);
        $this->assertSame(45_000, (int) $shipment->cod_amount);
        $this->assertSame($dueBefore - 5_000, (int) $shipment->merchant_due);

        $ticket = $this->ticket($shipment);
        $this->assertSame('approved', $ticket->status);
        $this->assertSame($this->baghdadAgent->id, (int) $ticket->handled_by_user_id);

        // في السجلّ: التعديل باسم الموظّفة ورقم الطلب
        $edited = Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $shipment->id)
            ->where('event_type', 'edited')->firstOrFail());
        $this->assertSame('سارة', $edited->actor_name);
        $this->assertStringContainsString($ticket->number, $edited->note);
        $this->assertStringContainsString('50,000 ← 45,000', $edited->note);

        // المندوب يرى الجواب، ويسلّم «واصل» بالمبلغ الجديد
        $this->actingAs($this->courierUser)->get($this->host().'/courier/shipments/'.$shipment->id)
            ->assertSee('اعتمد الكول سنتر المبلغ الجديد')->assertSee('45,000');
        $this->act($shipment, ['action' => 'delivered'])->assertSessionHasNoErrors();

        $shipment = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertSame(45_000, (int) $shipment->collected_amount);
    }

    public function test_an_approved_partial_opens_partial_delivery_at_that_amount_only(): void
    {
        $shipment = $this->withCourier(50_000);
        $this->ask($shipment, 'partial', 30_000, 'أخذ قطعتين من ثلاث');
        $ticket = $this->ticket($shipment);

        // الموظّفة تعتمد مبلغاً غير المطلوب بعد أن اتّصلت
        $this->actingAs($this->baghdadAgent)
            ->post($this->host().'/tickets/'.$ticket->id.'/approve', ['approved_amount' => 32_000])
            ->assertSessionHasNoErrors();

        $this->assertSame(50_000, (int) $this->fresh($shipment)->cod_amount);

        $this->actingAs($this->courierUser)->get($this->host().'/courier/shipments/'.$shipment->id)
            ->assertSee('value="partially_delivered"', false)->assertSee('32,000');

        $this->act($shipment, ['action' => 'partially_delivered'])->assertSessionHasNoErrors();

        $shipment = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::PartiallyDelivered, $shipment->status);
        $this->assertSame(32_000, (int) $shipment->collected_amount);
        $this->assertNotNull($this->ticket($shipment)->used_at);
        $this->assertSame('approved', $this->ticket($shipment)->status);
    }

    public function test_rejecting_needs_a_reason_and_the_courier_reads_it(): void
    {
        $shipment = $this->withCourier(50_000);
        $this->ask($shipment, 'price', 30_000);
        $ticket = $this->ticket($shipment);

        $this->actingAs($this->baghdadAgent)->post($this->host().'/tickets/'.$ticket->id.'/reject', ['reply' => ''])
            ->assertSessionHasErrors('reply');

        $this->actingAs($this->baghdadAgent)
            ->post($this->host().'/tickets/'.$ticket->id.'/reject', ['reply' => 'التاجر لا يقبل، السعر كما هو'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $this->ticket($shipment)->status);
        $this->assertSame(50_000, (int) $this->fresh($shipment)->cod_amount);

        $this->actingAs($this->courierUser)->get($this->host().'/courier/shipments/'.$shipment->id)
            ->assertSee('رُفض طلبك')->assertSee('التاجر لا يقبل، السعر كما هو')
            ->assertSee('الزبون يريد يدفع مبلغاً آخر؟');

        // ولا يُحسم مرّتين
        $this->actingAs($this->baghdadAgent)
            ->post($this->host().'/tickets/'.$ticket->id.'/approve', ['approved_amount' => 30_000])
            ->assertSessionHasErrors('ticket');
    }

    public function test_an_open_request_closes_when_the_shipment_leaves_the_courier(): void
    {
        $paid = $this->withCourier(50_000);
        $this->ask($paid, 'price', 40_000);

        // الزبون دفع كاملاً قبل الجواب
        $this->act($paid, ['action' => 'delivered'])->assertSessionHasNoErrors();
        $ticket = $this->ticket($paid);
        $this->assertSame('closed', $ticket->status);
        $this->assertSame('سُلِّمت بالمبلغ الأصلي قبل الجواب.', $ticket->closed_note);

        // وتذكرةٌ أُغلقت لا تُعتمد
        $this->actingAs($this->baghdadAgent)
            ->post($this->host().'/tickets/'.$ticket->id.'/approve', ['approved_amount' => 40_000])
            ->assertSessionHasErrors('ticket');
        $this->assertSame(50_000, (int) $this->fresh($paid)->cod_amount);

        // والواصل الجزئي المعتمد يسقط إن لم يُسلَّم به
        $refused = $this->withCourier(50_000);
        $this->ask($refused, 'partial', 20_000);
        $this->actingAs($this->baghdadAgent)
            ->post($this->host().'/tickets/'.$this->ticket($refused)->id.'/approve', ['approved_amount' => 20_000]);

        $reason = Tenancy::runFor($this->company, fn () => FailureReason::availableFor($this->company->id)->where('requires_note', false)->firstOrFail());
        $this->act($refused, ['action' => 'failed_attempt', 'failure_reason_id' => $reason->id])->assertSessionHasNoErrors();

        $this->assertSame('closed', $this->ticket($refused)->status);
        $this->assertNull($this->ticket($refused)->used_at);
    }

    public function test_tickets_need_their_permission(): void
    {
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($accountant)->get($this->host().'/tickets')->assertForbidden();

        // والكول سنتر يملكها افتراضاً، مع تغيير الحالة وتصحيح الشحنة — وكلّها تُنزع من المراتب
        foreach ([Ability::TICKETS_HANDLE, Ability::SHIPMENTS_STATUS, Ability::SHIPMENTS_EDIT] as $ability) {
            $this->assertContains($ability, Ability::defaultsFor(UserRole::CustomerService));
        }
    }

    // ---------------------------------------------------- محافظات الاختصاص

    public function test_the_owner_sets_an_agents_governorates_from_the_user_form(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/users', [
            'name' => 'نور', 'phone' => '07701110099', 'role' => UserRole::CustomerService->value,
            'password' => 'secret12', 'is_active' => '1',
            'governorates' => [$this->baghdad()->id, $this->basra()->id],
        ])->assertSessionHasNoErrors();

        $noor = Tenancy::runFor($this->company, fn () => User::where('phone', '07701110099')->firstOrFail());
        $this->assertEqualsCanonicalizing([$this->baghdad()->id, $this->basra()->id], $noor->handledGovernorateIds());

        $this->actingAs($this->owner)->get($this->host().'/users/'.$noor->id.'/edit')
            ->assertOk()->assertSee('المحافظات المختصّة بها');
        $this->actingAs($this->owner)->get($this->host().'/users')->assertSee('بغداد، البصرة', false);

        // تُرفع عنها المحافظات: ترى كلّها
        $this->actingAs($this->owner)->put($this->host().'/users/'.$noor->id, [
            'name' => 'نور', 'phone' => '07701110099', 'role' => UserRole::CustomerService->value, 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame([], Tenancy::runFor($this->company, fn () => User::findOrFail($noor->id)->handledGovernorateIds()));
    }

    public function test_processing_reaches_only_the_agent_of_that_governorate(): void
    {
        $reason = Tenancy::runFor($this->company, fn () => FailureReason::availableFor($this->company->id)->where('requires_note', false)->firstOrFail());
        $baghdad = $this->withCourier(50_000);
        $basra = $this->withCourier(60_000, $this->basra());

        foreach ([$baghdad, $basra] as $shipment) {
            $this->act($shipment, ['action' => 'failed_attempt', 'failure_reason_id' => $reason->id]);
        }

        // رسالة المندوب الأخيرة («سُجِّلت الشحنة …») لا تُحسب على الصفحة
        $this->flushSession();
        $this->actingAs($this->baghdadAgent)->get($this->host().'/processing')
            ->assertOk()->assertSee($baghdad->number)->assertDontSee($basra->number)->assertSee('تظهر لك شحنات محافظاتك');
        $this->actingAs($this->basraAgent)->get($this->host().'/processing')
            ->assertOk()->assertSee($basra->number)->assertDontSee($baghdad->number);

        // معالجة البصرة لا تُفتح من موظّفة بغداد برقمها
        $this->actingAs($this->baghdadAgent)->post($this->host().'/processing/'.$basra->id, ['action' => 'return'])
            ->assertNotFound();
        $this->actingAs($this->basraAgent)->post($this->host().'/processing/'.$basra->id, ['action' => 'return'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::Returning, $this->fresh($basra)->status);
    }

    public function test_a_merchants_message_about_a_shipment_reaches_that_governorates_agent(): void
    {
        $basraShipment = $this->withCourier(60_000, $this->basra());

        [$aboutBasra, $general] = Tenancy::runFor($this->company, function () use ($basraShipment) {
            $merchantUser = User::create([
                'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
            ]);
            $converse = app(Converse::class);

            return [
                $converse->start($this->merchant, 'وين شحنتي بالبصرة', 'متى توصل؟', $merchantUser, Converse::MERCHANT, $basraShipment->number),
                $converse->start($this->merchant, 'متى الحساب', 'متى تدفعون؟', $merchantUser, Converse::MERCHANT),
            ];
        });

        $this->assertSame($basraShipment->id, (int) $aboutBasra->shipment_id);

        // موظّفة بغداد: العامّة وحدها — رسالة البصرة لا تصلها، ولا تُفتح برقمها
        $this->actingAs($this->baghdadAgent)->get($this->host().'/conversations?show=all')
            ->assertOk()->assertSee('متى الحساب')->assertDontSee('وين شحنتي بالبصرة');
        $this->actingAs($this->baghdadAgent)->get($this->host().'/conversations/'.$aboutBasra->id)->assertNotFound();

        $this->actingAs($this->basraAgent)->get($this->host().'/conversations?show=all')
            ->assertOk()->assertSee('متى الحساب')->assertSee('وين شحنتي بالبصرة');

        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Conversation::visibleTo($this->baghdadAgent)->count()));
        $this->assertSame(2, Tenancy::runFor($this->company, fn () => Conversation::visibleTo($this->owner)->count()));
    }

    public function test_the_ticket_shows_on_the_staff_shipment_page_and_the_home_alerts(): void
    {
        $shipment = $this->withCourier(50_000);
        $this->ask($shipment, 'price', 45_000);
        $ticket = $this->ticket($shipment);

        $this->actingAs($this->baghdadAgent)->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()->assertSee('طلبات تغيير المبلغ')->assertSee($ticket->number);
        $this->actingAs($this->baghdadAgent)->get($this->host().'/')
            ->assertOk()->assertSee('طلبات المناديب لتغيير المبلغ')->assertSee($ticket->number);
    }
}

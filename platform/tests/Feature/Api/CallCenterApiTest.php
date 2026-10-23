<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ShipmentTickets;
use App\Actions\Support\Converse;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تطبيق الكول سنتر (docs/plan/73): يدخله من يعالج التذاكر أو الشحنات أو المحادثات، ويعمل بشاشات
 * المتابعة في الموقع نفسها — ومن فتحت الطلب تعالجه وحدها.
 */
class CallCenterApiTest extends TestCase
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
            $this->sara = $this->staff('سارة', '07701110011');
            $this->zainab = $this->staff('زينب', '07701110012');
        });
    }

    private function staff(string $name, string $phone, UserRole $role = UserRole::CustomerService): User
    {
        return User::create(['name' => $name, 'phone' => $phone, 'password' => 'password', 'role' => $role, 'is_active' => true]);
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function as(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson($this->api('/login'), ['username' => $user->username, 'password' => 'password', 'app' => 'callcenter'])
            ->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function withCourier(string $name = 'علي حسين'): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($name) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
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
        $shipment = $this->withCourier('منى جاسم');
        Tenancy::runFor($this->company, fn () => $shipment->forceFill([
            'status' => 'failed_attempt', 'status_changed_at' => now()->subHours(3), 'attempts_count' => 1,
        ])->save());

        return $shipment->refresh();
    }

    public function test_only_staff_who_handle_call_centre_work_enter_the_app(): void
    {
        $this->postJson($this->api('/login'), ['username' => $this->sara->username, 'password' => 'password', 'app' => 'callcenter'])
            ->assertOk()->assertJsonPath('user.role', 'customer_service');

        // المحاسب لا يعالج تذكرةً ولا شحنةً ولا محادثة
        $accountant = Tenancy::runFor($this->company, fn () => $this->staff('محاسب', '07701110013', UserRole::Accountant));
        $this->postJson($this->api('/login'), ['username' => $accountant->username, 'password' => 'password', 'app' => 'callcenter'])
            ->assertUnprocessable()->assertJsonPath('errors.username.0', fn ($m) => str_contains($m, 'لا يعالج'));

        // ورمز الكول سنتر لا يفتح واجهة التاجر
        $this->getJson($this->api('/merchant/home'), $this->as($this->sara))->assertForbidden();
    }

    public function test_home_shows_the_queues_by_urgency_and_what_she_holds(): void
    {
        $ticket = $this->ticket();
        $this->failed();
        Tenancy::runFor($this->company, fn () => app(Converse::class)
            ->start($this->merchant, 'شحنتي تأخرت', 'وين وصلت؟', $this->owner, Converse::MERCHANT));
        $headers = $this->as($this->sara);

        $this->getJson($this->api('/callcenter/home'), $headers)->assertOk()
            ->assertJsonPath('name', 'سارة')
            ->assertJsonPath('queues.tickets.count', 1)
            ->assertJsonPath('queues.processing.count', 1)->assertJsonPath('queues.processing.free', 1)
            ->assertJsonPath('queues.conversations.count', 1)
            ->assertJsonPath('mine', []);

        $this->postJson($this->api("/callcenter/tickets/{$ticket->id}/claim"), [], $headers)->assertOk();
        $this->getJson($this->api('/callcenter/home'), $headers)
            ->assertJsonPath('mine.0.subject', 'ticket')->assertJsonPath('mine.0.title', 'تذكرة '.$ticket->number);
    }

    public function test_a_ticket_claimed_by_one_agent_is_decided_by_her_alone(): void
    {
        $ticket = $this->ticket();
        $sara = $this->as($this->sara);

        $this->getJson($this->api('/callcenter/tickets'), $sara)->assertOk()
            ->assertJsonPath('data.0.number', $ticket->number)->assertJsonPath('data.0.requested', 45_000)
            ->assertJsonPath('data.0.claim', null);
        $this->postJson($this->api("/callcenter/tickets/{$ticket->id}/claim"), [], $sara)->assertOk()
            ->assertJsonPath('data.claim.mine', true);

        $zainab = $this->as($this->zainab);
        $this->getJson($this->api('/callcenter/tickets'), $zainab)
            ->assertJsonPath('data.0.claim.by', 'سارة')->assertJsonPath('data.0.claim.mine', false);
        $this->postJson($this->api("/callcenter/tickets/{$ticket->id}/claim"), [], $zainab)
            ->assertUnprocessable()->assertJsonValidationErrors('claim');
        $this->postJson($this->api("/callcenter/tickets/{$ticket->id}/approve"), ['approved_amount' => 45_000], $zainab)
            ->assertUnprocessable()->assertJsonValidationErrors('claim');

        $sara = $this->as($this->sara);
        $this->postJson($this->api("/callcenter/tickets/{$ticket->id}/approve"), ['approved_amount' => 45_000, 'reply' => 'اتصلت بالتاجر'], $sara)
            ->assertOk();
        $this->assertSame('approved', Tenancy::runFor($this->company, fn () => $ticket->refresh()->status));
        $this->getJson($this->api('/callcenter/tickets'), $sara)->assertJsonCount(0, 'data');
    }

    public function test_a_processing_case_is_claimed_messaged_and_decided_by_one_agent(): void
    {
        $shipment = $this->failed();
        $sara = $this->as($this->sara);

        $this->getJson($this->api('/callcenter/processing'), $sara)->assertOk()
            ->assertJsonPath('data.0.number', $shipment->number)->assertJsonPath('data.0.waiting_minutes', 180);
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/claim"), ['note' => 'يرد بعد الخامسة'], $sara)->assertOk()
            ->assertJsonPath('data.claim.mine', true)->assertJsonPath('data.my_note', 'يرد بعد الخامسة');

        // تكتب للتاجر وللمندوب من الشحنة نفسها
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/merchant"), ['body' => 'الزبون يريد الغد'], $sara)->assertCreated();
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/courier"), ['body' => 'اتصل به قبل الوصول'], $sara)->assertCreated();
        $this->getJson($this->api("/callcenter/processing/{$shipment->id}"), $sara)
            ->assertJsonPath('data.merchant_chat.0.body', 'الزبون يريد الغد')
            ->assertJsonPath('data.courier_chat.0.body', 'اتصل به قبل الوصول');

        $zainab = $this->as($this->zainab);
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/decide"), ['action' => 'return'], $zainab)
            ->assertUnprocessable()->assertJsonValidationErrors('claim');
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/merchant"), ['body' => 'أهلاً'], $zainab)
            ->assertUnprocessable()->assertJsonValidationErrors('claim');

        $sara = $this->as($this->sara);
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/decide"), ['action' => 'postpone'], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('until');
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/decide"),
            ['action' => 'postpone', 'until' => now()->addDay()->toDateString(), 'note' => 'الغد'], $sara)->assertOk();
        $this->assertSame(ShipmentStatus::Postponed, Tenancy::runFor($this->company, fn () => $shipment->refresh()->status));
    }

    public function test_a_conversation_opened_by_one_agent_is_answered_by_her(): void
    {
        $conversation = Tenancy::runFor($this->company, fn () => app(Converse::class)
            ->start($this->merchant, 'شحنتي تأخرت', 'وين وصلت؟', $this->owner, Converse::MERCHANT));
        $sara = $this->as($this->sara);

        $this->getJson($this->api('/callcenter/conversations'), $sara)->assertOk()->assertJsonPath('data.0.waiting', true);
        $this->getJson($this->api("/callcenter/conversations/{$conversation->id}"), $sara)->assertOk()
            ->assertJsonPath('data.claim.mine', true)->assertJsonPath('data.messages.0.body', 'وين وصلت؟');

        $zainab = $this->as($this->zainab);
        $this->getJson($this->api("/callcenter/conversations/{$conversation->id}"), $zainab)->assertJsonPath('data.claim.by', 'سارة');
        $this->postJson($this->api("/callcenter/conversations/{$conversation->id}/reply"), ['body' => 'أهلاً'], $zainab)
            ->assertUnprocessable()->assertJsonValidationErrors('claim');

        $sara = $this->as($this->sara);
        $this->postJson($this->api("/callcenter/conversations/{$conversation->id}/reply"), ['body' => 'وصلت المخزن'], $sara)->assertCreated();
        $this->postJson($this->api("/callcenter/conversations/{$conversation->id}/close"), [], $sara)->assertOk();
        $this->assertSame('closed', Tenancy::runFor($this->company, fn () => $conversation->refresh()->status));
    }

    /** موظّفةٌ لمحافظةٍ أخرى لا تفتح برقمه ما ليس لها: تذكرةً ولا شحنةً للمعالجة ولا محادثتها */
    public function test_an_agent_reaches_only_her_governorates_by_id(): void
    {
        $ticket = $this->ticket();
        $failed = $this->failed();
        $conversation = Tenancy::runFor($this->company, fn () => app(Converse::class)->start(
            $this->merchant, 'بخصوص الوصل', 'وين؟', $this->owner, Converse::MERCHANT, $failed->number,
        ));
        Tenancy::runFor($this->company, fn () => $this->zainab->governorates()->attach(
            Governorate::query()->whereKeyNot($this->baghdad()->id)->value('id'),
        ));
        $zainab = $this->as($this->zainab);

        foreach ([
            ['get', "/callcenter/tickets/{$ticket->id}"],
            ['post', "/callcenter/tickets/{$ticket->id}/claim"],
            ['post', "/callcenter/tickets/{$ticket->id}/approve"],
            ['get', "/callcenter/processing/{$failed->id}"],
            ['post', "/callcenter/processing/{$failed->id}/claim"],
            ['post', "/callcenter/processing/{$failed->id}/decide"],
            ['post', "/callcenter/processing/{$failed->id}/merchant"],
            ['get', "/callcenter/conversations/{$conversation->id}"],
            ['post', "/callcenter/conversations/{$conversation->id}/reply"],
        ] as [$method, $path]) {
            ($method === 'get'
                ? $this->getJson($this->api($path), $zainab)
                : $this->postJson($this->api($path), ['body' => 'x', 'approved_amount' => 45_000, 'action' => 'redeliver'], $zainab))
                ->assertNotFound();
        }
        $this->getJson($this->api('/callcenter/tickets'), $zainab)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_merchants_photo_shows_in_the_conversation(): void
    {
        Storage::fake('local');
        $conversation = Tenancy::runFor($this->company, fn () => app(Converse::class)->start(
            $this->merchant, 'بخصوص الوصل', 'هذا الوصل', $this->owner, Converse::MERCHANT, null,
            UploadedFile::fake()->image('waybill.jpg', 600, 800),
        ));
        $sara = $this->as($this->sara);

        $file = $this->getJson($this->api("/callcenter/conversations/{$conversation->id}"), $sara)->assertOk()
            ->assertJsonPath('data.messages.0.file.image', true)
            ->assertJsonPath('data.messages.0.file.name', 'waybill.jpg')
            ->json('data.messages.0.file.url');
        $this->get($file, $sara)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_shipment_is_found_by_its_number_or_phone_with_its_history(): void
    {
        $shipment = $this->withCourier();
        $sara = $this->as($this->sara);

        $this->getJson($this->api('/callcenter/shipments?q='.$shipment->number), $sara)->assertOk()
            ->assertJsonPath('data.0.id', $shipment->id)->assertJsonPath('data.0.status', 'out_for_delivery');
        $this->getJson($this->api('/callcenter/shipments?q=07801234567'), $sara)->assertJsonPath('data.0.id', $shipment->id);
        $this->getJson($this->api('/callcenter/shipments/'.$shipment->id), $sara)->assertOk()
            ->assertJsonPath('data.courier.name', 'أحمد')->assertJsonPath('data.processing', false)
            ->assertJsonPath('data.events.0.status', 'قيد التوصيل');
    }
}

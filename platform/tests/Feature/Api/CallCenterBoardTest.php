<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ShipmentTickets;
use App\Actions\Support\CourierChat;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تطبيق الكول سنتر (docs/plan/80): خانات «حالة الشحنات»، و«أنجزتِ اليوم» بتفاصيله، وتحديث الحالة من
 * التطبيق، وإعادة الراجع المؤكَّد للتوصيل، وتوضيح سبب المحاولة.
 */
class CallCenterBoardTest extends TestCase
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

    private function basraGov(): Governorate
    {
        return Governorate::where('name_ar', 'البصرة')->firstOrFail();
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

    private function decide(Shipment $shipment, array $headers, string $action, array $extra = []): void
    {
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/claim"), [], $headers)->assertOk();
        $this->postJson($this->api("/callcenter/processing/{$shipment->id}/decide"), ['action' => $action] + $extra, $headers)->assertOk();
    }

    public function test_the_board_sorts_shipments_into_their_places_and_today_is_detailed(): void
    {
        $out = $this->withCourier('على الطريق');
        $toRedeliver = $this->failed();
        $toPostpone = $this->failed();
        $toReturn = $this->failed();
        $sara = $this->as($this->sara);

        $this->decide($toRedeliver, $sara, 'redeliver', ['note' => 'يستلم العصر']);
        $this->decide($toPostpone, $sara, 'postpone', ['until' => now()->addDay()->toDateString()]);
        $this->decide($toReturn, $sara, 'return', ['note' => 'رفض نهائياً']);

        $counts = collect($this->getJson($this->api('/callcenter/board'), $sara)->assertOk()->json('data'))->pluck('count', 'kind');
        $this->assertSame(['in_delivery' => 1, 'postponed' => 1, 'redelivery' => 1, 'returns' => 1], $counts->all());

        $this->getJson($this->api('/callcenter/board/in_delivery'), $sara)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $out->number);
        $this->getJson($this->api('/callcenter/board/postponed'), $sara)->assertOk()
            ->assertJsonPath('data.0.number', $toPostpone->number)->assertJsonPath('data.0.until', fn ($v) => $v !== null);
        $this->getJson($this->api('/callcenter/board/redelivery'), $sara)->assertOk()
            ->assertJsonPath('data.0.number', $toRedeliver->number)->assertJsonPath('data.0.status_label', 'إعادة توصيل');
        $this->getJson($this->api('/callcenter/board/returns'), $sara)->assertOk()
            ->assertJsonPath('data.0.number', $toReturn->number)->assertJsonPath('data.0.with_courier', true);
        $this->getJson($this->api('/callcenter/board/nothing'), $sara)->assertNotFound();

        // «أنجزتِ اليوم»: بأنواعه، ويُفتح بتفاصيله
        $this->getJson($this->api('/callcenter/home'), $sara)->assertOk()
            ->assertJsonPath('today.processed', 3)
            ->assertJsonPath('today.actions.redeliver', 1)->assertJsonPath('today.actions.postpone', 1)
            ->assertJsonPath('today.actions.return', 1);
        $done = collect($this->getJson($this->api('/callcenter/done?kind=processed'), $sara)->assertOk()->json('data'));
        $this->assertSame(['راجع مؤكد', 'مؤجل', 'إعادة توصيل'], $done->pluck('label')->all());
        $this->assertSame('يستلم العصر', $done->firstWhere('action', 'redeliver')['note']);
        $this->getJson($this->api('/callcenter/done?kind=tickets'), $sara)->assertOk()->assertJsonCount(0, 'data');

        // زميلتها لم تعالج شيئاً اليوم
        $this->getJson($this->api('/callcenter/done?kind=processed'), $this->as($this->zainab))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_confirmed_return_still_with_its_courier_goes_back_out_with_a_reason(): void
    {
        $shipment = $this->failed();
        $sara = $this->as($this->sara);
        $this->decide($shipment, $sara, 'return');

        $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->assertOk()
            ->assertJsonPath('data.status_label', 'راجع مؤكد')->assertJsonPath('data.can_reopen', true);

        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/reopen"), [], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/reopen"), ['note' => 'اقتنع الزبون بالاستلام'], $sara)
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'مع مندوبها'));

        $shipment = Tenancy::runFor($this->company, fn () => $shipment->refresh());
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->status);
        $this->assertSame($this->courier->id, (int) $shipment->delivery_courier_id);
        $this->assertNotNull($shipment->redelivery_at);
        $this->assertNull($shipment->return_confirmed_at);

        // في «إعادة توصيل» عند المندوب، ومعدودةٌ في عملها اليوم
        $this->getJson($this->api('/callcenter/board/redelivery'), $sara)->assertJsonPath('data.0.number', $shipment->number);
        $this->getJson($this->api('/callcenter/home'), $sara)->assertJsonPath('today.actions.redeliver', 1);

        // لا تُعاد مرّتين، ولا شحنةٌ ليست راجعاً مؤكَّداً
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/reopen"), ['note' => 'ثانيةً'], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    public function test_a_confirmed_return_already_received_goes_back_to_the_warehouse(): void
    {
        $shipment = $this->failed();
        $sara = $this->as($this->sara);
        $this->decide($shipment, $sara, 'return');
        Tenancy::runFor($this->company, fn () => $shipment->refresh()->forceFill(['return_received_at' => now()])->save());

        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/reopen"), ['note' => 'التاجر اتّفق معه'], $sara)
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'للمخزن'));
        $shipment = Tenancy::runFor($this->company, fn () => $shipment->refresh());
        $this->assertSame(ShipmentStatus::AtHub, $shipment->status);
        $this->assertNull($shipment->return_received_at);
    }

    public function test_the_agent_updates_a_shipment_from_the_app_with_the_sites_rules(): void
    {
        $shipment = $this->withCourier();
        $sara = $this->as($this->sara);
        $reason = Tenancy::runFor($this->company, fn () => FailureReason::availableFor($this->company->id)->where('requires_note', true)->firstOrFail());

        $detail = $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->assertOk();
        $values = collect($detail->json('data.transitions'))->pluck('value');
        $this->assertTrue($values->contains('failed_attempt'));
        $this->assertTrue($values->contains('partially_delivered'));
        $this->assertFalse($values->contains('lost'));
        $this->assertNotEmpty($detail->json('data.reasons'));

        // بلا توضيح لسببٍ يحتاجه: يُرفض كما في الموقع
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/status"),
            ['status' => 'failed_attempt', 'failure_reason_id' => $reason->id], $sara)
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/status"),
            ['status' => 'failed_attempt', 'failure_reason_id' => $reason->id, 'note' => 'العنوان الجديد: الكرادة قرب الجسر'], $sara)
            ->assertOk();

        // والتوضيح يظهر مع السبب في الشحنة
        $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->assertOk()
            ->assertJsonPath('data.status', 'failed_attempt')
            ->assertJsonPath('data.reason', $reason->name_ar)
            ->assertJsonPath('data.reason_note', 'العنوان الجديد: الكرادة قرب الجسر')
            ->assertJsonPath('data.processing', true);

        // الإجبار والراجع للتاجر من الموقع وحده
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/status"),
            ['status' => 'forced_delivered', 'collected_amount' => 50_000, 'force_delivered_reason' => 'x'], $sara)->assertStatus(422);
        $this->assertSame(ShipmentStatus::FailedAttempt, Tenancy::runFor($this->company, fn () => $shipment->refresh()->status));
    }

    public function test_the_history_names_edits_and_tickets_not_just_statuses(): void
    {
        $shipment = $this->withCourier();
        Tenancy::runFor($this->company, fn () => app(ShipmentTickets::class)
            ->open($shipment, $this->courier, $this->owner, 'price', 45_000, 'الزبون يقول السعر 45'));

        $events = collect($this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $this->as($this->sara))
            ->assertOk()->json('data.events'));
        $this->assertSame('طلب تغيير المبلغ', $events->first()['status']);
        $this->assertStringContainsString('الزبون يقول السعر 45', $events->first()['note']);
    }

    public function test_any_shipment_has_its_merchant_and_courier_chats(): void
    {
        $shipment = $this->withCourier('على الطريق');
        $sara = $this->as($this->sara);

        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/merchant"), ['body' => 'الزبون يسأل متى توصل'], $sara)->assertCreated();
        $this->postJson($this->api("/callcenter/shipments/{$shipment->id}/courier"), ['body' => 'اتصل بالزبون قبل العصر'], $sara)->assertCreated();

        $this->getJson($this->api("/callcenter/shipments/{$shipment->id}"), $sara)->assertOk()
            ->assertJsonPath('data.can_chat', true)
            ->assertJsonPath('data.merchant_chat.0.body', 'الزبون يسأل متى توصل')
            ->assertJsonPath('data.courier_chat.0.body', 'اتصل بالزبون قبل العصر')
            ->assertJsonPath('data.courier_chat.0.mine', true);
    }

    public function test_courier_messages_are_read_and_answered_in_the_app(): void
    {
        $shipment = $this->withCourier();
        Tenancy::runFor($this->company, function () use ($shipment) {
            $courierUser = User::create(['name' => 'أحمد', 'phone' => '07720000009', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true]);
            app(CourierChat::class)->send($this->courier, 'الزبون ما يرد، شنو أسوي؟', $courierUser, CourierChat::COURIER, $shipment);
        });
        $sara = $this->as($this->sara);

        $this->getJson($this->api('/callcenter/couriers'), $sara)->assertOk()
            ->assertJsonPath('data.0.name', 'أحمد')->assertJsonPath('data.0.unread', true)
            ->assertJsonPath('data.0.waiting', true)->assertJsonPath('data.0.last', 'الزبون ما يرد، شنو أسوي؟');

        $this->getJson($this->api("/callcenter/couriers/{$this->courier->id}"), $sara)->assertOk()
            ->assertJsonPath('data.messages.0.shipment', $shipment->number)->assertJsonPath('data.messages.0.mine', false);
        $this->getJson($this->api('/callcenter/couriers'), $sara)->assertJsonPath('data.0.unread', false);

        $this->postJson($this->api("/callcenter/couriers/{$this->courier->id}/reply"), ['body' => 'أرسل له واتساب', 'shipment' => $shipment->number], $sara)
            ->assertCreated();
        $this->postJson($this->api("/callcenter/couriers/{$this->courier->id}/reply"), ['body' => 'x', 'shipment' => '999999999'], $sara)
            ->assertStatus(422);
        $this->getJson($this->api('/callcenter/couriers'), $sara)->assertJsonPath('data.0.waiting', false);
    }

    public function test_the_call_centre_writes_to_any_courier_even_one_who_never_wrote(): void
    {
        $shipment = $this->withCourier();
        $sara = $this->as($this->sara);

        // أحمد لم يكتب بعد، وشحنته قيد التوصيل: يظهر في «المناديب» ويُكتب له (docs/plan/93)
        $this->getJson($this->api('/callcenter/couriers'), $sara)->assertOk()
            ->assertJsonPath('data.0.name', 'أحمد')->assertJsonPath('data.0.last', null)->assertJsonPath('data.0.unread', false);
        $this->getJson($this->api('/callcenter/couriers?q=زيد'), $sara)->assertOk()->assertJsonCount(0, 'data');

        $this->postJson($this->api("/callcenter/couriers/{$this->courier->id}/reply"), ['body' => 'استعجل الوصل', 'shipment' => $shipment->number], $sara)
            ->assertCreated();
        $this->getJson($this->api('/callcenter/couriers'), $sara)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last', 'استعجل الوصل');
    }

    public function test_board_and_processing_filter_by_governorate_courier_and_merchant(): void
    {
        $baghdad = $this->withCourier('علي');
        $other = Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C2', 'name' => 'سجاد', 'phone' => '07720000002',
                'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]);
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'حسن', 'recipient_phone' => '07801234568',
                'governorate_id' => $this->basraGov()->id, 'address' => 'العنوان', 'cod_amount' => 20_000,
            ], $this->owner);
            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $courier->id]);

            return $shipment->refresh();
        });
        $sara = $this->as($this->sara);

        $page = $this->getJson($this->api('/callcenter/board/in_delivery'), $sara)->assertOk()
            ->assertJsonPath('count', 2)->assertJsonPath('matched', 2)->json();
        $this->assertEqualsCanonicalizing([$this->baghdad()->name_ar, $this->basraGov()->name_ar], array_column($page['facets']['governorates'], 'label'));
        $this->assertEqualsCanonicalizing(['أحمد', 'سجاد'], array_column($page['facets']['couriers'], 'label'));
        $this->assertSame([$this->merchant->business_name], array_column($page['facets']['merchants'], 'label'));

        $this->getJson($this->api('/callcenter/board/in_delivery?governorate_id='.$this->basraGov()->id), $sara)
            ->assertJsonPath('count', 2)->assertJsonPath('matched', 1)->assertJsonPath('data.0.number', $other->number);
        $this->getJson($this->api('/callcenter/board/in_delivery?courier_id='.$this->courier->id), $sara)
            ->assertJsonPath('matched', 1)->assertJsonPath('data.0.number', $baghdad->number);
        $this->getJson($this->api('/callcenter/board/in_delivery?merchant_id='.$this->merchant->id), $sara)->assertJsonPath('matched', 2);

        // والمعالجة كذلك
        $failed = $this->failed();
        $this->getJson($this->api('/callcenter/processing'), $sara)->assertOk()
            ->assertJsonPath('facets.governorates.0.label', $this->baghdad()->name_ar)
            ->assertJsonPath('data.0.number', $failed->number);
        $this->getJson($this->api('/callcenter/processing?governorate_id='.$this->basraGov()->id), $sara)->assertJsonCount(0, 'data');
    }

    public function test_the_merchant_is_notified_of_deliveries_and_processing_decisions(): void
    {
        $toRedeliver = $this->failed();
        $toPostpone = $this->failed();
        $delivered = $this->withCourier('زبون واصل');
        $sara = $this->as($this->sara);

        $this->decide($toRedeliver, $sara, 'redeliver');
        $this->decide($toPostpone, $sara, 'postpone', ['until' => now()->addDay()->toDateString()]);
        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($delivered, ShipmentStatus::Delivered, $this->owner));

        $titles = Tenancy::runFor($this->company, fn () => Announcement::query()->where('merchant_id', $this->merchant->id)->pluck('title'));
        $this->assertContains("إعادة توصيل شحنتك {$toRedeliver->number}", $titles->all());
        $this->assertContains("أُجّلت شحنتك {$toPostpone->number}", $titles->all());
        $this->assertContains("وصلت شحنتك {$delivered->number}", $titles->all());

        // تُطفأ من بيانات الشركة
        Tenancy::runFor($this->company, function () {
            $this->company->forceFill(['settings' => ['notify' => ['delivered' => false, 'processed' => false]]])->save();
        });
        $quiet = $this->withCourier('زبون صامت');
        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)->handle($quiet, ShipmentStatus::Delivered, $this->owner));
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Announcement::query()
            ->where('title', "وصلت شحنتك {$quiet->number}")->count()));
    }
}

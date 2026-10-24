<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ShipmentTickets;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
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
}

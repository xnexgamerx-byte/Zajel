<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\SendOutForDelivery;
use App\Actions\Shipments\ShipmentTickets;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تطبيق المندوب (docs/plan/66): يدخل بحساب الموقع نفسه، ويرى شحناته وحدها، ويسجّل عند الباب
 * بالقواعد نفسها التي تمرّ بها شاشته في الموقع — والنتيجة في القاعدة واحدة من الطريقين.
 */
class CourierApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private Courier $courier;

    private User $user;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
        $this->merchant = $this->makeMerchant($this->company);
        [$this->courier, $this->user] = $this->courierWithLogin('مندوب الكرادة', '07721230001');
    }

    /** @return array{Courier, User} */
    private function courierWithLogin(string $name, string $phone, array $extra = []): array
    {
        return Tenancy::runFor($this->company, function () use ($name, $phone, $extra) {
            $courier = Courier::create([
                'code' => 'C'.substr($phone, -4), 'name' => $name, 'phone' => $phone, 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 5_000, 'cash_limit' => 1_000_000,
            ] + $extra);
            $user = User::create([
                'name' => $name, 'phone' => $phone, 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
            ]);

            return [$courier, $user];
        });
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function token(?User $user = null): string
    {
        return $this->postJson($this->api('/login'), [
            'username' => ($user ?? $this->user)->username, 'password' => 'password', 'app' => 'courier',
        ])->assertOk()->json('token');
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /** شحنةٌ خرجت مع المندوب (هذا افتراضاً) كما يُسندها الموظّف */
    private function ship(string $name, ?Courier $courier = null, array $extra = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($name, $courier, $extra) {
            $shipment = app(CreateShipment::class)->handle(array_merge([
                'merchant_id' => $this->merchant->id,
                'recipient_name' => $name, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area(),
                'address' => 'شارع 62', 'cod_amount' => 40_000,
            ], $extra), $this->staff);

            app(SendOutForDelivery::class)->handle(collect([$shipment->refresh()]), $courier ?? $this->courier, $this->staff);

            return $shipment->refresh();
        });
    }

    private function fresh(Shipment|Courier $model)
    {
        return Tenancy::runFor($this->company, fn () => $model->refresh());
    }

    private function reason(bool $requiresNote = false): FailureReason
    {
        return FailureReason::availableFor($this->company->id)->where('requires_note', $requiresNote)->firstOrFail();
    }

    // ------------------------------------------------------------------ الدخول

    public function test_a_courier_logs_into_the_courier_app_and_nobody_else_does(): void
    {
        $this->postJson($this->api('/login'), ['username' => $this->user->username, 'password' => 'password', 'app' => 'courier'])
            ->assertOk()->assertJsonPath('user.role', 'courier');

        // حساب المندوب لا يدخل تطبيق التاجر، وحساب التاجر لا يدخل تطبيق المندوب
        $this->postJson($this->api('/login'), ['username' => $this->user->username, 'password' => 'password', 'app' => 'merchant'])
            ->assertUnprocessable();

        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
        $this->postJson($this->api('/login'), ['username' => $merchantUser->username, 'password' => 'password', 'app' => 'courier'])
            ->assertUnprocessable();
    }

    public function test_a_suspended_courier_and_an_intermediary_company_cannot_use_the_app(): void
    {
        [, $agentUser] = $this->courierWithLogin('شركة وسيطة', '07721230002', ['is_intermediary' => true]);
        $this->postJson($this->api('/login'), ['username' => $agentUser->username, 'password' => 'password', 'app' => 'courier'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');

        // مندوبٌ دخل ثم أُوقف أو صار وسيطاً: يُرفض رمزه من طلبه التالي
        $token = $this->token();
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertOk();

        Tenancy::runFor($this->company, fn () => $this->courier->forceFill(['is_intermediary' => true])->save());
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertForbidden();

        Tenancy::runFor($this->company, fn () => $this->courier->forceFill(['is_intermediary' => false, 'status' => 'suspended'])->save());
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertForbidden();
        $this->postJson($this->api('/login'), ['username' => $this->user->username, 'password' => 'password', 'app' => 'courier'])
            ->assertUnprocessable();
    }

    public function test_a_merchant_token_cannot_open_the_courier_api(): void
    {
        $merchantUser = Tenancy::runFor($this->company, function () {
            $this->merchant->forceFill(['portal_access' => true])->save();

            return User::create([
                'name' => 'تاجر', 'phone' => '07790000002', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
            ]);
        });
        $token = $this->postJson($this->api('/login'), ['username' => $merchantUser->username, 'password' => 'password', 'app' => 'merchant'])
            ->assertOk()->json('token');

        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson($this->api('/courier/home'))->assertUnauthorized();
    }

    // ------------------------------------------------------------------ الشاشات

    public function test_home_shows_what_is_in_hand_the_cash_and_what_needs_attention(): void
    {
        $this->ship('زينب علي');
        $coded = $this->ship('علي حسن');
        Tenancy::runFor($this->company, fn () => $coded->forceFill(['delivery_code' => '4821'])->save());

        $this->getJson($this->api('/courier/home'), $this->auth($this->token()))->assertOk()
            ->assertJsonPath('name', 'مندوب الكرادة')
            ->assertJsonPath('in_hand.count', 2)
            ->assertJsonPath('in_hand.to_collect', 80_000)
            ->assertJsonPath('cash.in_hand', 0)
            ->assertJsonPath('cash.limit', 1_000_000)
            ->assertJsonPath('attention.0.kind', 'code')
            ->assertJsonPath('attention.0.number', $coded->number);
    }

    public function test_tasks_are_grouped_by_area_searchable_and_only_mine(): void
    {
        $mine = $this->ship('زينب علي');
        $this->ship('حسين كاظم');
        [$other] = $this->courierWithLogin('مندوب آخر', '07721230003');
        $theirs = $this->ship('رنا جاسم', $other);

        $tasks = $this->getJson($this->api('/courier/tasks'), $this->auth($this->token()))->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('to_collect', 80_000)
            ->assertJsonPath('groups.0.governorate', 'بغداد')
            ->assertJsonPath('groups.0.count', 2)
            ->json('groups.0.items');

        $this->assertNotContains($theirs->number, array_column($tasks, 'number'));
        $this->assertArrayNotHasKey('delivery_code', $tasks[0]);

        $this->getJson($this->api('/courier/tasks?q='.$mine->number), $this->auth($this->token()))->assertOk()
            ->assertJsonPath('count', 1)->assertJsonPath('groups.0.items.0.number', $mine->number);
    }

    public function test_a_shipment_shows_everything_for_the_door_but_never_the_code_and_never_someone_elses(): void
    {
        $shipment = $this->ship('زينب علي', null, ['landmark' => 'قرب مدرسة النور']);
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['delivery_code' => '4821'])->save());
        [$other] = $this->courierWithLogin('مندوب آخر', '07721230004');
        $theirs = $this->ship('رنا جاسم', $other);
        $token = $this->token();

        $response = $this->getJson($this->api('/courier/shipments/'.$shipment->id), $this->auth($token))->assertOk()
            ->assertJsonPath('data.recipient.name', 'زينب علي')
            ->assertJsonPath('data.address.landmark', 'قرب مدرسة النور')
            ->assertJsonPath('data.amount', 40_000)
            ->assertJsonPath('data.needs_code', true)
            ->assertJsonPath('data.actions', ['delivered', 'failed_attempt', 'postponed']);
        $this->assertStringNotContainsString('4821', $response->getContent());

        $this->getJson($this->api('/courier/shipments/'.$theirs->id), $this->auth($token))->assertNotFound();
        $this->postJson($this->api('/courier/shipments/'.$theirs->id.'/act'), ['action' => 'delivered'], $this->auth($token))->assertNotFound();

        // الماسح يفتح شحنته برقمها، ولا يفتح شحنة غيره
        $this->getJson($this->api('/courier/shipments/find?q='.$shipment->number), $this->auth($token))
            ->assertOk()->assertJsonPath('data.id', $shipment->id);
        $this->getJson($this->api('/courier/shipments/find?q='.$theirs->number), $this->auth($token))->assertNotFound();
    }

    // ------------------------------------------------------------------ عند الباب

    public function test_delivered_from_the_app_moves_the_cash_and_cannot_change_the_amount(): void
    {
        $shipment = $this->ship('زينب علي');
        $token = $this->token();
        $url = $this->api('/courier/shipments/'.$shipment->id.'/act');

        $this->postJson($url, ['action' => 'delivered', 'collected_amount' => 30_000], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('collected_amount');

        $this->postJson($url, ['action' => 'delivered', 'lat' => 33.3, 'lng' => 44.4], $this->auth($token))->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.actions', []);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame(40_000, (int) $shipment->refresh()->collected_amount);
            $this->assertSame(40_000, (int) $this->courier->refresh()->cash_in_hand);
            $this->assertSame(5_000, (int) $this->courier->commission_balance);
            $event = ShipmentEvent::where('shipment_id', $shipment->id)->where('to_status', 'delivered')->sole();
            $this->assertSame('courier', $event->actor_type);
            $this->assertSame($this->user->id, (int) $event->actor_id);
        });

        // ولا يُسجَّل مرّتين
        $this->postJson($url, ['action' => 'delivered'], $this->auth($token))->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->getJson($this->api('/courier/cash'), $this->auth($token))->assertOk()
            ->assertJsonPath('in_hand', 40_000)->assertJsonPath('net_due', 35_000)
            ->assertJsonPath('movements.0.shipment', $shipment->number);
    }

    public function test_not_delivered_needs_a_reason_and_a_note_when_the_reason_asks_for_one(): void
    {
        $shipment = $this->ship('زينب علي');
        $token = $this->token();
        $url = $this->api('/courier/shipments/'.$shipment->id.'/act');

        $this->postJson($url, ['action' => 'failed_attempt'], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('failure_reason_id');

        $needsNote = $this->reason(requiresNote: true);
        $this->postJson($url, ['action' => 'postponed', 'failure_reason_id' => $needsNote->id], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $reasons = $this->getJson($this->api('/courier/reasons'), $this->auth($token))->assertOk()->json('data');
        $this->assertContains($needsNote->id, array_column($reasons, 'id'));

        $this->postJson($url, ['action' => 'postponed', 'failure_reason_id' => $needsNote->id, 'note' => 'الغد بعد الظهر'], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'postponed');
    }

    public function test_a_delivery_code_is_needed_and_locks_after_five_wrong_tries(): void
    {
        $shipment = $this->ship('زينب علي');
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['delivery_code' => '4821'])->save());
        $token = $this->token();
        $url = $this->api('/courier/shipments/'.$shipment->id.'/act');

        $this->postJson($url, ['action' => 'delivered'], $this->auth($token))->assertUnprocessable()->assertJsonValidationErrors('delivery_code');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($url, ['action' => 'delivered', 'delivery_code' => '000'.$i], $this->auth($token))->assertUnprocessable();
        }
        $this->postJson($url, ['action' => 'delivered', 'delivery_code' => '4821'], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('delivery_code');
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);
    }

    public function test_a_partial_delivery_waits_for_the_call_centre_and_then_uses_its_amount(): void
    {
        $shipment = $this->ship('زينب علي');
        $token = $this->token();
        $base = $this->api('/courier/shipments/'.$shipment->id);

        $this->postJson($base.'/act', ['action' => 'partially_delivered'], $this->auth($token))->assertUnprocessable();

        $this->postJson($base.'/ticket', ['kind' => 'partial', 'requested_amount' => 25_000, 'reason' => 'أخذ قطعة وأرجع الثانية'], $this->auth($token))
            ->assertCreated()->assertJsonPath('data.status', 'open');
        $this->getJson($base.'/ticket', $this->auth($token))->assertOk()->assertJsonPath('data.status', 'open');

        Tenancy::runFor($this->company, function () use ($shipment) {
            $ticket = ShipmentTicket::where('shipment_id', $shipment->id)->sole();
            app(ShipmentTickets::class)->approve($ticket, 25_000, 'موافق', $this->staff);
        });

        $this->getJson($base, $this->auth($token))->assertOk()
            ->assertJsonPath('data.partial_amount', 25_000)
            ->assertJsonPath('data.actions', ['delivered', 'partially_delivered', 'failed_attempt', 'postponed']);

        $this->postJson($base.'/act', ['action' => 'partially_delivered'], $this->auth($token))->assertOk()
            ->assertJsonPath('data.status', 'partially_delivered');
        $this->assertSame(25_000, (int) $this->fresh($shipment)->collected_amount);
    }

    public function test_the_app_and_the_courier_web_screen_leave_the_same_result(): void
    {
        $viaWeb = $this->ship('زبون الموقع');
        $viaApp = $this->ship('زبون التطبيق');
        $reason = $this->reason();

        $this->actingAs($this->user)
            ->post('http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/courier/shipments/'.$viaWeb->id,
                ['action' => 'failed_attempt', 'failure_reason_id' => $reason->id, 'note' => 'لا يرد'])
            ->assertRedirect();
        auth()->logout();

        $this->postJson($this->api('/courier/shipments/'.$viaApp->id.'/act'),
            ['action' => 'failed_attempt', 'failure_reason_id' => $reason->id, 'note' => 'لا يرد'], $this->auth($this->token()))
            ->assertOk();

        Tenancy::runFor($this->company, function () use ($viaWeb, $viaApp) {
            $columns = ['status', 'attempts_count', 'last_failure_reason_id', 'delivery_courier_id', 'collected_amount', 'merchant_due'];
            $this->assertSame(
                $viaWeb->refresh()->only($columns),
                $viaApp->refresh()->only($columns),
            );
            $event = fn (Shipment $s) => ShipmentEvent::where('shipment_id', $s->id)->where('to_status', 'failed_attempt')
                ->sole()->only(['actor_type', 'actor_id', 'courier_id', 'failure_reason_id', 'note']);
            $this->assertSame($event($viaWeb), $event($viaApp));
        });
    }

    public function test_the_phone_location_is_recorded_while_the_app_is_open(): void
    {
        $token = $this->token();

        $this->postJson($this->api('/courier/location'), ['lat' => 33.31, 'lng' => 44.36, 'accuracy' => 20], $this->auth($token))->assertNoContent();
        $this->postJson($this->api('/courier/location'), ['lat' => 200, 'lng' => 44.36], $this->auth($token))->assertUnprocessable();

        Tenancy::runFor($this->company, function () {
            $courier = $this->courier->refresh();
            $this->assertEqualsWithDelta(33.31, (float) $courier->last_lat, 0.0001);
            $this->assertNotNull($courier->last_seen_at);
        });
    }
}

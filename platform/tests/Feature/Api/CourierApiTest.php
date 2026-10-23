<?php

namespace Tests\Feature\Api;

use App\Actions\Notify\Announce;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ProcessFailedAttempt;
use App\Actions\Shipments\SendOutForDelivery;
use App\Actions\Shipments\ShipmentTickets;
use App\Actions\Support\CourierChat;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\PickupPayout;
use App\Models\PickupRequest;
use App\Models\PickupShare;
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
            // ما مُرّر يغلب الافتراض: ['type' => 'pickup'] مندوب استلام
            $courier = Courier::create($extra + [
                'code' => 'C'.substr($phone, -4), 'name' => $name, 'phone' => $phone, 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 5_000, 'cash_limit' => 1_000_000,
            ]);
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
        $this->ship('علي حسن');

        $this->getJson($this->api('/courier/home'), $this->auth($this->token()))->assertOk()
            ->assertJsonPath('name', 'مندوب الكرادة')
            ->assertJsonPath('in_hand.count', 2)
            ->assertJsonPath('in_hand.to_collect', 80_000)
            ->assertJsonPath('cash.in_hand', 0)
            ->assertJsonPath('cash.limit', 1_000_000)
            // لا كود تسليم (docs/plan/76): لا شيء يحتاج انتباهه بعد
            ->assertJsonPath('attention', []);
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
            ->assertJsonMissingPath('data.needs_code')
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

    /**
     * خانات «مهامي» الأربع (docs/plan/75): ما لم يُسلَّم يبقى في «الراجع» ينتظر المعالجة ولا يختفي،
     * وما أُعيد إليه في «إعادة التوصيل»، والمؤجّلة بموعدها في «المؤجل».
     */
    public function test_failed_postponed_and_redelivered_each_have_their_tab(): void
    {
        $failed = $this->ship('علي حسين');
        $postponed = $this->ship('زينب علي');
        $redelivered = $this->ship('حسين كاظم');
        $fresh = $this->ship('نور الهدى');
        $token = $this->token();
        $reason = $this->reason();

        $this->postJson($this->api("/courier/shipments/{$failed->id}/act"),
            ['action' => 'failed_attempt', 'failure_reason_id' => $reason->id], $this->auth($token))->assertOk();
        $this->postJson($this->api("/courier/shipments/{$redelivered->id}/act"),
            ['action' => 'failed_attempt', 'failure_reason_id' => $reason->id], $this->auth($token))->assertOk();
        Tenancy::runFor($this->company, fn () => app(ProcessFailedAttempt::class)
            ->handle($this->fresh($redelivered), 'redeliver', $this->staff));

        // المؤجّلة بموعد: من اليوم إلى شهر
        $act = $this->api("/courier/shipments/{$postponed->id}/act");
        $this->postJson($act, ['action' => 'postponed', 'failure_reason_id' => $reason->id, 'until' => today()->subDay()->toDateString()],
            $this->auth($token))->assertUnprocessable()->assertJsonValidationErrors('until');
        $at = today()->addDay()->setTime(16, 0);
        $this->postJson($act, ['action' => 'postponed', 'failure_reason_id' => $reason->id, 'until' => $at->toIso8601String()],
            $this->auth($token))->assertOk()->assertJsonPath('data.until', $at->toIso8601String())->assertJsonPath('data.can_resume', true);

        $tab = fn (string $kind) => $this->getJson($this->api('/courier/tasks?kind='.$kind), $this->auth($token))->assertOk();

        $delivery = $tab('delivery')->assertJsonPath('count', 1)
            ->assertJsonPath('counts', ['delivery' => 1, 'redelivery' => 1, 'postponed' => 1, 'returns' => 1]);
        $this->assertSame($fresh->number, $delivery->json('groups.0.items.0.number'));

        $tab('redelivery')->assertJsonPath('count', 1)->assertJsonPath('groups.0.items.0.number', $redelivered->number)
            ->assertJsonPath('groups.0.items.0.redelivery', true)->assertJsonPath('to_collect', 40_000);
        $tab('postponed')->assertJsonPath('groups.0.title', 'موعدها غداً')
            ->assertJsonPath('groups.0.items.0.number', $postponed->number)->assertJsonPath('groups.0.items.0.until', $at->toIso8601String());
        // لم تُسلَّم ولم يُقرَّر فيها: في «الراجع» تنتظر، لا تختفي
        $tab('returns')->assertJsonPath('groups.0.title', 'لم تُسلَّم — تنتظر قرار المعالجة')
            ->assertJsonPath('groups.0.items.0.number', $failed->number)->assertJsonPath('groups.0.items.0.waiting', true);

        // الرئيسية تذكّره بموعدها
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertOk()
            ->assertJsonPath('appointments.0.number', $postponed->number)->assertJsonPath('appointments.0.at', $at->toIso8601String());

        // «أوصلها الآن»: تعود إليه في «إعادة التوصيل» ويُسجَّل عليها عند الباب
        $this->postJson($this->api("/courier/shipments/{$postponed->id}/resume"), [], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
        $tab('redelivery')->assertJsonPath('count', 2);
        $this->postJson($this->api("/courier/shipments/{$fresh->id}/resume"), [], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    /** لا كود تسليم (docs/plan/76): ولو حمل وصلٌ قديم كوداً، يسلّمه المندوب بلا إجراءٍ آخر */
    public function test_delivered_needs_no_delivery_code(): void
    {
        $shipment = $this->ship('زينب علي');
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['delivery_code' => '4821'])->save());

        $this->postJson($this->api('/courier/shipments/'.$shipment->id.'/act'), ['action' => 'delivered'], $this->auth($this->token()))
            ->assertOk()->assertJsonPath('data.status', 'delivered');
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

    // ------------------------------------------------- «اليوم» والاستلام والحصص والجرس والمحادثة (docs/plan/71)

    public function test_today_lists_what_was_recorded_today_and_what_was_collected(): void
    {
        $delivered = $this->ship('زينب علي');
        $this->ship('علي حسين');
        $token = $this->token();
        $this->postJson($this->api('/courier/shipments/'.$delivered->id.'/act'), ['action' => 'delivered'], $this->auth($token))->assertOk();

        $this->getJson($this->api('/courier/today'), $this->auth($token))->assertOk()
            ->assertJsonPath('delivered', 1)->assertJsonPath('collected', 40_000)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $delivered->number)
            ->assertJsonPath('data.0.collected', 40_000);

        // والرئيسية تعرف ما يعمله وما حصّله اليوم
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertOk()
            ->assertJsonPath('delivers', true)->assertJsonPath('picks', false)
            ->assertJsonPath('today.collected', 40_000)->assertJsonPath('chat.unread', false);
    }

    public function test_a_pickup_courier_closes_his_pickup_from_the_app_and_nobody_elses(): void
    {
        [$picker, $pickerUser] = $this->courierWithLogin('سجاد', '07721230009', ['type' => 'pickup']);
        Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 30_000,
        ], $this->staff));
        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'courier_id' => $picker->id,
            'number' => 'PU000001', 'status' => 'assigned', 'expected_count' => 1,
        ]));

        // مندوب التوصيل وحده لا يرى شاشة الاستلام، ولا يغلق طلب غيره
        $this->getJson($this->api('/courier/pickups'), $this->auth($this->token()))->assertForbidden();
        $this->postJson($this->api('/courier/pickups/'.$pickup->id.'/complete'), ['actual_count' => 1], $this->auth($this->token()))
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $token = $this->token($pickerUser);
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertOk()
            ->assertJsonPath('picks', true)->assertJsonPath('pickups', 1);
        $this->getJson($this->api('/courier/pickups'), $this->auth($token))->assertOk()
            ->assertJsonPath('data.0.number', 'PU000001')->assertJsonPath('data.0.expected', 1);

        $this->postJson($this->api('/courier/pickups/'.$pickup->id.'/complete'), ['actual_count' => 1], $this->auth($token))
            ->assertOk()->assertJsonPath('message', fn ($m) => str_starts_with($m, 'استُلم'));
        $this->assertSame('completed', Tenancy::runFor($this->company, fn () => $pickup->refresh()->status));
        $this->postJson($this->api('/courier/pickups/'.$pickup->id.'/complete'), ['actual_count' => 1], $this->auth($token))
            ->assertUnprocessable();
    }

    public function test_shares_can_be_objected_to_and_a_payout_confirmed_once(): void
    {
        [$picker, $pickerUser] = $this->courierWithLogin('سجاد', '07721230009', ['type' => 'pickup']);
        [$share, $payout] = Tenancy::runFor($this->company, fn () => [
            PickupShare::create(['courier_id' => $picker->id, 'shipments_count' => 4, 'rate' => 1000, 'amount' => 4000]),
            PickupPayout::create(['courier_id' => $picker->id, 'number' => 'PP-1', 'earned' => 4000, 'paid_amount' => 4000]),
        ]);
        $token = $this->token($pickerUser);

        $this->getJson($this->api('/courier/shares'), $this->auth($token))->assertOk()
            ->assertJsonPath('shares.0.count', 4)->assertJsonPath('shares.0.can_object', true)
            ->assertJsonPath('payouts.0.number', 'PP-1')->assertJsonPath('payouts.0.confirmed', false);

        $this->postJson($this->api('/courier/shares/'.$share->id.'/object'), ['claimed_count' => 6], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->api('/courier/shares/'.$share->id.'/object'), ['claimed_count' => 6, 'reason' => 'طردان لم يُسجَّلا'], $this->auth($token))
            ->assertOk();
        $this->assertSame('objected', Tenancy::runFor($this->company, fn () => $share->refresh()->status));

        $this->postJson($this->api('/courier/payouts/'.$payout->id.'/confirm'), [], $this->auth($token))->assertOk();
        $this->postJson($this->api('/courier/payouts/'.$payout->id.'/confirm'), [], $this->auth($token))->assertUnprocessable();

        // حصص غيره لا تُمسّ
        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('/courier/payouts/'.$payout->id.'/confirm'), [], $this->auth($this->token()))->assertNotFound();
    }

    public function test_the_bell_and_the_office_chat_are_the_web_screens_own(): void
    {
        Tenancy::runFor($this->company, fn () => app(Announce::class)->publish('delivery_couriers', 'تحديث', 'لا توصيل يوم الجمعة.', $this->staff));
        $token = $this->token();

        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertJsonPath('unread', 1);
        $this->getJson($this->api('/courier/notifications'), $this->auth($token))->assertOk()
            ->assertJsonPath('data.0.title', 'تحديث')->assertJsonPath('data.0.fresh', true);
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertJsonPath('unread', 0);

        // المحادثة مع المكتب حين تُفتح للشركة وحدها
        $this->setFeature($this->company, Feature::Conversations, false);
        $this->getJson($this->api('/courier/chat'), $this->auth($token))->assertForbidden();
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertJsonPath('chat', null);
        Tenancy::runFor($this->company, fn () => CompanyFeature::where('feature', Feature::Conversations->value)->delete());
        $this->company->forgetFeatures();

        $shipment = $this->ship('زينب علي');
        $this->postJson($this->api('/courier/chat'), ['body' => 'الزبون لا يردّ', 'shipment_id' => $shipment->id], $this->auth($token))
            ->assertCreated();
        Tenancy::runFor($this->company, fn () => app(CourierChat::class)
            ->send($this->courier, 'اتصل به بعد ساعة', $this->staff, CourierChat::STAFF));

        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertJsonPath('chat.unread', true);
        $this->getJson($this->api('/courier/chat'), $this->auth($token))->assertOk()
            ->assertJsonPath('data.0.mine', true)->assertJsonPath('data.0.shipment.id', $shipment->id)
            ->assertJsonPath('data.1.mine', false)->assertJsonPath('data.1.body', 'اتصل به بعد ساعة');
        $this->getJson($this->api('/courier/home'), $this->auth($token))->assertJsonPath('chat.unread', false);
    }

    // ------------------------------------------------------- مسح الشحنة عند الاستلام والتسليم (docs/plan/72)

    public function test_with_scanning_on_the_courier_records_nothing_at_the_door_before_scanning_the_parcel(): void
    {
        $shipment = $this->ship('زينب علي');
        $other = $this->ship('علي حسين');
        $token = $this->token();
        $url = $this->api('/courier/shipments/'.$shipment->id.'/act');

        // مطفأةٌ: كما كان
        $this->getJson($this->api('/courier/shipments/'.$shipment->id), $this->auth($token))->assertJsonPath('data.needs_scan', false);

        $this->setFeature($this->company, Feature::ScanParcels);
        $this->getJson($this->api('/courier/shipments/'.$shipment->id), $this->auth($token))->assertJsonPath('data.needs_scan', true);

        $this->postJson($url, ['action' => 'delivered'], $this->auth($token))
            ->assertUnprocessable()->assertJsonValidationErrors('scan');
        // طردٌ آخر بيده ليس هذا
        $this->postJson($url, ['action' => 'delivered', 'scan' => $other->number], $this->auth($token))
            ->assertUnprocessable()->assertJsonPath('errors.scan.0', fn ($m) => str_starts_with($m, 'ما مسحته ليس'));
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        $this->postJson($url, ['action' => 'delivered', 'scan' => $shipment->number], $this->auth($token))->assertOk();
        $this->assertSame(ShipmentStatus::Delivered, $this->fresh($shipment)->status);

        // وشاشته في الموقع بالقاعدة نفسها
        $this->app['auth']->forgetGuards();
        $web = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/courier/shipments/'.$other->id;
        $this->actingAs($this->user)->post($web, ['action' => 'failed_attempt', 'failure_reason_id' => $this->reason()->id])
            ->assertSessionHasErrors('scan');
        $this->actingAs($this->user)->post($web, ['action' => 'failed_attempt', 'failure_reason_id' => $this->reason()->id, 'scan' => $other->number])
            ->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::FailedAttempt, $this->fresh($other)->status);
    }

    public function test_with_scanning_on_a_pickup_takes_only_the_scanned_parcels_of_that_merchant(): void
    {
        [$picker, $pickerUser] = $this->courierWithLogin('سجاد', '07721230009', ['type' => 'pickup']);
        $make = fn (Merchant $merchant, string $name) => Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 30_000,
        ], $this->staff));
        [$first, $second, $third] = [$make($this->merchant, 'زبون 1'), $make($this->merchant, 'زبون 2'), $make($this->merchant, 'زبون 3')];
        $foreign = $make($this->makeMerchant($this->company, 'M0002'), 'زبون تاجرٍ آخر');
        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'courier_id' => $picker->id,
            'number' => 'PU000001', 'status' => 'assigned', 'expected_count' => 3,
        ]));
        $this->setFeature($this->company, Feature::ScanParcels);
        $token = $this->token($pickerUser);

        $this->getJson($this->api('/courier/pickups'), $this->auth($token))->assertJsonPath('scan_required', true);
        $scan = fn (string $code) => $this->getJson($this->api('/courier/pickups/'.$pickup->id.'/scan?code='.urlencode($code)), $this->auth($token));
        $scan($first->number)->assertOk()->assertJsonPath('data.id', $first->id)->assertJsonPath('data.recipient', 'زبون 1');
        $scan($foreign->number)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'ليست لهذا التاجر'));
        $scan('999999999')->assertUnprocessable();

        $url = $this->api('/courier/pickups/'.$pickup->id.'/complete');
        $this->postJson($url, ['actual_count' => 3], $this->auth($token))->assertUnprocessable()->assertJsonValidationErrors('shipments');
        $this->postJson($url, ['shipments' => [$first->id, $foreign->id]], $this->auth($token))->assertUnprocessable();
        $this->postJson($url, ['shipments' => [$first->id, $second->id]], $this->auth($token))->assertOk()
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'استُلم طردان'));

        Tenancy::runFor($this->company, function () use ($first, $second, $third, $pickup) {
            $this->assertSame(ShipmentStatus::PickedUp, $first->refresh()->status);
            $this->assertSame(ShipmentStatus::PickedUp, $second->refresh()->status);
            // ما لم يُمسح بقي عند التاجر
            $this->assertNotSame(ShipmentStatus::PickedUp, $third->refresh()->status);
            $this->assertSame(2, $pickup->refresh()->actual_count);
        });
    }

    public function test_with_scanning_on_the_web_pickup_screen_takes_the_scanned_lines(): void
    {
        [$picker, $pickerUser] = $this->courierWithLogin('سجاد', '07721230009', ['type' => 'pickup']);
        [$first, $second] = Tenancy::runFor($this->company, fn () => collect(['زبون 1', 'زبون 2'])->map(fn ($name) => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 30_000,
        ], $this->staff))->all());
        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'courier_id' => $picker->id,
            'number' => 'PU000001', 'status' => 'assigned', 'expected_count' => 2,
        ]));
        $this->setFeature($this->company, Feature::ScanParcels);
        $url = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/courier/pickups/'.$pickup->id.'/complete';

        $this->actingAs($pickerUser)->post($url, ['actual_count' => 2])->assertSessionHasErrors('codes');
        $this->actingAs($pickerUser)->post($url, ['codes' => "{$first->number}\n999999999"])->assertSessionHasErrors('codes');
        // قارئ الباركود يكتب الرقم مرّتين أحياناً: يُعدّ مرّة
        $this->actingAs($pickerUser)->post($url, ['codes' => "{$first->number}\n{$first->number}\n"])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'استُلم طرد واحد'));

        Tenancy::runFor($this->company, function () use ($first, $second) {
            $this->assertSame(ShipmentStatus::PickedUp, $first->refresh()->status);
            $this->assertNotSame(ShipmentStatus::PickedUp, $second->refresh()->status);
        });
    }
}

<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\PushDevice;
use App\Models\Shipment;
use App\Models\User;
use App\Support\MerchantNotice;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * إشعارات الهواتف (docs/plan/82): الهاتف يُسجَّل برمزه بعد الدخول، ويصل إشعار التاجر إلى هواتفه،
 * ومن رفضته Firebase يُمسح. وبلا مفتاح خدمة لا يُرسل شيء ولا يتعطّل شيء.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    private User $staff;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0007');
        $this->staff = $this->makeUser($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر', 'phone' => '07790000007',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]));

        $this->keyFile = $this->serviceAccountFile();
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    /**
     * ملف حساب خدمة للاختبار. رمز الوصول يُوضع في الكاش، فلا يُوقَّع JWT هنا: التوقيع يحتاج إعدادات OpenSSL
     * الكاملة الموجودة على السيرفر، وتُغطّيه الجلسة الحقيقية. هذا الاختبار يغطّي الإرسال والمسح.
     */
    private function serviceAccountFile(): string
    {
        $path = sys_get_temp_dir().'/fcm-test-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account', 'project_id' => 'zajel-test',
            'client_email' => 'svc@zajel-test.iam.gserviceaccount.com', 'private_key' => 'test-only',
        ]));
        Cache::put('fcm.access_token', 'ya29.test', now()->addMinutes(10));

        return $path;
    }

    private function fakeGoogle(array $responses = []): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::sequence(array_map(
            fn (array $r) => Http::response($r[0], $r[1]),
            $responses ?: [[[], 200]],
        ))]);
    }

    private function device(string $token, User $owner): void
    {
        Tenancy::runFor($this->company, fn () => PushDevice::create(['user_id' => $owner->id, 'token' => $token, 'platform' => 'android']));
    }

    private function delivered(string $name): Shipment
    {
        $shipment = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 40_000,
        ], $this->staff));
        Tenancy::runFor($this->company, fn () => $shipment->forceFill([
            'status' => ShipmentStatus::Delivered->value, 'delivered_at' => now(), 'collected_amount' => 40_000,
        ])->save());

        return $shipment->refresh();
    }

    public function test_a_phone_registers_its_token_and_unregisters_on_logout(): void
    {
        Tenancy::runFor($this->company, fn () => $this->user->refresh());
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('http://zajel.'.config('zajel.tenant_domain').'/api/v1/login', [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $api = 'http://zajel.'.config('zajel.tenant_domain').'/api/v1';

        $this->postJson($api.'/devices', ['token' => 'fcm-phone-1'], $headers)->assertCreated();
        $this->postJson($api.'/devices', ['token' => 'fcm-phone-1'], $headers)->assertCreated();
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => PushDevice::count()));

        $this->deleteJson($api.'/devices', ['token' => 'fcm-phone-1'], $headers)->assertOk();
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => PushDevice::count()));
    }

    public function test_without_a_service_account_nothing_is_sent_and_nothing_breaks(): void
    {
        config(['services.firebase.service_account' => null]);
        Http::fake();
        $this->device('fcm-phone-2', $this->user);
        Cache::forget('fcm.access_token');

        $shipment = $this->delivered('زبون بلا مفتاح');
        Tenancy::runFor($this->company, fn () => MerchantNotice::delivered($shipment));

        Http::assertNothingSent();
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Announcement::where('merchant_id', $this->merchant->id)
            ->where('title', "وصلت شحنتك {$shipment->number}")->count()), 'الجرس يبقى');
    }

    public function test_the_merchants_phones_get_the_delivery_notice_and_dead_tokens_are_removed(): void
    {
        config(['services.firebase.service_account' => $this->keyFile]);
        $unregistered = ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]];
        $this->fakeGoogle([[$unregistered, 404]]);
        $this->device('fcm-phone-3', $this->user);
        $other = Tenancy::runFor($this->company, fn () => User::create(['name' => 'تاجر آخر', 'phone' => '07790000008',
            'password' => 'password', 'role' => UserRole::Merchant, 'merchant_id' => $this->makeMerchant($this->company, 'M0008')->id, 'is_active' => true]));
        $this->device('fcm-phone-other', $other);

        $shipment = $this->delivered('زبون مع إشعار');
        Tenancy::runFor($this->company, fn () => MerchantNotice::delivered($shipment));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/zajel-test/messages:send')
            && $request['message']['token'] === 'fcm-phone-3'
            && $request['message']['notification']['title'] === "وصلت شحنتك {$shipment->number}");
        Http::assertNotSent(fn ($request) => ($request['message']['token'] ?? null) === 'fcm-phone-other');

        // رفضت Firebase الرمز نهائياً فيُمسح، ولا يُكرَّر إليه
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => PushDevice::where('token', 'fcm-phone-3')->count()));
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => PushDevice::where('token', 'fcm-phone-other')->count()));
    }

    private function merchantHeaders(): array
    {
        Tenancy::runFor($this->company, fn () => $this->user->refresh());
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('http://zajel.'.config('zajel.tenant_domain').'/api/v1/login', [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_the_merchant_chooses_which_notices_reach_the_phone_and_with_or_without_sound(): void
    {
        config(['services.firebase.service_account' => $this->keyFile]);
        Http::fake(['fcm.googleapis.com/*' => Http::response([], 200)]);
        $this->device('fcm-phone-4', $this->user);
        $api = 'http://zajel.'.config('zajel.tenant_domain').'/api/v1/merchant/notifications/settings';
        $headers = $this->merchantHeaders();

        // كلّها شغّالة بصوت ما لم يختر
        $this->getJson($api, $headers)->assertOk()
            ->assertJsonPath('data.sound', true)
            ->assertJsonPath('data.kinds.0.key', 'delivered')->assertJsonPath('data.kinds.0.on', true)
            ->assertJsonPath('data.kinds.1.key', 'failed')->assertJsonPath('data.kinds.2.key', 'processed');

        $shipment = $this->delivered('زبون بصوت');
        Tenancy::runFor($this->company, fn () => MerchantNotice::delivered($shipment));
        Http::assertSent(fn ($r) => ($r['message']['android']['notification']['channel_id'] ?? null) === 'updates'
            && ($r['message']['data']['kind'] ?? null) === 'delivered');

        // بلا صوت، والواصل لا يصل الهاتف — والجرس يبقى يحمله
        $this->putJson($api, ['sound' => false, 'delivered' => false], $headers)->assertOk()
            ->assertJsonPath('data.sound', false)->assertJsonPath('data.kinds.0.on', false)
            ->assertJsonPath('data.kinds.2.on', true);
        $sent = count(Http::recorded());
        $second = $this->delivered('زبون بلا صوت');
        Tenancy::runFor($this->company, fn () => MerchantNotice::delivered($second));
        $this->assertCount($sent, Http::recorded(), 'الواصل لا يصل هاتفاً أطفأه');
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Announcement::where('title', "وصلت شحنتك {$second->number}")->count()));

        Tenancy::runFor($this->company, fn () => MerchantNotice::processed($second, 'redeliver'));
        Http::assertSent(fn ($r) => ($r['message']['android']['notification']['channel_id'] ?? null) === 'updates_silent'
            && ($r['message']['data']['kind'] ?? null) === 'processed');
    }

    public function test_a_failed_attempt_tells_the_merchant_it_is_in_processing(): void
    {
        config(['services.firebase.service_account' => $this->keyFile]);
        $this->fakeGoogle();
        $this->device('fcm-phone-5', $this->user);
        $shipment = $this->delivered('زبون لم يستلم');
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['status' => ShipmentStatus::OutForDelivery->value])->save());
        $reason = Tenancy::runFor($this->company, fn () => FailureReason::availableFor($this->company->id)->where('requires_note', false)->firstOrFail());

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($shipment->refresh(), ShipmentStatus::FailedAttempt, $this->staff, ['failure_reason_id' => $reason->id]));

        $title = "شحنتك {$shipment->number} لم تُسلَّم — للمعالجة";
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Announcement::where('merchant_id', $this->merchant->id)->where('title', $title)->count()));
        Http::assertSent(fn ($r) => $r['message']['notification']['title'] === $title
            && str_contains($r['message']['notification']['body'], "السبب: {$reason->name_ar}")
            && $r['message']['data']['kind'] === 'failed');

        // والشركة تطفئه من «بيانات الشركة»
        Tenancy::runFor($this->company, function () {
            $settings = $this->company->settings ?? [];
            data_set($settings, 'notify.failed', false);
            $this->company->forceFill(['settings' => $settings])->save();
        });
        $other = $this->delivered('زبون ثانٍ');
        Tenancy::runFor($this->company, fn () => $other->forceFill(['status' => ShipmentStatus::OutForDelivery->value])->save());
        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($other->refresh(), ShipmentStatus::FailedAttempt, $this->staff, ['failure_reason_id' => $reason->id]));
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Announcement::where('title', "شحنتك {$other->number} لم تُسلَّم — للمعالجة")->count()));
    }
}

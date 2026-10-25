<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Company;
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
}

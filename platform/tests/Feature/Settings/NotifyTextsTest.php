<?php

namespace Tests\Feature\Settings;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\SendOutForDelivery;
use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PushDevice;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserGrant;
use App\Support\MerchantNotice;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نصوص الإشعارات (docs/plan/91): الشركة تكتب ما يصل الهواتف، ومن يكتبه صلاحيةٌ لمدير الشركة يمنحها.
 */
class NotifyTextsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private string $host;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');

        Tenancy::runFor($this->company, function () {
            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
            ]);
            $courierUser = User::create(['name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true]);
            $merchantUser = User::create(['name' => 'تاجر', 'phone' => '07790000007', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]);
            PushDevice::create(['user_id' => $courierUser->id, 'token' => 'fcm-courier', 'platform' => 'android']);
            PushDevice::create(['user_id' => $merchantUser->id, 'token' => 'fcm-merchant', 'platform' => 'android']);
        });

        $this->keyFile = sys_get_temp_dir().'/fcm-texts-'.uniqid().'.json';
        file_put_contents($this->keyFile, json_encode([
            'type' => 'service_account', 'project_id' => 'zajel-test',
            'client_email' => 'svc@zajel-test.iam.gserviceaccount.com', 'private_key' => 'test-only',
        ]));
        Cache::put('fcm.access_token', 'ya29.test', now()->addMinutes(10));
        config(['services.firebase.service_account' => $this->keyFile]);
        Http::fake(['fcm.googleapis.com/*' => Http::response([], 200)]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    /** ما وصل هاتفاً بعينه: [العنوان, النصّ] */
    private function pushed(string $token): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? [])
            ->filter(fn ($m) => ($m['token'] ?? null) === $token)
            ->map(fn ($m) => [$m['notification']['title'], $m['notification']['body']])
            ->values()
            ->all();
    }

    private function shipment(string $name = 'منى'): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'العنوان', 'cod_amount' => 50_000,
        ], $this->owner));
    }

    private function save(User $user, array $texts, array $reset = [])
    {
        return $this->actingAs($user)->put($this->host.'/notify-texts', ['texts' => $texts, 'reset' => $reset]);
    }

    public function test_the_screen_is_closed_until_the_platform_opens_the_feature(): void
    {
        $this->actingAs($this->owner)->get($this->host.'/notify-texts')->assertForbidden();
        $this->assertFalse(Feature::NotifyTexts->included());

        $this->setFeature($this->company, Feature::NotifyTexts);

        $this->actingAs($this->owner)->get($this->host.'/notify-texts')->assertOk()
            ->assertSee('نصوص الإشعارات')->assertSee('تصل التاجر')->assertSee('تصل المندوب')->assertSee('تصل الكول سنتر')
            ->assertSee('أُسندت إليك {الشحنات} للتوصيل — افتح التطبيق لتراها.');
    }

    public function test_the_company_manager_has_it_and_gives_it_to_whom_he_chooses(): void
    {
        $this->setFeature($this->company, Feature::NotifyTexts);
        $admin = $this->makeUser($this->company, UserRole::CompanyAdmin);
        $callCentre = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($admin)->get($this->host.'/notify-texts')->assertOk();
        $this->actingAs($callCentre)->get($this->host.'/notify-texts')->assertForbidden();
        $this->save($callCentre, ['delivered' => ['title' => 'x']])->assertForbidden();

        Tenancy::runFor($this->company, fn () => UserGrant::create(['user_id' => $callCentre->id, 'ability' => 'notify.texts']));

        $this->actingAs($callCentre->refresh())->get($this->host.'/notify-texts')->assertOk();
    }

    public function test_the_courier_and_merchant_phones_get_the_company_texts_with_their_fields(): void
    {
        $this->setFeature($this->company, Feature::NotifyTexts);

        $this->save($this->owner, [
            'courier_assigned_one' => ['title' => 'عندك شغل', 'body' => 'الوصل {الوصل} صار عندك'],
            'amount_change' => ['title' => 'الوصل {الوصل} تعدّل', 'body' => 'من {المبلغ السابق} إلى {المبلغ الجديد} — {الزبون}'],
        ])->assertRedirect()->assertSessionHas('success');
        $this->company->refresh();

        $shipment = $this->shipment();
        Tenancy::runFor($this->company, fn () => app(SendOutForDelivery::class)
            ->handle(collect([$shipment->refresh()]), $this->courier, $this->owner));

        $this->assertSame([['عندك شغل', "الوصل {$shipment->number} صار عندك"]], $this->pushed('fcm-courier'));

        Tenancy::runFor($this->company, fn () => MerchantNotice::amountChanged($shipment->refresh(), 50_000, 45_000, $this->owner));

        $expected = ["الوصل {$shipment->number} تعدّل", 'من 50,000 د.ع إلى 45,000 د.ع — منى'];
        $this->assertContains($expected, $this->pushed('fcm-merchant'));
        // والجرس بالنصّ نفسه
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Announcement::where('title', $expected[0])->where('body', $expected[1])->count()));

        $this->assertSame(1, Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'notify_texts_updated')->count()));
    }

    public function test_what_the_company_leaves_and_what_it_resets_stays_the_system_text(): void
    {
        $this->setFeature($this->company, Feature::NotifyTexts);

        // نصّ النظام كما هو لا يُحفظ نسخة
        $this->save($this->owner, [
            'courier_assigned_one' => ['title' => 'شحنات جديدة للتوصيل', 'body' => 'أُسندت إليك الشحنة {الوصل} — افتح التطبيق لتراها.'],
        ])->assertSessionHas('success', 'لم يتغيّر شيء.');
        $this->assertNull($this->company->refresh()->setting('notify_texts'));

        $this->save($this->owner, ['courier_assigned_one' => ['title' => 'عندك شغل']]);
        $this->assertSame(['title' => 'عندك شغل'], $this->company->refresh()->setting('notify_texts.courier_assigned_one'));

        $this->save($this->owner, ['courier_assigned_one' => ['title' => 'عندك شغل']], ['courier_assigned_one' => 1]);
        $this->assertNull($this->company->refresh()->setting('notify_texts'));

        $shipment = $this->shipment();
        Tenancy::runFor($this->company, fn () => app(SendOutForDelivery::class)
            ->handle(collect([$shipment->refresh()]), $this->courier, $this->owner));

        $this->assertSame([['شحنات جديدة للتوصيل', "أُسندت إليك الشحنة {$shipment->number} — افتح التطبيق لتراها."]], $this->pushed('fcm-courier'));
    }

    public function test_a_field_that_does_not_belong_to_the_notice_is_refused(): void
    {
        $this->setFeature($this->company, Feature::NotifyTexts);

        $this->save($this->owner, ['courier_assigned_one' => ['body' => 'الوصل {الوصل} للزبون {الزبون}']])
            ->assertSessionHasErrors('texts.courier_assigned_one.body');

        $this->assertNull($this->company->refresh()->setting('notify_texts'));
    }

    public function test_a_closed_feature_keeps_the_system_texts_even_if_some_were_written(): void
    {
        $this->company->forceFill(['settings' => ['notify_texts' => ['courier_assigned_one' => ['title' => 'عندك شغل']]]])->save();

        $shipment = $this->shipment();
        Tenancy::runFor($this->company, fn () => app(SendOutForDelivery::class)
            ->handle(collect([$shipment->refresh()]), $this->courier, $this->owner));

        $this->assertSame('شحنات جديدة للتوصيل', $this->pushed('fcm-courier')[0][0]);
    }
}

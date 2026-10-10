<?php

namespace Tests\Feature\Api;

use App\Actions\Notify\Announce;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Support\Converse;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * من تجربة التطبيق على الهاتف (docs/plan/59): صورة التاجر أو شعاره، والجرس، والتحيّة بوقت
 * بغداد، وتتبّع الشحنة بمندوبها وآخر تحديث — و«تتبّع المناديب» في النظام.
 */
class MerchantAppProfileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    private User $owner;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        // المراسلة مفتوحة اليوم كلّه: الاختبار لا يتبع ساعة تشغيله (docs/plan/39)
        $this->company->forceFill(['settings' => ['support' => ['merchant_from' => 0, 'merchant_to' => 24]]])->save();
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        [$this->user, $this->courier] = Tenancy::runFor($this->company, fn () => [
            User::create(['name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
                'merchant_id' => $this->merchant->id, 'is_active' => true]),
            Courier::create(['code' => 'C1', 'name' => 'عباس', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
        ]);
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function headers(): array
    {
        $token = $this->postJson($this->api('/login'), [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function outForDelivery(): Shipment
    {
        return Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 25_000,
            ], $this->owner);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    public function test_the_logo_is_empty_until_the_merchant_puts_one(): void
    {
        $headers = $this->headers();
        $this->getJson($this->api('/merchant/home'), $headers)->assertOk()->assertJsonPath('logo', null);

        $this->post($this->api('/merchant/logo'), ['logo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], $headers)
            ->assertStatus(422)->assertJsonPath('errors.logo.0', 'الصورة JPG أو PNG أو WEBP.');
        $url = $this->post($this->api('/merchant/logo'), ['logo' => UploadedFile::fake()->image('shop.png', 300, 300)], $headers)
            ->assertOk()->json('logo');

        $path = Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->logo_path);
        Storage::disk('local')->assertExists($path);
        $this->getJson($this->api('/merchant/home'), $headers)->assertJsonPath('logo', $url);
        $this->get($url, $headers)->assertOk();

        // غيرها تحلّ محلّها وتُمحى القديمة، والحذف يعيدها فارغة
        $this->post($this->api('/merchant/logo'), ['logo' => UploadedFile::fake()->image('new.jpg')], $headers)->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->deleteJson($this->api('/merchant/logo'), [], $headers)->assertOk()->assertJsonPath('logo', null);
        $this->get($url, $headers)->assertNotFound();
    }

    public function test_the_bell_lists_notices_and_company_replies(): void
    {
        $headers = $this->headers();
        Tenancy::runFor($this->company, function () {
            app(Announce::class)->publish('merchants', 'عطلة العيد', 'لا توصيل يوم الجمعة.', $this->owner);
            $c = app(Converse::class)->start($this->merchant, 'تأخّر الطرد', 'متى؟', $this->user, Converse::MERCHANT);
            app(Converse::class)->reply($c, 'غداً', $this->owner, Converse::STAFF);
        });

        $this->getJson($this->api('/merchant/home'), $headers)->assertJsonPath('unread', 2);
        $this->getJson($this->api('/merchant/notifications'), $headers)->assertOk()
            ->assertJsonPath('data.0.kind', 'support')->assertJsonPath('data.0.title', 'ردّت الشركة: تأخّر الطرد')
            ->assertJsonPath('data.1.kind', 'notice')->assertJsonPath('data.1.title', 'عطلة العيد')->assertJsonPath('data.1.fresh', true);

        // فتحُ الجرس قراءةٌ للإعلانات؛ والردّ يُقرأ في محادثته
        $this->getJson($this->api('/merchant/notifications'), $headers)->assertJsonPath('data.1.fresh', false);
        $this->getJson($this->api('/merchant/home'), $headers)->assertJsonPath('unread', 1);
    }

    public function test_the_greeting_follows_baghdad_time(): void
    {
        $headers = $this->headers();
        Carbon::setTestNow(Carbon::parse('2026-10-10 06:30', 'UTC'));   // ٩:٣٠ صباحاً في بغداد
        $this->getJson($this->api('/merchant/home'), $headers)->assertJsonPath('greeting', 'صباح الخير');
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:30', 'UTC'));   // ١:٣٠ ظهراً في بغداد
        $this->getJson($this->api('/merchant/home'), $headers)->assertJsonPath('greeting', 'مساء الخير');
        Carbon::setTestNow();
    }

    public function test_a_shipment_shows_its_courier_and_last_update(): void
    {
        $shipment = $this->outForDelivery();

        $this->getJson($this->api("/merchant/shipments/{$shipment->id}"), $this->headers())->assertOk()
            ->assertJsonPath('tracking.courier', 'عباس')
            ->assertJsonPath('tracking.status', $shipment->statusLabel());
    }

    public function test_courier_tracking_shows_each_courier_by_the_last_shipment_they_moved(): void
    {
        $shipment = $this->outForDelivery();

        $this->actingAs($this->owner)
            ->get('http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/couriers/tracking')
            ->assertOk()->assertSee('تتبّع المناديب')->assertSee('عباس')->assertSee($shipment->number)
            ->assertSee($shipment->statusLabel());
    }
}

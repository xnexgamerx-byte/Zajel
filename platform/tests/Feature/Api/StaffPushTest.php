<?php

namespace Tests\Feature\Api;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\SendOutForDelivery;
use App\Actions\Shipments\ShipmentTickets;
use App\Actions\Support\Converse;
use App\Actions\Support\CourierChat;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PushDevice;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * إشعارات المندوب والكول سنتر (docs/plan/90): كلّ حدثٍ يصل أصحابه وحدهم، وإرساله بعد الحفظ.
 */
class StaffPushTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private User $merchantUser;

    private User $courierUser;

    private User $callCentre;

    private Courier $courier;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

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
            $this->courierUser = User::create(['name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true]);
            $this->merchantUser = User::create(['name' => 'تاجر', 'phone' => '07790000007', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true]);
            $this->callCentre = User::create(['name' => 'سارة', 'phone' => '07701110011', 'password' => 'password',
                'role' => UserRole::CustomerService, 'is_active' => true]);

            PushDevice::create(['user_id' => $this->courierUser->id, 'token' => 'fcm-courier', 'platform' => 'android']);
            PushDevice::create(['user_id' => $this->callCentre->id, 'token' => 'fcm-callcentre', 'platform' => 'android']);
            PushDevice::create(['user_id' => $this->merchantUser->id, 'token' => 'fcm-merchant', 'platform' => 'android']);
        });

        $this->keyFile = sys_get_temp_dir().'/fcm-staff-'.uniqid().'.json';
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

    /** الرموز التي أُرسل إليها إشعارٌ بهذا العنوان */
    private function sentTo(string $title): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data())
            ->filter(fn ($m) => ($m['message']['notification']['title'] ?? null) === $title)
            ->map(fn ($m) => $m['message']['token'])
            ->values()
            ->all();
    }

    private function shipment(string $name = 'زبون'): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => $name, 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'العنوان', 'cod_amount' => 50_000,
        ], $this->owner));
    }

    public function test_an_assigned_shipment_reaches_only_its_courier(): void
    {
        $shipment = $this->shipment();

        Tenancy::runFor($this->company, fn () => app(SendOutForDelivery::class)
            ->handle(collect([$shipment->refresh()]), $this->courier, $this->owner));

        $this->assertSame(['fcm-courier'], $this->sentTo('شحنات جديدة للتوصيل'));
        Http::assertSent(fn ($r) => ($r['message']['android']['notification']['channel_id'] ?? null) === 'updates');
        $this->assertSame([], $this->sentTo('تذكرة جديدة من مندوب'));
    }

    public function test_a_courier_price_ticket_reaches_the_call_centre_and_not_the_merchant(): void
    {
        $shipment = $this->shipment('منى');
        Tenancy::runFor($this->company, function () use ($shipment) {
            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);
            app(ShipmentTickets::class)->open($shipment->refresh(), $this->courier, $this->courierUser, 'price', 45_000, 'الزبون يقول السعر 45');
        });

        $this->assertSame(['fcm-callcentre'], $this->sentTo('تذكرة جديدة من مندوب'));
    }

    public function test_a_merchant_support_message_reaches_the_call_centre(): void
    {
        Tenancy::runFor($this->company, fn () => app(Converse::class)
            ->start($this->merchant, 'تأخّر الطرد', 'وين الشحنة؟', $this->merchantUser, Converse::MERCHANT));

        $this->assertSame(['fcm-callcentre'], $this->sentTo('رسالة من تاجر: '.$this->merchant->business_name));
    }

    public function test_courier_and_call_centre_messages_reach_the_other_side_only(): void
    {
        Tenancy::runFor($this->company, function () {
            app(CourierChat::class)->send($this->courier, 'الشحنة عند الباب', $this->courierUser, CourierChat::COURIER);
            app(CourierChat::class)->send($this->courier, 'انتظر قليلاً', $this->callCentre, CourierChat::STAFF);
        });

        $this->assertSame(['fcm-callcentre'], $this->sentTo('رسالة من مندوب: أحمد'));
        $this->assertSame(['fcm-courier'], $this->sentTo('رسالة من الكول سنتر'));
    }

    public function test_a_failed_push_does_not_stop_the_work_it_follows(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);
        $shipment = $this->shipment();

        $result = Tenancy::runFor($this->company, fn () => app(SendOutForDelivery::class)
            ->handle(collect([$shipment->refresh()]), $this->courier, $this->owner));

        $this->assertCount(1, $result['moved']);
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->refresh()->status);
    }
}

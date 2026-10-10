<?php

namespace Tests\Feature\Api;

use App\Actions\Returns\HandOverReturns;
use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Support\Converse;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\PickupRequest;
use App\Models\ReturnBatch;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الأدوات السريعة في تطبيق التاجر (docs/plan/56): طلبات الاستلام، و«طلباتي» وإيصالات الراجع،
 * والدعم — بقواعد البوابة نفسها، ولتاجر الحساب وحده.
 */
class MerchantAppToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Merchant $other;

    private User $user;

    private User $staff;

    private Courier $courier;

    private Courier $pickup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        // المراسلة مفتوحة اليوم كلّه: الاختبار لا يتبع ساعة تشغيله (docs/plan/39)
        $this->company->forceFill(['settings' => ['support' => ['merchant_from' => 0, 'merchant_to' => 24]]])->save();
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->other = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
        [$this->user, $this->courier, $this->pickup] = Tenancy::runFor($this->company, function () {
            $pickup = Courier::create(['code' => 'P1', 'name' => 'حيدر الاستلام', 'phone' => '07720000002', 'type' => 'pickup', 'status' => 'active']);
            $this->merchant->forceFill(['pickup_courier_id' => $pickup->id, 'address' => 'المنصور، شارع ١٤ رمضان'])->save();
            $this->other->forceFill(['pickup_courier_id' => $pickup->id])->save();

            return [
                User::create(['name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
                    'merchant_id' => $this->merchant->id, 'is_active' => true]),
                Courier::create(['code' => 'C1', 'name' => 'مندوب', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
                $pickup,
            ];
        });
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

    /** راجعٌ استُلم من المندوب وعلى رفّ فرع تاجره */
    private function onShelf(Merchant $merchant): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 40_000,
            ], $this->staff);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery,
                ShipmentStatus::FailedAttempt, ShipmentStatus::Returning] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->staff, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }
            app(ReceiveReturns::class)->handle([$shipment->id], $this->staff);

            return $shipment->refresh();
        });
    }

    public function test_a_pickup_request_takes_the_account_address_and_only_one_stays_open(): void
    {
        $headers = $this->headers();

        $this->getJson($this->api('/merchant/pickups'), $headers)->assertOk()
            ->assertJsonPath('open', false)->assertJsonPath('address', 'المنصور، شارع ١٤ رمضان')->assertJsonCount(0, 'data');

        $this->postJson($this->api('/merchant/pickups'), ['expected_count' => 0], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('expected_count');
        $this->postJson($this->api('/merchant/pickups'), ['expected_count' => 12, 'scheduled_at' => now()->addDay()->toDateString(),
            'notes' => 'بعد العصر'], $headers)->assertCreated()->assertJsonStructure(['number', 'message']);
        $this->postJson($this->api('/merchant/pickups'), ['expected_count' => 3], $headers)
            ->assertStatus(422)->assertJsonPath('errors.expected_count.0', 'عندك طلب استلام مفتوح. انتظر وصول المندوب، أو اتّصل بالشركة لتعديل عدده.');

        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::sole());
        $this->assertSame('المنصور، شارع ١٤ رمضان', $pickup->address);
        $this->assertSame(12, (int) $pickup->expected_count);

        $this->getJson($this->api('/merchant/pickups'), $headers)->assertOk()
            ->assertJsonPath('open', true)
            ->assertJsonPath('data.0.label', 'بانتظار مندوب')
            ->assertJsonPath('data.0.expected', 12)
            ->assertJsonPath('data.0.notes', 'بعد العصر');
    }

    public function test_requests_returns_and_their_receipts(): void
    {
        $headers = $this->headers();

        $this->postJson($this->api('/merchant/requests'), [], $headers)
            ->assertStatus(422)->assertJsonPath('errors.type.0', 'لا راجع لك عندنا الآن.');

        $mine = $this->onShelf($this->merchant);
        $theirs = $this->onShelf($this->other);
        $this->getJson($this->api('/merchant/requests'), $headers)->assertOk()->assertJsonPath('returning', 1);

        $made = $this->postJson($this->api('/merchant/requests'), ['note' => 'مع مندوب الاستلام'], $headers)->assertCreated();
        $request = Tenancy::runFor($this->company, fn () => MerchantRequest::sole());
        $this->assertSame('returns', $request->type);

        $this->getJson($this->api('/merchant/requests'), $headers)->assertOk()
            ->assertJsonPath('requests.0.number', $made->json('number'))
            ->assertJsonPath('requests.0.label', 'طلب كشف راجع')
            ->assertJsonPath('requests.0.state', 'بانتظار المعالجة');

        // يُلغى مفتوحاً، ولا يُلغى ثانيةً
        $this->postJson($this->api("/merchant/requests/{$request->id}/cancel"), [], $headers)->assertOk();
        $this->postJson($this->api("/merchant/requests/{$request->id}/cancel"), [], $headers)->assertStatus(422);

        // مندوب الاستلام يحمل الراجع بإيصالٍ لكلّ تاجر — والتاجر يؤكّد إيصاله وحده
        Tenancy::runFor($this->company, fn () => app(HandOverReturns::class)->toPickupCourier([$mine->id, $theirs->id], $this->pickup, $this->staff));
        [$a, $b] = Tenancy::runFor($this->company, fn () => [
            ReturnBatch::where('merchant_id', $this->merchant->id)->sole(),
            ReturnBatch::where('merchant_id', $this->other->id)->sole(),
        ]);

        $this->getJson($this->api('/merchant/requests'), $headers)->assertOk()
            ->assertJsonCount(1, 'batches')
            ->assertJsonPath('batches.0.number', $a->number)
            ->assertJsonPath('batches.0.count', 1)
            ->assertJsonPath('batches.0.via', 'مع حيدر الاستلام')
            ->assertJsonPath('batches.0.received', null);

        $this->postJson($this->api("/merchant/returns/{$b->id}/confirm"), [], $headers)->assertNotFound();
        $this->postJson($this->api("/merchant/returns/{$a->id}/confirm"), [], $headers)->assertOk();
        $this->postJson($this->api("/merchant/returns/{$a->id}/confirm"), [], $headers)->assertStatus(422);
        Tenancy::runFor($this->company, fn () => $this->assertSame('التاجر من التطبيق', $a->refresh()->received_by));
    }

    public function test_support_conversations_with_a_reply_a_file_and_the_company_numbers(): void
    {
        Storage::fake('local');
        $this->setFeature($this->company, Feature::Conversations);
        Tenancy::runAsPlatform(fn () => $this->company->forceFill(['settings' => [
            'support' => ['whatsapp' => '07701234567', 'complaints' => '07801112222', 'merchant_from' => 0, 'merchant_to' => 24],
        ]])->save());
        $headers = $this->headers();

        $this->getJson($this->api('/merchant/support'), $headers)->assertOk()
            ->assertJsonPath('complaints', '07801112222')
            ->assertJsonPath('whatsapp', fn ($url) => str_starts_with($url, 'https://wa.me/9647701234567'));

        $id = $this->post($this->api('/merchant/support'), [
            'subject' => 'تأخّر الطرد', 'body' => 'متى يصل؟', 'attachment' => UploadedFile::fake()->image('waybill.jpg'),
        ], $headers)->assertCreated()->json('id');

        // ردّ الموظّف يظهر للتاجر غير مقروء
        Tenancy::runFor($this->company, fn () => app(Converse::class)->reply(Conversation::find($id), 'يصل غداً', $this->staff, Converse::STAFF));
        $this->getJson($this->api('/merchant/support'), $headers)->assertOk()
            ->assertJsonPath('data.0.subject', 'تأخّر الطرد')->assertJsonPath('data.0.unread', true)->assertJsonPath('data.0.staff', true);

        $thread = $this->getJson($this->api("/merchant/support/{$id}"), $headers)->assertOk()
            ->assertJsonPath('messages.0.mine', true)
            ->assertJsonPath('messages.0.file.image', true)
            ->assertJsonPath('messages.1.mine', false)
            ->assertJsonPath('messages.1.body', 'يصل غداً');
        $this->get($thread->json('messages.0.file.url'), $headers)->assertOk();
        $this->getJson($this->api('/merchant/support'), $headers)->assertJsonPath('data.0.unread', false);

        $this->postJson($this->api("/merchant/support/{$id}/reply"), ['body' => 'شكراً'], $headers)->assertCreated();
        $this->postJson($this->api("/merchant/support/{$id}/reply"), [], $headers)->assertStatus(422);

        // محادثة تاجرٍ آخر «غير موجودة»
        $foreign = Tenancy::runFor($this->company, fn () => app(Converse::class)->start($this->other, 'سؤال', 'نص', $this->staff, Converse::STAFF));
        $this->getJson($this->api("/merchant/support/{$foreign->id}"), $headers)->assertNotFound();
    }

    public function test_support_follows_the_conversations_feature(): void
    {
        $this->setFeature($this->company, Feature::Conversations, false);
        $this->getJson($this->api('/merchant/support'), $this->headers())->assertForbidden();
    }
}

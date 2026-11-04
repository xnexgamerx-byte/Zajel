<?php

namespace Tests\Feature\Courier;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المسح بكاميرا الهاتف في شاشة المندوب (docs/plan/119): زرّ «امسح بالكاميرا» بجانب كلّ خانة مسح، وما تكتبه
 * الكاميرا — رقم الباركود أو رابط رمز QR كاملاً — يُقبل كما يُقبل من قارئ الباركود.
 */
class CameraScanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);
        $this->setFeature($this->company, Feature::ScanParcels);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function courier(string $code, string $phone, string $type = 'delivery'): array
    {
        return Tenancy::runFor($this->company, function () use ($code, $phone, $type) {
            $courier = Courier::create(['code' => $code, 'name' => 'مندوب '.$code, 'phone' => $phone, 'type' => $type, 'status' => 'active']);
            $user = User::create([
                'name' => $courier->name, 'phone' => $phone, 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
            ]);

            return [$courier, $user];
        });
    }

    private function created(): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
        ], $this->staff));
    }

    private function outFor(Courier $courier): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier) {
            $shipment = $this->created();
            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::PickedUp, $this->staff);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff,
                ['courier_id' => $courier->id]);

            return $shipment->refresh();
        });
    }

    public function test_the_courier_screens_offer_the_camera_beside_every_scan_field(): void
    {
        [$courier, $user] = $this->courier('C1', '07720000001');
        $shipment = $this->outFor($courier);

        // البحث: الكاميرا تكتب في خانته وتُرسلها، فتُفتح الشحنة
        $this->actingAs($user)->get($this->host().'/courier')->assertOk()
            ->assertSee('data-camera-scan="#courier-search"', false)->assertSee('data-camera-mode="submit"', false);

        // الشحنة: الكاميرا تكتب في خانة المسح قبل التسجيل — وزرّها مخفيٌّ حتى يتأكّد السكربت من الكاميرا
        $this->get($this->host().'/courier/shipments/'.$shipment->id)->assertOk()
            ->assertSee('data-camera-scan="#scan"', false)->assertSee('امسح الوصل بالكاميرا');

        // بلا ميزة المسح لا خانة مسحٍ في الشحنة، فلا كاميرا لها
        $this->setFeature($this->company, Feature::ScanParcels, false);
        $this->get($this->host().'/courier/shipments/'.$shipment->id)->assertOk()->assertDontSee('data-camera-scan="#scan"', false);
    }

    public function test_what_the_camera_reads_from_the_qr_is_accepted_like_the_scanner(): void
    {
        [$courier, $user] = $this->courier('C1', '07720000001');
        $shipment = $this->outFor($courier);
        $link = Tenancy::runFor($this->company, fn () => Tracking::url($shipment));
        $reason = FailureReason::where('code', 'no_answer')->firstOrFail();

        // البحث برابط QR كما تكتبه الكاميرا
        $this->actingAs($user)->get($this->host().'/courier/search?q='.urlencode($link))
            ->assertRedirect($this->host().'/courier/shipments/'.$shipment->id);

        // «لم يُسلَّم» بعد مسح الرابط — والمسح لشحنةٍ أخرى يُرفض
        $other = $this->outFor($courier);
        $this->post($this->host().'/courier/shipments/'.$shipment->id, [
            'action' => 'failed_attempt', 'failure_reason_id' => $reason->id, 'note' => 'لم يرد',
            'scan' => Tenancy::runFor($this->company, fn () => Tracking::url($other)),
        ])->assertSessionHasErrors('scan');
        $this->post($this->host().'/courier/shipments/'.$shipment->id, [
            'action' => 'failed_attempt', 'failure_reason_id' => $reason->id, 'note' => 'لم يرد', 'scan' => $link,
        ])->assertSessionHas('success');

        $this->assertSame(ShipmentStatus::FailedAttempt, $shipment->fresh()->status);
    }

    public function test_the_pickup_courier_scans_parcels_line_by_line_with_the_camera(): void
    {
        [$picker, $user] = $this->courier('P1', '07720000009', 'pickup');
        [$first, $second, $left] = [$this->created(), $this->created(), $this->created()];
        $pickup = Tenancy::runFor($this->company, fn () => PickupRequest::create([
            'merchant_id' => $this->merchant->id, 'courier_id' => $picker->id,
            'number' => 'PU000001', 'status' => 'assigned', 'expected_count' => 3,
        ]));

        $this->actingAs($user)->get($this->host().'/courier/pickups')->assertOk()
            ->assertSee('data-camera-scan="#codes-'.$pickup->id.'"', false)->assertSee('data-camera-mode="lines"', false);

        // سطرٌ لكلّ طرد: رابط QR من الكاميرا، ورقمٌ من الباركود
        $codes = Tenancy::runFor($this->company, fn () => Tracking::url($first))."\n".$second->number;
        $this->post($this->host()."/courier/pickups/{$pickup->id}/complete", ['codes' => $codes])->assertSessionHas('success');

        $this->assertSame(ShipmentStatus::PickedUp, $first->fresh()->status);
        $this->assertSame(ShipmentStatus::PickedUp, $second->fresh()->status);
        $this->assertNotSame(ShipmentStatus::PickedUp, $left->fresh()->status);
    }
}

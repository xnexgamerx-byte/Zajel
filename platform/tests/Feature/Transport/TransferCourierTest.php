<?php

namespace Tests\Feature\Transport;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Transport\BagShipments;
use App\Actions\Transport\RunManifest;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Bag;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المناورة بين الفروع: مندوب نقلٍ من مندوبي الشركة يحمل كشف النقل بين محافظتين، فتُعرف الشحنة
 * معه في الطريق — في سجلّها، وعلى صفحتها، وفي تطبيقه — حتى يستلمها مركز الوصول.
 */
class TransferCourierTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Hub $baghdad;

    private Hub $basra;

    private Courier $carrier;

    private User $carrierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () {
            $this->baghdad = Hub::create(['code' => 'BGD', 'name' => 'مركز بغداد', 'type' => 'main', 'is_active' => true]);
            $this->basra = Hub::create(['code' => 'BSR', 'name' => 'مركز البصرة', 'type' => 'branch', 'is_active' => true]);
            $this->carrier = Courier::create([
                'code' => 'T1', 'name' => 'أبو حيدر', 'phone' => '07730000001', 'type' => 'transfer', 'status' => 'active',
                'vehicle_number' => 'بغداد 12345',
            ]);
            $this->carrierUser = User::create([
                'name' => 'أبو حيدر', 'phone' => '07730000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->carrier->id, 'is_active' => true,
            ]);
            $this->carrier->update(['user_id' => $this->carrierUser->id]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** شحنة في كيسٍ مختوم من بغداد إلى البصرة */
    private function bagged(): array
    {
        return Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 50_000,
            ], $this->staff);
            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->staff, ['hub_id' => $this->baghdad->id]);

            $bags = app(BagShipments::class);
            $bag = $bags->create($this->baghdad, $this->basra, $this->staff);
            $bags->add($bag, [$shipment->number], $this->staff);
            $bags->seal($bag->refresh(), $this->staff);

            return [$shipment->refresh(), $bag->refresh()];
        });
    }

    public function test_a_transfer_courier_carries_the_manifest_and_the_shipment_is_tracked_with_him(): void
    {
        [$shipment, $bag] = $this->bagged();

        // الكشف باسم مندوب النقل: يُختار من مندوبي «نقل بين الفروع»
        $this->actingAs($this->staff)->get($this->host().'/manifests')
            ->assertOk()->assertSee('مندوب النقل بين الفروع')->assertSee('أبو حيدر — 07730000001');

        $this->actingAs($this->staff)->post($this->host().'/manifests', [
            'from_hub_id' => $this->baghdad->id, 'to_hub_id' => $this->basra->id, 'courier_id' => $this->carrier->id,
        ])->assertSessionHasNoErrors();

        $manifest = Tenancy::runFor($this->company, fn () => Manifest::firstOrFail());
        $this->assertSame([$this->carrier->id, 'أبو حيدر', '07730000001', 'بغداد 12345'],
            [(int) $manifest->courier_id, $manifest->driver_name, $manifest->driver_phone, $manifest->vehicle_number]);

        // في تطبيقه: يُحمَّل له
        $this->actingAs($this->carrierUser)->get($this->host().'/courier')
            ->assertOk()->assertSee('تُحمَّل لك')->assertSee($manifest->code);

        Tenancy::runFor($this->company, function () use ($manifest, $bag) {
            app(RunManifest::class)->load($manifest, $bag, $this->staff);
            app(RunManifest::class)->dispatch($manifest->refresh(), $this->staff);
        });

        // سجلّ الشحنة: خرجت معه، والحدث باسمه — ومندوب توصيلها لم يتغيّر
        $fresh = Tenancy::runFor($this->company, fn () => $shipment->refresh());
        $this->assertSame(ShipmentStatus::InTransit, $fresh->status);
        $this->assertNull($fresh->delivery_courier_id);
        $event = Tenancy::runFor($this->company, fn () => $fresh->events()->where('to_status', 'in_transit')->sole());
        $this->assertSame($this->carrier->id, (int) $event->courier_id);
        $this->assertSame("غادرت مع الكشف {$manifest->code} إلى مركز البصرة — مع مندوب النقل أبو حيدر", $event->note);

        // صفحتها: بالطريق بين الفروع ومع مَن
        $this->actingAs($this->staff)->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()->assertSee('بالطريق بين الفروع:')->assertSee('مندوب النقل أبو حيدر')
            ->assertSee('من مركز بغداد إلى مركز البصرة');

        // وفي تطبيقه: بيده في الطريق
        $this->actingAs($this->carrierUser)->get($this->host().'/courier')
            ->assertOk()->assertSee('بيدك في الطريق')->assertSee('مركز بغداد ← مركز البصرة');

        // يستلمه مركز البصرة: يخرج من يده
        Tenancy::runFor($this->company, fn () => app(RunManifest::class)->receive($manifest->refresh(), [$bag->id], $this->staff));

        $this->actingAs($this->staff)->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()->assertDontSee('بالطريق بين الفروع:');
        $this->actingAs($this->carrierUser)->get($this->host().'/courier')
            ->assertOk()->assertSee('سلّمتها اليوم')->assertDontSee('بيدك في الطريق');
    }

    public function test_only_a_transfer_courier_carries_a_manifest_and_an_outside_driver_still_can(): void
    {
        $delivery = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'مندوب توصيل', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
        ]));

        $this->actingAs($this->staff)->post($this->host().'/manifests', [
            'from_hub_id' => $this->baghdad->id, 'to_hub_id' => $this->basra->id, 'courier_id' => $delivery->id,
        ])->assertSessionHasErrors('courier_id');

        // سائقٌ من خارج الشركة بالاسم كما كان
        $this->actingAs($this->staff)->post($this->host().'/manifests', [
            'from_hub_id' => $this->baghdad->id, 'to_hub_id' => $this->basra->id, 'driver_name' => 'سائق الأجرة',
        ])->assertSessionHasNoErrors();

        $manifest = Tenancy::runFor($this->company, fn () => Manifest::firstOrFail());
        $this->assertSame([null, 'السائق سائق الأجرة'], [$manifest->courier_id, $manifest->carrierLabel()]);
    }

    public function test_a_transfer_courier_is_added_from_the_couriers_form(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/couriers', [
            'name' => 'سائق الخط', 'phone' => '07740000002', 'type' => 'transfer', 'vehicle_type' => 'van',
            'cash_limit' => 0, 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $courier = Tenancy::runFor($this->company, fn () => Courier::where('phone', '07740000002')->firstOrFail());
        $this->assertTrue($courier->isTransfer());

        // ولا يظهر بين مندوبي التوصيل ولا الاستلام
        Tenancy::runFor($this->company, function () use ($courier) {
            $this->assertFalse(Courier::delivering()->whereKey($courier->id)->exists());
            $this->assertFalse(Courier::picking()->whereKey($courier->id)->exists());
        });

        $this->actingAs($this->staff)->get($this->host().'/couriers/'.$courier->id)
            ->assertOk()->assertSee('مندوب نقل بين الفروع');
    }
}

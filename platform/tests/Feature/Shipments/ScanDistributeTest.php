<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Governorate;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «توزيع بالمسح» (docs/plan/50): لكلّ محافظةٍ توزيعها، ولكلّ منطقةٍ مندوبها.
 */
class ScanDistributeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $karrada;

    private Courier $mansour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->karrada, $this->mansour] = Tenancy::runFor($this->company, function () {
            $main = Branch::where('code', 'B1')->firstOrFail();
            $main->forceFill(['is_main' => true, 'governorate_id' => $this->baghdad()->id])->save();
            Hub::create(['code' => 'H1', 'name' => 'مخزن بغداد', 'type' => 'main', 'branch_id' => $main->id, 'is_active' => true]);

            $make = fn (string $code, string $name) => Courier::create([
                'code' => $code, 'name' => $name, 'phone' => $this->phoneFrom($code, '0772'),
                'type' => 'delivery', 'status' => 'active', 'branch_id' => $main->id,
            ]);
            $karrada = $make('C1', 'مندوب الكرادة');
            $mansour = $make('C2', 'مندوب المنصور');

            CourierZone::create(['courier_id' => $karrada->id, 'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('الكرادة')]);
            CourierZone::create(['courier_id' => $mansour->id, 'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور')]);

            return [$karrada, $mansour];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(string $area, ?Governorate $governorate = null): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون',
            'recipient_phone' => '07801234567', 'governorate_id' => ($governorate ?? $this->baghdad())->id,
            'city_id' => $this->area($area, $governorate), 'address' => 'عنوان', 'cod_amount' => 25_000,
        ], $this->owner));
    }

    public function test_the_screen_opens_on_the_branch_governorate(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/shipments/distribute')
            ->assertOk()->assertSee('توزيع بالمسح')->assertSee('رقم الوصل — بغداد')->assertSee('مندوب الكرادة');
    }

    public function test_lookup_takes_only_this_governorate_and_suggests_the_area_courier(): void
    {
        $karrada = $this->shipment('الكرادة');
        $basra = Governorate::where('code', 'BSR')->firstOrFail();
        $far = $this->shipment(\App\Models\City::where('governorate_id', $basra->id)->value('name_ar'), $basra);

        $this->actingAs($this->owner)
            ->getJson($this->host().'/shipments/distribute/lookup?governorate='.$this->baghdad()->id.'&number='.$karrada->number)
            ->assertOk()
            ->assertJson(['id' => $karrada->id, 'area' => 'الكرادة', 'suggested' => $this->karrada->id]);

        $this->actingAs($this->owner)
            ->getJson($this->host().'/shipments/distribute/lookup?governorate='.$this->baghdad()->id.'&number='.$far->number)
            ->assertStatus(422)
            ->assertJsonFragment(['error' => "{$far->number} وجهته البصرة — ليس من توزيع هذه المحافظة."]);
    }

    public function test_a_delivered_shipment_is_refused_at_the_scan(): void
    {
        $done = $this->shipment('الكرادة');
        Tenancy::runFor($this->company, fn () => $done->forceFill(['status' => ShipmentStatus::Delivered->value])->save());

        $this->actingAs($this->owner)
            ->getJson($this->host().'/shipments/distribute/lookup?governorate='.$this->baghdad()->id.'&number='.$done->number)
            ->assertStatus(422)->assertJsonFragment(['error' => "{$done->number} حالته «واصل» — لا يُوزَّع."]);
    }

    public function test_each_shipment_goes_out_with_its_area_courier(): void
    {
        $a = $this->shipment('الكرادة');
        $b = $this->shipment('المنصور');

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id,
            'courier' => [$a->id => $this->karrada->id, $b->id => $this->mansour->id],
        ])->assertRedirect()->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($a, $b) {
            $this->assertSame([ShipmentStatus::OutForDelivery, $this->karrada->id], [$a->refresh()->status, $a->delivery_courier_id]);
            $this->assertSame([ShipmentStatus::OutForDelivery, $this->mansour->id], [$b->refresh()->status, $b->delivery_courier_id]);
        });
    }

    public function test_a_shipment_of_another_governorate_is_not_distributed_here(): void
    {
        $basra = Governorate::where('code', 'BSR')->firstOrFail();
        $far = $this->shipment(\App\Models\City::where('governorate_id', $basra->id)->value('name_ar'), $basra);

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id,
            'courier' => [$far->id => $this->karrada->id],
        ])->assertSessionHasErrors('courier');

        $this->assertSame(ShipmentStatus::Created, Tenancy::runFor($this->company, fn () => $far->refresh()->status));
    }

    public function test_an_area_without_a_courier_stops_the_distribution(): void
    {
        $a = $this->shipment('الكرادة');

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id, 'courier' => [$a->id => ''],
        ])->assertSessionHasErrors('courier');
    }
}

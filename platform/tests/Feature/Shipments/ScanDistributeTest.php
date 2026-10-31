<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Branch;
use App\Models\City;
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
 * «توزيع بالمسح» (docs/plan/50): لكلّ منطقةٍ مندوبها. والمحافظات كلّها أو ما يُختار منها (docs/plan/98).
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

    /** docs/plan/98: يفتح على كلّ المحافظات، ويُختار منها واحدةٌ أو أكثر */
    public function test_the_screen_opens_on_every_governorate_or_the_chosen_ones(): void
    {
        $basra = Governorate::where('code', 'BSR')->firstOrFail();

        $this->actingAs($this->owner)->get($this->host().'/shipments/distribute')
            ->assertOk()->assertSee('توزيع بالمسح')->assertSee('رقم الوصل — كل المحافظات')->assertSee('مندوب الكرادة')
            ->assertSee('مندوبٌ واحد لكلّ المناطق')->assertDontSee('خارج المحافظات المختارة');

        $this->actingAs($this->owner)->get($this->host().'/shipments/distribute?governorates[]='.$this->baghdad()->id)
            ->assertOk()->assertSee('رقم الوصل — بغداد');

        $this->actingAs($this->owner)
            ->get($this->host().'/shipments/distribute?governorates[]='.$this->baghdad()->id.'&governorates[]='.$basra->id)
            ->assertOk()->assertSee('رقم الوصل — بغداد، البصرة')
            ->assertSee('name="governorates[]" value="'.$basra->id.'"', false);
    }

    public function test_lookup_takes_the_chosen_governorates_and_suggests_the_area_courier(): void
    {
        $karrada = $this->shipment('الكرادة');
        $basra = Governorate::where('code', 'BSR')->firstOrFail();
        $far = $this->shipment(City::where('governorate_id', $basra->id)->value('name_ar'), $basra);

        $this->actingAs($this->owner)
            ->getJson($this->host().'/shipments/distribute/lookup?governorates='.$this->baghdad()->id.'&number='.$karrada->number)
            ->assertOk()
            ->assertJson(['id' => $karrada->id, 'area' => 'الكرادة', 'governorate' => 'بغداد', 'suggested' => $this->karrada->id]);

        $this->actingAs($this->owner)
            ->getJson($this->host().'/shipments/distribute/lookup?governorates='.$this->baghdad()->id.'&number='.$far->number)
            ->assertStatus(422)
            ->assertJsonFragment(['error' => "{$far->number} وجهته البصرة — خارج المحافظات المختارة."]);

        // بغداد والبصرة معاً، أو بلا اختيار: كلّ المحافظات
        foreach (['governorates='.$this->baghdad()->id.','.$basra->id.'&', ''] as $scope) {
            $this->actingAs($this->owner)
                ->getJson($this->host().'/shipments/distribute/lookup?'.$scope.'number='.$far->number)
                ->assertOk()->assertJson(['id' => $far->id, 'governorate' => 'البصرة']);
        }

        // المنطقة بمحافظتها: لا تختلط منطقتان من محافظتين
        $this->assertNotSame(
            $this->actingAs($this->owner)->getJson($this->host().'/shipments/distribute/lookup?number='.$karrada->number)->json('area_id'),
            $this->actingAs($this->owner)->getJson($this->host().'/shipments/distribute/lookup?number='.$far->number)->json('area_id'),
        );
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

    public function test_a_shipment_outside_the_chosen_governorates_is_not_distributed(): void
    {
        $basra = Governorate::where('code', 'BSR')->firstOrFail();
        $far = $this->shipment(City::where('governorate_id', $basra->id)->value('name_ar'), $basra);

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorates' => [$this->baghdad()->id],
            'courier' => [$far->id => $this->karrada->id],
        ])->assertSessionHasErrors('courier');

        // ورابطٌ قديم بمحافظةٍ واحدة كما كان
        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id,
            'courier' => [$far->id => $this->karrada->id],
        ])->assertSessionHasErrors('courier');

        $this->assertSame(ShipmentStatus::Created, Tenancy::runFor($this->company, fn () => $far->refresh()->status));
    }

    /** docs/plan/98: الشركة الوسيطة توصل لمحافظاتٍ كثيرة — توزيعٌ واحد بلا محافظة، أو بمحافظاتٍ مختارة */
    public function test_one_distribution_sends_shipments_of_many_governorates_to_the_partner(): void
    {
        $basra = Governorate::where('code', 'BSR')->firstOrFail();
        $partner = $this->partner();
        $a = $this->shipment('الكرادة');
        $b = $this->shipment(City::where('governorate_id', $basra->id)->value('name_ar'), $basra);

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'mode' => 'partner', 'courier' => [$a->id => $partner->id, $b->id => $partner->id],
        ])->assertRedirect($this->host().'/shipments/distribute?mode=partner')->assertSessionHas('success');

        $c = $this->shipment('المنصور');
        $d = $this->shipment(City::where('governorate_id', $basra->id)->value('name_ar'), $basra);
        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorates' => [$this->baghdad()->id, $basra->id], 'mode' => 'partner',
            'courier' => [$c->id => $partner->id, $d->id => $partner->id],
        ])->assertRedirect()->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($a, $b, $c, $d, $partner) {
            foreach ([$a, $b, $c, $d] as $s) {
                $s->refresh();
                $this->assertSame([ShipmentStatus::InTransit, $partner->id], [$s->status, $s->delivery_courier_id]);
            }
        });
    }

    public function test_an_area_without_a_courier_stops_the_distribution(): void
    {
        $a = $this->shipment('الكرادة');

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id, 'courier' => [$a->id => ''],
        ])->assertSessionHasErrors('courier');
    }

    /** مندوب شركةٍ أخرى (وسيطة) يُختار في توزيعه وحده، والشحنة تسير إليه بالطريق */
    public function test_partner_mode_hands_the_shipment_to_another_companys_courier(): void
    {
        $a = $this->shipment('الكرادة');
        $partner = $this->partner();

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id, 'mode' => 'partner', 'courier' => [$a->id => $partner->id],
        ])->assertRedirect()->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($a, $partner) {
            $a->refresh();
            $this->assertSame([ShipmentStatus::InTransit, $partner->id], [$a->status, $a->delivery_courier_id]);
        });
    }

    /** كلّ نوعٍ يقبل مناديبه وحده */
    public function test_each_mode_takes_only_its_own_couriers(): void
    {
        $a = $this->shipment('الكرادة');
        $partner = $this->partner();

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id, 'mode' => 'partner', 'courier' => [$a->id => $this->karrada->id],
        ])->assertSessionHasErrors('courier');

        $this->actingAs($this->owner)->post($this->host().'/shipments/distribute', [
            'governorate_id' => $this->baghdad()->id, 'mode' => 'own', 'courier' => [$a->id => $partner->id],
        ])->assertSessionHasErrors('courier');

        $this->assertSame(ShipmentStatus::Created, Tenancy::runFor($this->company, fn () => $a->refresh()->status));
    }

    private function partner(): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'P1', 'name' => 'مندوب شركة الوسيط', 'phone' => $this->phoneFrom('P1', '0773'),
            'type' => 'delivery', 'status' => 'active', 'is_intermediary' => true,
            'branch_id' => Branch::where('code', 'B1')->value('id'),
        ]));
    }

    public function test_the_screen_offers_the_partner_mode_beside_our_couriers(): void
    {
        $this->partner();

        $this->actingAs($this->owner)->get($this->host().'/shipments/distribute')
            ->assertOk()->assertSee('توزيع إلى مندوب شركة أخرى')->assertSee('مندوب شركة الوسيط');
    }
}

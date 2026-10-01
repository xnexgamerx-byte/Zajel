<?php

namespace Tests\Feature\Settlements;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\CitySetting;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\GovernorateSetting;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\User;
use App\Services\PricingService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التسعير بالمركز والأطراف كما في «إعدادات المحافظة» و«المناطق» في المعتاد:
 * مبلغان للمحافظة (المركز والأقضية)، وأجرةٌ خاصّة لمنطقةٍ بعينها، وأجرة
 * المندوب بحسب الوجهة لمن لا أجرة في بطاقته — وما تشحن إليه الشركة وبأيّ ترتيب.
 */
class AreaPricingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private City $karrada;

    private City $taji;

    private City $dora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->karrada, $this->taji, $this->dora] = collect(['الكرادة', 'التاجي', 'الدورة'])
            ->map(fn ($name) => City::firstOrCreate(['governorate_id' => $this->baghdad()->id, 'name_ar' => $name],
                ['name_en' => $name, 'is_active' => true]))
            ->all();

        // بغداد: ٥٠٠٠ للمركز و٧٠٠٠ للأطراف
        Tenancy::runFor($this->company, function () {
            PriceListRule::create([
                'price_list_id' => PriceList::where('is_default', true)->value('id'),
                'to_governorate_id' => $this->baghdad()->id, 'weight_from_grams' => 0, 'weight_to_grams' => 5000,
                'delivery_fee' => 5000, 'peripheral_fee' => 7000, 'return_fee' => 2500, 'priority' => 1, 'is_active' => true,
            ]);

            CitySetting::create(['city_id' => $this->taji->id, 'is_peripheral' => true]);
            CitySetting::create(['city_id' => $this->dora->id, 'delivery_fee' => 9000]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function quote(?City $city, ?Merchant $merchant = null): array
    {
        return Tenancy::runFor($this->company, fn () => app(PricingService::class)
            ->quote(($merchant ?? $this->merchant)->refresh(), $this->baghdad()->id, $city?->id, 1000, 50_000));
    }

    public function test_the_centre_the_outskirts_and_an_area_of_its_own(): void
    {
        $this->assertSame([5000, null], [$this->quote($this->karrada)['delivery_fee'], $this->quote($this->karrada)['zone']]);
        $this->assertSame([7000, 'peripheral'], [$this->quote($this->taji)['delivery_fee'], $this->quote($this->taji)['zone']]);
        $this->assertSame([9000, 'area'], [$this->quote($this->dora)['delivery_fee'], $this->quote($this->dora)['zone']]);
        $this->assertSame(5000, $this->quote(null)['delivery_fee']);

        // والراجع من قاعدة المحافظة كما هو
        $this->assertSame(2500, $this->quote($this->dora)['return_fee']);
    }

    public function test_a_special_price_list_keeps_its_own_prices(): void
    {
        $vip = Tenancy::runFor($this->company, function () {
            $list = PriceList::create(['name' => 'خاصّة', 'is_active' => true]);
            PriceListRule::create(['price_list_id' => $list->id, 'to_governorate_id' => $this->baghdad()->id,
                'weight_from_grams' => 0, 'weight_to_grams' => 5000, 'delivery_fee' => 4000, 'peripheral_fee' => 4500,
                'priority' => 1, 'is_active' => true]);

            $vip = $this->makeMerchant($this->company, 'M0002');
            $vip->update(['price_list_id' => $list->id]);

            return $vip;
        });

        // أجرة المنطقة للتسعيرة العامّة؛ وأطراف تسعيرته منها
        $this->assertSame(4000, $this->quote($this->dora, $vip)['delivery_fee']);
        $this->assertSame(4500, $this->quote($this->taji, $vip)['delivery_fee']);

        // وقاعدةٌ للمنطقة نفسها أخصّ من كل شيء
        Tenancy::runFor($this->company, fn () => PriceListRule::create(['price_list_id' => PriceList::where('is_default', true)->value('id'),
            'to_governorate_id' => $this->baghdad()->id, 'to_city_id' => $this->dora->id, 'weight_from_grams' => 0,
            'weight_to_grams' => 5000, 'delivery_fee' => 11000, 'priority' => 2, 'is_active' => true]));
        $this->assertSame([11000, null], [$this->quote($this->dora)['delivery_fee'], $this->quote($this->dora)['zone']]);
    }

    public function test_the_pricing_grid_saves_an_outskirts_fee_and_blank_means_like_the_centre(): void
    {
        $list = Tenancy::runFor($this->company, fn () => PriceList::where('is_default', true)->firstOrFail());
        $basra = Governorate::where('code', 'BSR')->firstOrFail();

        $this->actingAs($this->owner)->put($this->host().'/pricing/'.$list->id, [
            'name' => $list->name, 'weight_to_grams' => 5000, 'is_default' => 1, 'is_active' => 1,
            'rows' => [
                $this->baghdad()->id => ['delivery_fee' => 5000, 'peripheral_fee' => 8000],
                $basra->id => ['delivery_fee' => 6000, 'peripheral_fee' => ''],
            ],
        ])->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($list, $basra) {
            $rule = fn ($gov) => PriceListRule::where('price_list_id', $list->id)->where('to_governorate_id', $gov)->whereNull('to_city_id')->first();
            $this->assertSame(8000, (int) $rule($this->baghdad()->id)->peripheral_fee);
            $this->assertNull($rule($basra->id)->peripheral_fee);
        });

        $this->actingAs($this->owner)->get($this->host().'/pricing/'.$list->id)->assertSee('للأقضية والأطراف');
    }

    public function test_the_areas_screen_sets_fees_outskirts_and_everything_at_once(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/areas?governorate_id='.$this->baghdad()->id)
            ->assertOk()->assertSee('الكرادة')->assertSee('التاجي')->assertSee('غير مسنودة');

        $this->actingAs($this->owner)->post($this->host().'/areas', [
            'governorate_id' => $this->baghdad()->id,
            'rows' => [
                $this->karrada->id => ['delivery_fee' => '6000', 'is_peripheral' => '0'],
                $this->taji->id    => ['delivery_fee' => '', 'is_peripheral' => '0'],   // يعود كمحافظته
                $this->dora->id    => ['delivery_fee' => '', 'is_peripheral' => '1'],
            ],
        ])->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () {
            $this->assertSame(6000, (int) CitySetting::where('city_id', $this->karrada->id)->value('delivery_fee'));
            $this->assertNull(CitySetting::where('city_id', $this->taji->id)->first());
            $this->assertTrue(CitySetting::where('city_id', $this->dora->id)->value('is_peripheral'));
            $this->assertNull(CitySetting::where('city_id', $this->dora->id)->value('delivery_fee'));
        });

        $this->actingAs($this->owner)->post($this->host().'/areas/peripheral', ['governorate_id' => $this->baghdad()->id, 'mode' => 'all']);
        $this->assertSame(7000, $this->quote($this->taji)['delivery_fee']);
        $this->assertSame(6000, $this->quote($this->karrada)['delivery_fee']); // أجرتها الخاصّة تبقى

        $this->actingAs($this->owner)->post($this->host().'/areas/peripheral', ['governorate_id' => $this->baghdad()->id, 'mode' => 'none']);
        Tenancy::runFor($this->company, function () {
            $this->assertSame(0, CitySetting::where('is_peripheral', true)->count());
            // وما له أجرةٌ خاصّة يبقى صفّه
            $this->assertSame(1, CitySetting::count());
        });

        // لا تُمسّ منطقةٌ من محافظةٍ أخرى بصفحةٍ ليست لها
        $basra = Governorate::where('code', 'BSR')->firstOrFail();
        $this->actingAs($this->owner)->post($this->host().'/areas', [
            'governorate_id' => $basra->id, 'rows' => [$this->taji->id => ['delivery_fee' => '1', 'is_peripheral' => '1']],
        ]);
        Tenancy::runFor($this->company, fn () => $this->assertNull(CitySetting::where('city_id', $this->taji->id)->first()));
    }

    public function test_a_governorate_the_company_does_not_serve_is_neither_offered_nor_accepted(): void
    {
        $basra = Governorate::where('code', 'BSR')->firstOrFail();
        $offers = fn () => $this->actingAs($this->owner)->get($this->host().'/shipments/create')->assertOk()->viewData('governorates');

        $this->assertTrue($offers()->contains('id', $basra->id));

        $this->actingAs($this->owner)->post($this->host().'/governorate-settings', ['rows' => [
            $basra->id           => ['is_active' => '0'],
            $this->baghdad()->id => ['is_active' => '1', 'sort_order' => '0'],
        ]])->assertSessionHasNoErrors();

        $this->assertFalse($offers()->contains('id', $basra->id));
        $this->assertSame('BGD', $offers()->first()->code ?? Governorate::find($offers()->first()->id)->code);

        $this->actingAs($this->owner)->post($this->host().'/shipments', [
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $basra->id, 'city_id' => $this->area('العشار', $basra), 'landmark' => 'قرب الجامع', 'pieces_count' => 1,
            'cod_amount' => 50_000, 'fees_paid_by' => 'merchant',
        ])->assertSessionHasErrors(['governorate_id' => 'شركتك لا تشحن إلى هذه المحافظة الآن.']);

        // وشحنةٌ قائمةٌ إليها تُصحَّح بياناتها ما دامت وجهتها لم تتغيّر
        $existing = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $basra->id, 'address' => 'البصرة', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
        ], $this->owner));
        $this->actingAs($this->owner)->put($this->host().'/shipments/'.$existing->id, [
            'recipient_name' => 'علي حسن', 'recipient_phone' => '07801234567', 'governorate_id' => $basra->id,
            'city_id' => $this->area('العشار', $basra), 'landmark' => 'قرب الجامع', 'pieces_count' => 1, 'cod_amount' => 50_000, 'fees_paid_by' => 'merchant',
        ])->assertSessionHasNoErrors();

        // والشركات الأخرى لا تتأثّر
        $other = $this->makeCompany('other', 'الآخر');
        $ids = Tenancy::runFor($other, fn () => Governorate::offered()->pluck('id')->all());
        $this->assertContains($basra->id, $ids);

        // وترتيبها: بغداد أوّلاً لهذه الشركة
        $first = Tenancy::runFor($this->company, fn () => Governorate::offered()->value('code'));
        $this->assertSame('BGD', $first);
    }

    public function test_a_courier_without_a_rate_is_paid_by_destination(): void
    {
        Tenancy::runFor($this->company, fn () => GovernorateSetting::create([
            'governorate_id' => $this->baghdad()->id, 'courier_fee' => 1500, 'courier_fee_peripheral' => 2500,
        ]));

        $deliver = function (?int $rate, City $city) {
            return Tenancy::runFor($this->company, function () use ($rate, $city) {
                $courier = Courier::create(['code' => 'C'.random_int(100, 999), 'name' => 'مندوب', 'phone' => '0772'.random_int(1000000, 9999999),
                    'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => $rate]);
                $shipment = app(CreateShipment::class)->handle([
                    'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                    'governorate_id' => $this->baghdad()->id, 'city_id' => $city->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع',
                    'cod_amount' => 50_000,
                ], $this->owner);

                foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                    app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                        ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
                }

                return (int) $shipment->refresh()->courier_commission;
            });
        };

        $this->assertSame(1500, $deliver(null, $this->karrada));
        $this->assertSame(2500, $deliver(null, $this->taji));
        $this->assertSame(1000, $deliver(1000, $this->taji)); // أجرته في بطاقته تغلب
        $this->assertSame(0, $deliver(0, $this->karrada));    // وصفرٌ مكتوبٌ صفر
    }

    public function test_the_settings_screens_need_the_pricing_ability(): void
    {
        $agent = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($agent)->get($this->host().'/areas')->assertForbidden();
        $this->actingAs($agent)->get($this->host().'/governorate-settings')->assertForbidden();

        $this->actingAs($this->owner)->get($this->host().'/governorate-settings')
            ->assertOk()->assertSee('إعدادات المحافظات')->assertSee('7,000'); // مبلغ أطراف بغداد من التسعيرة
    }
}

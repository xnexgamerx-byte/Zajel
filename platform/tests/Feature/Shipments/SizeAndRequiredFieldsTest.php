<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Models\City;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ShipmentFields;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حجم الشحنة بتسعيرته، واسم المستلم الاختياريّ، والحقول التي تُلزِم بها الشركة (docs/plan/38).
 */
class SizeAndRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function city(): int
    {
        return (int) City::where('governorate_id', $this->baghdad()->id)->where('is_active', true)->value('id');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'merchant_id' => $this->merchant->id, 'recipient_name' => '', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->city(), 'landmark' => '',
            'cod_amount' => 50_000, 'pieces_count' => 1,
        ];
    }

    private function sizeFees(array $fees): void
    {
        Tenancy::runFor($this->company, fn () => PriceList::query()->update(['size_fees' => json_encode($fees)]));
    }

    public function test_a_bigger_size_adds_its_price_list_fee_to_the_delivery_fee(): void
    {
        $this->sizeFees(['medium' => 1_000, 'large' => 3_000, 'special' => 7_500]);

        $make = fn (string $size) => Tenancy::runFor($this->company, fn () => app(CreateShipment::class)
            ->handle($this->payload(['size' => $size]), $this->owner));

        $normal = $make('normal');
        $this->assertSame(5_000, (int) $normal->delivery_fee);
        $this->assertSame(6_000, (int) $make('medium')->delivery_fee);
        $this->assertSame(8_000, (int) $make('large')->delivery_fee);
        $this->assertSame(12_500, (int) $make('special')->delivery_fee);

        // وتغيير الحجم في التعديل يعيد حساب الأجرة، ومعها مستحقّ التاجر
        // (حقل الأجرة في نموذج التعديل فارغ: الفارغ يُحسب من التسعيرة)
        $updated = Tenancy::runFor($this->company, fn () => app(UpdateShipment::class)->handle(
            $normal, ['size' => 'large', 'delivery_fee' => null] + UpdateShipment::withAmount($normal, 50_000), $this->owner,
        ));
        $this->assertSame(8_000, (int) $updated->delivery_fee);
        $this->assertSame(50_000 - 8_000, (int) $updated->merchant_due);
    }

    public function test_the_price_list_screen_saves_the_size_fees(): void
    {
        $list = Tenancy::runFor($this->company, fn () => PriceList::firstOrFail());

        $this->actingAs($this->owner)->get($this->host()."/pricing/{$list->id}")
            ->assertOk()->assertSee('زيادة الأجرة حسب حجم الشحنة')->assertSee('حجم متوسط');

        $this->actingAs($this->owner)->put($this->host()."/pricing/{$list->id}", [
            'name' => $list->name, 'is_active' => 1, 'weight_to_grams' => 5000,
            'rows' => [$this->baghdad()->id => ['delivery_fee' => 5000]],
            'size_fees' => ['medium' => 1500, 'large' => 4000, 'special' => ''],
        ])->assertSessionHasNoErrors();

        // MySQL يعيد مفاتيح JSON بترتيبه هو: المقارنة بالقيم لا بالترتيب
        $fees = $list->refresh()->size_fees;
        ksort($fees);
        $this->assertSame(['large' => 4000, 'medium' => 1500, 'special' => 0], $fees);
        $this->assertSame(4000, $list->sizeFee('large'));
    }

    public function test_the_name_is_printed_only_when_written_and_the_size_always(): void
    {
        $named = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)
            ->handle($this->payload(['recipient_name' => 'طه محمد', 'size' => 'large']), $this->owner));
        $unnamed = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)
            ->handle($this->payload(), $this->owner));

        $this->actingAs($this->owner)->get($this->host().'/shipments/labels?ids[]='.$named->id)
            ->assertOk()->assertSee('طه محمد')->assertSee('حجم كبير');

        $this->actingAs($this->owner)->get($this->host().'/shipments/labels?ids[]='.$unnamed->id)
            ->assertOk()->assertDontSee('>'.Shipment::UNNAMED_RECIPIENT.'<', false);
    }

    public function test_the_company_chooses_what_is_required_in_every_form(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'primary_color' => '#0F766E', 'shipment_required' => ['recipient_name', 'landmark'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['recipient_name', 'landmark'], ShipmentFields::required($this->company->refresh()));

        // نموذج الموظّف
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->payload())
            ->assertSessionHasErrors([
                'recipient_name' => 'اكتب اسم المستلم: الشركة تُلزِم به.',
                'landmark'       => 'اكتب أقرب نقطة دالّة: الشركة تُلزِم به.',
            ]);

        $this->actingAs($this->owner)->get($this->host().'/shipments/create')->assertSee('تُلزِم الشركة أيضاً');

        // والإدخال السريع
        $this->actingAs($this->owner)->post($this->host().'/shipments/quick', [
            'mode' => 'merchant', 'merchant_id' => $this->merchant->id,
            'rows' => [['amount' => '25', 'recipient_phone' => '07701234567', 'recipient_name' => 'علي',
                'governorate_id' => $this->baghdad()->id, 'city_id' => $this->city(), 'landmark' => '']],
        ])->assertSessionHasErrors(['rows.0.landmark']);

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()));

        // وما لم يُختر يبقى اختيارياً
        $this->actingAs($this->owner)->put($this->host().'/settings/company', ['primary_color' => '#0F766E'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Shipment::count()));
    }
}

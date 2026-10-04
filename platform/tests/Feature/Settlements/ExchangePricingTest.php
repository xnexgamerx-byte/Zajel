<?php

namespace Tests\Feature\Settlements;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\CitySetting;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\Shipment;
use App\Models\User;
use App\Services\PricingService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أجرة «الاستبدال» في التسعيرة (docs/plan/31): طلب الاستبدال — يُسلَّم الجديد ويُستلَم
 * القديم — يُسعَّر بها بدل أجرة التوصيل، ومن غيرها فبأجرة التوصيل كما هي.
 */
class ExchangePricingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private City $taji;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        $this->taji = City::firstOrCreate(['governorate_id' => $this->baghdad()->id, 'name_ar' => 'التاجي'],
            ['name_en' => 'Taji', 'is_active' => true]);

        // بغداد: ٥٠٠٠ توصيلاً، ٧٠٠٠ للأطراف، ٦٥٠٠ للاستبدال. وما سواها بالقاعدة العامّة (٥٠٠٠) بلا أجرة استبدال
        Tenancy::runFor($this->company, function () {
            PriceListRule::create([
                'price_list_id' => PriceList::where('is_default', true)->value('id'),
                'to_governorate_id' => $this->baghdad()->id, 'weight_from_grams' => 0, 'weight_to_grams' => 5000,
                'delivery_fee' => 5000, 'peripheral_fee' => 7000, 'return_fee' => 2500, 'replacement_fee' => 6500,
                'priority' => 1, 'is_active' => true,
            ]);
            CitySetting::create(['city_id' => $this->taji->id, 'is_peripheral' => true]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function basra(): Governorate
    {
        return Governorate::where('code', 'BSR')->firstOrFail();
    }

    private function quote(string $type, ?Governorate $to = null, ?City $city = null): array
    {
        return Tenancy::runFor($this->company, fn () => app(PricingService::class)->quote(
            $this->merchant->refresh(), ($to ?? $this->baghdad())->id, $city?->id, 1000, 50_000, type: $type));
    }

    private function create(array $data = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle($data + [
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'العنوان', 'cod_amount' => 50_000,
        ], $this->owner));
    }

    public function test_an_exchange_is_priced_with_the_exchange_fee(): void
    {
        $this->assertSame([5000, null], [$this->quote('delivery')['delivery_fee'], $this->quote('delivery')['zone']]);
        $this->assertSame([6500, 'exchange'], [$this->quote('exchange')['delivery_fee'], $this->quote('exchange')['zone']]);

        // أجرة الاستبدال لطلب الاستبدال أيّاً كانت منطقته؛ والراجع كما هو
        $this->assertSame(7000, $this->quote('delivery', city: $this->taji)['delivery_fee']);
        $this->assertSame(6500, $this->quote('exchange', city: $this->taji)['delivery_fee']);
        $this->assertSame(2500, $this->quote('exchange')['return_fee']);

        // قاعدةٌ بلا أجرة استبدال: أجرة التوصيل
        $this->assertSame(5000, $this->quote('exchange', $this->basra())['delivery_fee']);
        $this->assertNull($this->quote('exchange', $this->basra())['zone']);
    }

    public function test_a_new_exchange_shipment_carries_the_exchange_fee_into_its_money(): void
    {
        $exchange = $this->create(['type' => 'exchange']);

        $this->assertSame(6500, (int) $exchange->delivery_fee);
        $this->assertSame(6500, (int) $exchange->total_fees);
        $this->assertSame(50_000 - 6500, (int) $exchange->merchant_due);

        // والأجرة المكتوبة يدوياً تغلب كما في كل شحنة
        $this->assertSame(4000, (int) $this->create(['type' => 'exchange', 'delivery_fee' => 4000])->delivery_fee);
        $this->assertSame(5000, (int) $this->create()->delivery_fee);
    }

    public function test_changing_the_type_reprices_and_correcting_a_phone_does_not(): void
    {
        $shipment = $this->create();
        // كما يُرسله نموذج التعديل: خانة الأجرة فارغة (فارغٌ يُبقيها ما لم يتغيّر ما تُسعَّر به)
        $edit = fn (array $changes) => Tenancy::runFor($this->company, fn () => app(UpdateShipment::class)->handle(
            $shipment->refresh(),
            $changes + ['delivery_fee' => null] + UpdateShipment::withAmount($shipment->refresh(), 50_000),
            $this->owner,
        ));

        $this->assertSame(6500, (int) $edit(['type' => 'exchange'])->delivery_fee);
        $this->assertSame(6500, (int) $edit(['recipient_phone' => '07809999999'])->delivery_fee);
        $this->assertSame(5000, (int) $edit(['type' => 'delivery'])->delivery_fee);
    }

    public function test_the_merchant_portal_prices_an_exchange_order(): void
    {
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->post($this->host().'/portal/shipments', [
            'recipient_name' => 'طه محمد', 'recipient_phone' => '07712345678',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور'),
            'pieces_count' => 1, 'cod_amount' => 25_000, 'type' => 'exchange',
        ])->assertSessionHasNoErrors();

        $shipment = Tenancy::runFor($this->company, fn () => Shipment::latest('id')->firstOrFail());
        $this->assertSame('exchange', $shipment->type);
        $this->assertSame(6500, (int) $shipment->delivery_fee);
    }

    public function test_the_price_list_screen_shows_an_unset_exchange_fee_as_like_delivery(): void
    {
        $this->actingAs($this->owner)
            ->get($this->host().'/pricing/'.Tenancy::runFor($this->company, fn () => PriceList::where('is_default', true)->value('id')))
            ->assertOk()
            ->assertSee('placeholder="كالتوصيل"', false)
            ->assertSee('value="6500"', false)
            ->assertSee('فارغاً أو صفراً يُسعَّر بأجرة التوصيل');
    }
}

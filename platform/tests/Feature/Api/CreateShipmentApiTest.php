<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «طلب جديد» في تطبيق التاجر (docs/plan/52): نموذج البوابة نفسه بقواعده وتسعيره.
 */
class CreateShipmentApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
            'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
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

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_the_form_lists_what_the_portal_form_offers(): void
    {
        $this->getJson($this->api('/merchant/shipments/form'), $this->headers())->assertOk()
            ->assertJsonFragment(['id' => $this->baghdad()->id, 'name' => 'بغداد'])
            ->assertJsonFragment(['value' => 'normal', 'label' => 'عادي'])
            ->assertJsonFragment(['value' => 'exchange', 'label' => 'استبدال'])
            ->assertJsonPath('required', []);

        $area = $this->area('المنصور');
        $this->getJson($this->api('/merchant/areas?governorate='.$this->baghdad()->id), $this->headers())->assertOk()
            ->assertJsonFragment(['id' => $area, 'name' => 'المنصور']);
    }

    public function test_the_quote_is_the_price_list_fee_and_what_is_left_for_the_merchant(): void
    {
        $this->getJson($this->api('/merchant/shipments/quote?governorate_id='.$this->baghdad()->id.'&cod_amount=25000'), $this->headers())
            ->assertOk()->assertJson(['delivery_fee' => 5000, 'fees' => 5000, 'due' => 20_000]);
    }

    public function test_a_merchant_creates_a_shipment_typed_as_a_phone_types_it(): void
    {
        $other = $this->makeMerchant($this->company, 'M0002');

        $response = $this->postJson($this->api('/merchant/shipments'), [
            'merchant_id'     => $other->id, // يُهمَل: التاجر من حسابه
            'recipient_name'  => 'طه محمد',
            'recipient_phone' => '٠٧٨٠ ١٢٣ ٤٥٦٧',
            'governorate_id'  => $this->baghdad()->id,
            'city_id'         => $this->area('المنصور'),
            'cod_amount'      => '٢٥٠٠٠',
            'delivery_fee'    => 0, // ولا يسعّر لنفسه
            'notes'           => 'اتصل قبل الوصول',
        ], $this->headers());

        $response->assertCreated()
            ->assertJsonPath('shipment.name', 'طه محمد')
            ->assertJsonPath('shipment.amount', 25_000)
            ->assertJsonPath('shipment.due', 20_000);

        $shipment = Tenancy::runFor($this->company, fn () => Shipment::findOrFail($response->json('shipment.id')));
        $this->assertSame($this->merchant->id, $shipment->merchant_id);
        $this->assertSame('07801234567', $shipment->recipient_phone);
        $this->assertSame(5000, $shipment->delivery_fee);
        $this->assertSame('merchant_app', $shipment->source);
        $this->assertSame(1, $shipment->pieces_count);
        $this->assertSame($shipment->number, $response->json('shipment.number'));
    }

    public function test_mistakes_come_back_by_field_in_arabic(): void
    {
        $this->postJson($this->api('/merchant/shipments'), [
            'recipient_phone' => '0780',
            'governorate_id'  => $this->baghdad()->id,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_phone', 'city_id', 'cod_amount'])
            ->assertJsonPath('errors.city_id.0', 'اختر المنطقة.');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()));
    }

    public function test_only_a_signed_in_merchant_creates(): void
    {
        $this->postJson($this->api('/merchant/shipments'), ['recipient_phone' => '07801234567'])->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الشاشتان عبر HTTP كاملاً: الوسيط، المصادقة، التحقّق، العرض.
 * ما يمرّ هنا يمرّ في المتصفّح.
 */
class ShipmentScreensTest extends TestCase
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
        $this->user = $this->makeUser($this->company);
    }

    /** يحاكي النطاق الفرعي للشركة: zajel.zajel.iq */
    private function host(Company $company): string
    {
        return 'http://'.$company->slug.'.'.config('zajel.tenant_domain');
    }

    private function actingInCompany(?Company $company = null): static
    {
        $company ??= $this->company;

        return $this->actingAs(
            $company->is($this->company) ? $this->user : $this->makeUser($company)
        );
    }

    public function test_the_list_screen_renders_with_shipments(): void
    {
        $shipment = $this->makeShipment();

        $response = $this->actingInCompany()->get($this->host($this->company).'/shipments');

        $response->assertOk()
            ->assertSee($shipment->number)
            ->assertSee('علي حسين')
            ->assertSee('الشحنات');
    }

    public function test_the_list_screen_finds_a_shipment_by_receipt_number(): void
    {
        $wanted = $this->makeShipment(['recipient_name' => 'المطلوب']);
        $other  = $this->makeShipment(['recipient_name' => 'غير المطلوب', 'recipient_phone' => '07709998887']);

        $response = $this->actingInCompany()
            ->get($this->host($this->company).'/shipments?q='.$wanted->number);

        $response->assertOk()
            ->assertSee('المطلوب')
            ->assertDontSee('غير المطلوب');
    }

    public function test_an_unknown_subdomain_is_rejected(): void
    {
        $this->get('http://nope.'.config('zajel.tenant_domain').'/shipments')
            ->assertNotFound();
    }

    public function test_a_suspended_company_cannot_be_reached(): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->update(['status' => 'suspended']));

        $this->actingInCompany()
            ->get($this->host($this->company).'/shipments')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->host($this->company).'/shipments')
            ->assertRedirect($this->host($this->company).'/login');
    }

    public function test_one_company_cannot_open_another_companys_shipment(): void
    {
        $foreign = $this->makeShipment();

        $other = $this->makeCompany('barq', 'البرق');

        $this->actingInCompany($other)
            ->get($this->host($other).'/shipments/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_a_session_from_another_company_is_ended(): void
    {
        $other = $this->makeCompany('barq', 'البرق');

        // مستخدم الزاجل يحاول فتح نظام البرق بجلسته
        $this->actingAs($this->user)
            ->get($this->host($other).'/shipments')
            ->assertRedirect($this->host($other).'/login');

        $this->assertGuest();
    }

    public function test_the_create_screen_renders_the_form(): void
    {
        $this->actingInCompany()
            ->get($this->host($this->company).'/shipments/create')
            ->assertOk()
            ->assertSee('شحنة جديدة')
            ->assertSee('أقرب نقطة دالّة')
            ->assertSee($this->merchant->business_name);
    }

    public function test_creating_a_shipment_without_a_landmark_is_rejected(): void
    {
        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['landmark' => '']))
            ->assertSessionHasErrors('landmark');

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Shipment::count()));
    }

    public function test_creating_a_shipment_with_a_malformed_phone_is_rejected(): void
    {
        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload(['recipient_phone' => '12345']))
            ->assertSessionHasErrors('recipient_phone');
    }

    public function test_a_city_from_a_different_governorate_is_rejected(): void
    {
        $basraCity = \App\Models\City::whereRelation('governorate', 'code', 'BSR')->firstOrFail();

        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload([
                'governorate_id' => $this->baghdad()->id,
                'city_id'        => $basraCity->id,
            ]))
            ->assertSessionHasErrors('city_id');
    }

    public function test_a_merchant_of_another_company_is_rejected(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $foreignMerchant = $this->makeMerchant($other, 'M9001');

        $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload([
                'merchant_id' => $foreignMerchant->id,
            ]))
            ->assertSessionHasErrors('merchant_id');
    }

    public function test_a_valid_shipment_is_created_priced_and_shown(): void
    {
        $response = $this->actingInCompany()
            ->post($this->host($this->company).'/shipments', $this->validPayload());

        $shipment = Tenancy::runFor($this->company, fn () => Shipment::firstOrFail());

        $response->assertRedirect($this->host($this->company).'/shipments/'.$shipment->id)
            ->assertSessionHas('success');

        $this->assertSame('000001', $shipment->number);
        $this->assertSame(ShipmentStatus::Created, $shipment->status);
        $this->assertSame(5000, $shipment->delivery_fee);
        $this->assertSame(45_000, $shipment->merchant_due);   // 50,000 − 5,000
        $this->assertSame($this->user->id, $shipment->created_by_user_id);

        $this->actingInCompany()
            ->get($this->host($this->company).'/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSee('000001')
            ->assertSee('مقابل جامع الشيخ معروف')
            ->assertSee('سجلّ الشحنة');
    }

    public function test_the_quote_endpoint_prices_before_saving(): void
    {
        $this->actingInCompany()
            ->postJson($this->host($this->company).'/quote', [
                'merchant_id'    => $this->merchant->id,
                'governorate_id' => $this->baghdad()->id,
                'weight_grams'   => 1000,
                'cod_amount'     => 50_000,
                'fees_paid_by'   => 'merchant',
            ])
            ->assertOk()
            ->assertJson([
                'delivery_fee' => 5000,
                'total_fees'   => 5000,
                'merchant_due' => 45_000,
                'matched'      => true,
            ]);
    }

    public function test_the_quote_endpoint_refuses_a_merchant_of_another_company(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $foreignMerchant = $this->makeMerchant($other, 'M9001');

        $this->actingInCompany()
            ->postJson($this->host($this->company).'/quote', [
                'merchant_id'    => $foreignMerchant->id,
                'governorate_id' => $this->baghdad()->id,
            ])
            ->assertNotFound();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - الكرادة',
            'landmark'        => 'مقابل جامع الشيخ معروف',
            'pieces_count'    => 1,
            'weight_grams'    => 1000,
            'cod_amount'      => 50_000,
            'fees_paid_by'    => 'merchant',
        ], $overrides);
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle(array_merge([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - الكرادة',
            'landmark'        => 'مقابل جامع الشيخ معروف',
            'cod_amount'      => 50_000,
        ], $overrides), $this->user));
    }
}

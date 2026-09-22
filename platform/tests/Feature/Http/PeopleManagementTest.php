<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeopleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchantPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name'    => 'أزياء الرشيد',
            'owner_name'       => 'سيف الرشيد',
            'phone'            => '07733445566',
            'governorate_id'   => $this->baghdad()->id,
            'address'          => 'بغداد - الكرادة',
            'landmark'         => 'قرب مول بابل',
            'settlement_cycle' => 'weekly',
            'payout_method'    => 'zaincash',
            'payout_account'   => '07733445566',
            'status'           => 'active',
        ], $overrides);
    }

    private function courierPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                    => 'سجاد الموسوي',
            'phone'                   => '07755667788',
            'type'                    => 'pickup',
            'vehicle_type'            => 'van',
            'commission_per_pickup'   => 800,
            'cash_limit'              => 0,
            'status'                  => 'active',
        ], $overrides);
    }

    // ------------------------------------------------------------- التجّار

    public function test_a_merchant_is_created_with_a_generated_code(): void
    {
        $this->actingAs($this->staff)
            ->post($this->host().'/merchants', $this->merchantPayload())
            ->assertSessionHas('success');

        $merchant = Tenancy::runFor($this->company, fn () => Merchant::firstOrFail());

        $this->assertSame('M000001', $merchant->code);
        $this->assertSame('أزياء الرشيد', $merchant->business_name);
        $this->assertSame($this->company->id, $merchant->company_id);
        $this->assertSame($this->staff->id, $merchant->created_by_user_id);
    }

    public function test_merchant_codes_are_sequential_per_company(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchantPayload());
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchantPayload([
            'business_name' => 'متجر ثانٍ', 'phone' => '07733445577',
        ]));

        $codes = Tenancy::runFor($this->company, fn () => Merchant::orderBy('id')->pluck('code')->all());

        $this->assertSame(['M000001', 'M000002'], $codes);
    }

    public function test_a_duplicate_phone_inside_the_company_is_refused(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchantPayload());

        $this->actingAs($this->staff)
            ->post($this->host().'/merchants', $this->merchantPayload(['business_name' => 'متجر آخر']))
            ->assertSessionHasErrors('phone');

        Tenancy::runFor($this->company, fn () => $this->assertSame(1, Merchant::count()));
    }

    public function test_the_same_phone_is_allowed_at_a_different_company(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchantPayload());

        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);
        $otherHost = 'http://'.$other->slug.'.'.config('zajel.tenant_domain');

        $this->actingAs($otherStaff)
            ->post($otherHost.'/merchants', $this->merchantPayload())
            ->assertSessionHasNoErrors();

        Tenancy::runFor($other, fn () => $this->assertSame(1, Merchant::count()));
    }

    public function test_a_malformed_phone_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->post($this->host().'/merchants', $this->merchantPayload(['phone' => '12345']))
            ->assertSessionHasErrors('phone');
    }

    public function test_a_city_outside_the_chosen_governorate_is_refused(): void
    {
        $basraCity = \App\Models\City::whereRelation('governorate', 'code', 'BSR')->firstOrFail();

        $this->actingAs($this->staff)
            ->post($this->host().'/merchants', $this->merchantPayload(['city_id' => $basraCity->id]))
            ->assertSessionHasErrors('city_id');
    }

    public function test_a_login_is_created_for_the_merchant_when_asked(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchantPayload([
            'create_login' => 1, 'password' => 'secret123',
        ]));

        Tenancy::runFor($this->company, function () {
            $merchant = Merchant::firstOrFail();
            $user = User::where('merchant_id', $merchant->id)->first();

            $this->assertNotNull($user);
            $this->assertSame(UserRole::Merchant, $user->role);
            $this->assertSame($merchant->phone, $user->phone);
        });
    }

    public function test_asking_for_a_login_without_a_password_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->post($this->host().'/merchants', $this->merchantPayload(['create_login' => 1]))
            ->assertSessionHasErrors('password');
    }

    public function test_a_merchant_can_be_edited(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchantPayload());
        $merchant = Tenancy::runFor($this->company, fn () => Merchant::firstOrFail());

        $this->actingAs($this->staff)
            ->put($this->host().'/merchants/'.$merchant->id, $this->merchantPayload([
                'business_name' => 'أزياء الرشيد — فرع ثانٍ',
                'status'        => 'suspended',
            ]))
            ->assertSessionHas('success');

        $merchant->refresh();

        $this->assertSame('أزياء الرشيد — فرع ثانٍ', $merchant->business_name);
        $this->assertSame('suspended', $merchant->status);
        $this->assertSame('M000001', $merchant->code);   // الرمز لا يتغيّر
    }

    public function test_another_companys_merchant_is_not_reachable(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $foreign = $this->makeMerchant($other, 'M9001');

        $this->actingAs($this->staff)
            ->get($this->host().'/merchants/'.$foreign->id)
            ->assertNotFound();
    }

    // ---------------------------------------------------------- المندوبون

    public function test_a_courier_is_created_with_zones_and_a_login(): void
    {
        $basra = \App\Models\Governorate::where('code', 'BSR')->firstOrFail();

        $this->actingAs($this->staff)
            ->post($this->host().'/couriers', $this->courierPayload([
                'zones'        => [$this->baghdad()->id, $basra->id],
                'create_login' => 1,
                'password'     => 'secret123',
            ]))
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () {
            $courier = Courier::firstOrFail();

            $this->assertSame('C000001', $courier->code);
            $this->assertSame('pickup', $courier->type);
            $this->assertSame(800, (int) $courier->commission_per_pickup);
            $this->assertSame(2, CourierZone::where('courier_id', $courier->id)->count());

            $this->assertNotNull($courier->user);
            $this->assertSame(UserRole::Courier, $courier->user->role);
        });
    }

    public function test_a_pickup_courier_is_not_offered_for_delivery(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/couriers', $this->courierPayload());

        Tenancy::runFor($this->company, function () {
            $this->assertSame(1, Courier::picking()->count());
            $this->assertSame(0, Courier::delivering()->count());
        });
    }

    public function test_editing_a_courier_replaces_its_zones(): void
    {
        $basra = \App\Models\Governorate::where('code', 'BSR')->firstOrFail();

        $this->actingAs($this->staff)->post($this->host().'/couriers', $this->courierPayload([
            'zones' => [$this->baghdad()->id, $basra->id],
        ]));

        $courier = Tenancy::runFor($this->company, fn () => Courier::firstOrFail());

        $this->actingAs($this->staff)
            ->put($this->host().'/couriers/'.$courier->id, $this->courierPayload([
                'type'  => 'both',
                'zones' => [$basra->id],
            ]))
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($courier, $basra) {
            $zones = CourierZone::where('courier_id', $courier->id)->pluck('governorate_id')->all();

            $this->assertSame([$basra->id], $zones);
            $this->assertSame('both', $courier->fresh()->type);
        });
    }

    public function test_the_lists_render(): void
    {
        $this->actingAs($this->staff)->post($this->host().'/merchants', $this->merchantPayload());
        $this->actingAs($this->staff)->post($this->host().'/couriers', $this->courierPayload());

        $this->actingAs($this->staff)->get($this->host().'/merchants')
            ->assertOk()->assertSee('أزياء الرشيد');

        $this->actingAs($this->staff)->get($this->host().'/couriers')
            ->assertOk()->assertSee('سجاد الموسوي');
    }
}

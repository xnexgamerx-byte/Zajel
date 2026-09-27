<?php

namespace Tests\Feature\Http;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حقول «متاجر الفرع» في المعتاد: نوع البضاعة، والمميّز، والدخول للبوابة،
 * ومندوب الاستلام، وموظّف المبيعات.
 */
class MerchantReferenceFieldsTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'business_name' => $this->merchant->business_name, 'phone' => $this->merchant->phone,
            'governorate_id' => $this->baghdad()->id, 'settlement_cycle' => 'weekly', 'payout_method' => 'cash',
            'status' => 'active',
        ];
    }

    public function test_the_fields_are_saved_shown_and_filtered(): void
    {
        [$agent, $seller] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'مندوب استلام الكرادة', 'phone' => '07720000001', 'type' => 'pickup', 'status' => 'active']),
            User::create(['name' => 'سيف المبيعات', 'phone' => '07720000009', 'password' => 'password',
                'role' => UserRole::Operations, 'is_active' => true, 'is_sales' => true]),
        ]);

        $this->actingAs($this->owner)
            ->put($this->host()."/merchants/{$this->merchant->id}", $this->payload([
                'goods_type' => 'clothing', 'is_vip' => '1', 'portal_access' => '1',
                'pickup_courier_id' => $agent->id, 'sales_user_id' => $seller->id,
            ]))
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($agent, $seller) {
            $m = $this->merchant->refresh();
            $this->assertSame('clothing', $m->goods_type);
            $this->assertTrue($m->is_vip);
            $this->assertSame($agent->id, $m->pickup_courier_id);
            $this->assertSame($seller->id, $m->sales_user_id);
        });

        $this->actingAs($this->owner)->get($this->host()."/merchants/{$this->merchant->id}")
            ->assertOk()->assertSee('عميل مميّز')->assertSee('ملابس')->assertSee('مندوب استلام الكرادة')->assertSee('سيف المبيعات');

        $other = $this->makeMerchant($this->company, 'M0002');
        $this->actingAs($this->owner)->get($this->host().'/merchants?vip=1')
            ->assertSee($this->merchant->business_name)->assertDontSee($other->business_name);
        $this->actingAs($this->owner)->get($this->host().'/merchants?goods_type=clothing')
            ->assertSee($this->merchant->business_name)->assertDontSee($other->business_name);

        // وشحنات المميّزين تُعلَّم وتُفلتر في القائمة
        Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 1000,
        ], $this->owner));
        $this->actingAs($this->owner)->get($this->host().'/shipments?vip=1')
            ->assertSee('إجمالي النتائج: 1')->assertSee('مميّز');
    }

    public function test_a_delivery_courier_or_a_non_sales_user_is_refused(): void
    {
        [$driver, $clerk] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C2', 'name' => 'مندوب توصيل', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active']),
            User::create(['name' => 'موظّف', 'phone' => '07720000008', 'password' => 'password', 'role' => UserRole::Operations, 'is_active' => true]),
        ]);

        $this->actingAs($this->owner)
            ->put($this->host()."/merchants/{$this->merchant->id}", $this->payload([
                'pickup_courier_id' => $driver->id, 'sales_user_id' => $clerk->id, 'goods_type' => 'weapons',
            ]))
            ->assertSessionHasErrors(['pickup_courier_id', 'sales_user_id', 'goods_type']);
    }

    public function test_a_merchant_without_portal_access_is_stopped_at_the_door(): void
    {
        $login = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'username' => 'shop1', 'phone' => '07790000001', 'password' => 'secret123',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($this->owner)
            ->put($this->host()."/merchants/{$this->merchant->id}", $this->payload(['portal_access' => '0']))
            ->assertSessionHasNoErrors();

        auth()->logout();

        $this->post($this->host().'/login', ['username' => 'shop1', 'password' => 'secret123'])
            ->assertSessionHasErrors(['username' => 'لم يُفتح لمتجرك الدخول إلى البوابة. راجع شركة التوصيل.']);
        $this->assertGuest();

        // ومن كانت له جلسةٌ مفتوحة تُغلق البوابة أمامه
        $this->actingAs($login)->get($this->host().'/portal')->assertForbidden();
    }

    public function test_the_usual_pickup_agent_is_suggested_for_a_new_request(): void
    {
        $agent = Tenancy::runFor($this->company, function () {
            $agent = Courier::create(['code' => 'C1', 'name' => 'مندوب الكرادة', 'phone' => '07720000001', 'type' => 'pickup', 'status' => 'active']);
            $this->merchant->forceFill(['pickup_courier_id' => $agent->id])->save();
            PickupRequest::create(['merchant_id' => $this->merchant->id, 'number' => 'PR1', 'status' => 'pending',
                'expected_count' => 3, 'address' => 'الكرادة', 'requested_at' => now()]);

            return $agent;
        });

        $this->actingAs($this->owner)->get($this->host().'/pickups')
            ->assertOk()
            ->assertSee('<option value="'.$agent->id.'"', false)
            ->assertSee('مندوب الكرادة — مندوبه');
    }
}

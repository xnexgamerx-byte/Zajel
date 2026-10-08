<?php

namespace Tests\Feature\Settlements;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البحث والفلترة في محاسبة المندوبين والتجّار: خمسون مندوباً لا يُبحث عن أحدهم
 * بالعين، والكشف يُعرف من حاسبه. القائمة كما هي، والبحث يقصرها.
 */
class SettlementFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function courier(string $code, string $name, string $phone, int $cash): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => $code, 'name' => $name, 'phone' => $phone, 'type' => 'delivery', 'status' => 'active',
            'cash_in_hand' => $cash,
        ]));
    }

    public function test_couriers_are_searched_by_name_code_or_phone_and_by_who_settled(): void
    {
        $ahmed = $this->courier('C1', 'أحمد الساعدي', '07720000001', 50_000);
        $karrar = $this->courier('C2', 'كرار حسين', '07720000002', 30_000);

        $accountant = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'سامر المحاسب', 'phone' => '07701110002', 'password' => 'password',
            'role' => UserRole::Accountant, 'is_active' => true,
        ]));

        Tenancy::runFor($this->company, function () use ($ahmed, $karrar, $accountant) {
            CourierSettlement::create(['courier_id' => $ahmed->id, 'code' => 'CS1', 'status' => 'confirmed',
                'created_by_user_id' => $accountant->id, 'confirmed_by_user_id' => $accountant->id]);
            CourierSettlement::create(['courier_id' => $karrar->id, 'code' => 'CS2', 'status' => 'confirmed',
                'created_by_user_id' => $this->owner->id, 'confirmed_by_user_id' => $this->owner->id]);
        });

        $page = $this->actingAs($this->owner)->get($this->host().'/settlements/couriers')->assertOk();
        $page->assertSee('ابحث عن مندوب')->assertSee('أحمد الساعدي')->assertSee('كرار حسين')->assertSee('CS1')->assertSee('CS2');

        // بالاسم: المنتظر والكشوف معاً
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers?q=كرار')->assertOk()
            ->assertSee('كرار حسين')->assertSee('CS2')
            ->assertDontSee('أحمد الساعدي')->assertDontSee('CS1');

        // بالكود وبالهاتف
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers?q=C1')->assertOk()
            ->assertSee('CS1')->assertDontSee('CS2');
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers?q=0002')->assertOk()
            ->assertSee('CS2')->assertDontSee('CS1');

        // بمن حاسبه
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers?by='.$accountant->id)->assertOk()
            ->assertSee('CS1')->assertDontSee('CS2')->assertSee('سامر المحاسب');

        // بحثٌ لا يطابق أحداً يقول ذلك
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers?q=لا_أحد')->assertOk()
            ->assertSee('لا كشوف بهذا البحث.');
    }

    public function test_merchants_are_searched_and_filtered_by_status(): void
    {
        $first = $this->makeMerchant($this->company, 'M0001');
        $second = $this->makeMerchant($this->company, 'M0002');

        Tenancy::runFor($this->company, function () use ($first, $second) {
            $first->forceFill(['business_name' => 'متجر النخيل', 'balance' => 20_000])->save();
            $second->forceFill(['business_name' => 'بيت العطور', 'phone' => '07711112222', 'balance' => 10_000])->save();

            MerchantSettlement::create(['merchant_id' => $first->id, 'code' => 'MS1', 'status' => 'paid',
                'paid_by_user_id' => $this->owner->id]);
            MerchantSettlement::create(['merchant_id' => $second->id, 'code' => 'MS2', 'status' => 'draft']);
        });

        $this->actingAs($this->owner)->get($this->host().'/settlements/merchants?q=العطور')->assertOk()
            ->assertSee('ابحث عن تاجر')
            ->assertSee('بيت العطور')->assertSee('MS2')
            ->assertDontSee('متجر النخيل')->assertDontSee('MS1');

        $this->actingAs($this->owner)->get($this->host().'/settlements/merchants?status=paid')->assertOk()
            ->assertSee('MS1')->assertDontSee('MS2');
    }
}

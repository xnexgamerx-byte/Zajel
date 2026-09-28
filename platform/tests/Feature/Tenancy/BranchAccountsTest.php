<?php

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\User;
use App\Services\PricingService;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حساب الفرع وتسعيرته.
 *
 * صاحب الشركة يُنشئ الفرع ومعه اسم مستخدمٍ وكلمة مرورٍ لصاحبه، ويختار تسعيرته.
 * و«صاحب الفرع» يعمل بالنظام كلّه في فرعه وحده — يُضيف موظّفيه ومناديبه وتجّاره —
 * ولا يغيّر التسعيرة: يراها ولا يعدّلها، وتسري على تجّار فرعه.
 */
class BranchAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Merchant $mainMerchant;

    private PriceList $basraPrices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->mainMerchant = $this->makeMerchant($this->company, 'M0001');   // الفرع الرئيسي، التسعيرة الافتراضية ٥٬٠٠٠
        $this->owner = $this->makeUser($this->company);

        $this->basraPrices = Tenancy::runFor($this->company, function () {
            $list = PriceList::create(['name' => 'تسعيرة البصرة', 'is_active' => true]);
            PriceListRule::create(['price_list_id' => $list->id, 'weight_from_grams' => 0, 'weight_to_grams' => 5000,
                'delivery_fee' => 7000, 'return_fee' => 3000, 'extra_kg_fee' => 1000, 'is_active' => true]);

            return $list;
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** صاحب الشركة يُنشئ فرع البصرة بتسعيرته وحساب صاحبه، ويُرجع صاحبه */
    private function basra(): User
    {
        $this->actingAs($this->owner)->post($this->host().'/branches', [
            'code' => 'BSR', 'name' => 'فرع البصرة', 'is_active' => '1', 'price_list_id' => $this->basraPrices->id,
            'account_name' => 'صاحب فرع البصرة', 'account_phone' => '07731112222',
            'account_username' => 'basra', 'account_password' => 'secret-pass',
        ])->assertSessionHasNoErrors();

        return Tenancy::runFor($this->company, fn () => User::where('username', 'basra')->firstOrFail());
    }

    private function basraMerchant(array $attributes = []): Merchant
    {
        return Tenancy::runFor($this->company, fn () => Merchant::create($attributes + [
            'code' => 'M0200', 'business_name' => 'متجر البصرة', 'phone' => '07711223344',
            'branch_id' => Branch::where('code', 'BSR')->value('id'), 'status' => 'active',
        ]));
    }

    // ── الإنشاء ─────────────────────────────────────────────────────

    public function test_the_owner_creates_a_branch_with_its_login_and_pricing(): void
    {
        $account = $this->basra();

        Tenancy::runFor($this->company, function () use ($account) {
            $branch = Branch::where('code', 'BSR')->firstOrFail();
            $this->assertSame($this->basraPrices->id, (int) $branch->price_list_id);
            $this->assertSame(UserRole::BranchOwner, $account->role);
            $this->assertSame($branch->id, (int) $account->branch_id);
            $this->assertTrue($account->isBranchLimited());
        });

        // ويدخل صاحبه باسمه وكلمة مروره
        auth()->logout();
        $this->post($this->host().'/login', ['username' => 'basra', 'password' => 'secret-pass'])
            ->assertRedirect($this->host());
        $this->assertAuthenticatedAs($account);
    }

    public function test_a_branch_owner_must_belong_to_a_branch(): void
    {
        // صاحب فرعٍ بلا فرع يرى الشركة كلّها: فرعه شرط
        $this->actingAs($this->owner)->post($this->host().'/users', [
            'name' => 'بلا فرع', 'phone' => '07731119999', 'role' => UserRole::BranchOwner->value, 'password' => 'secret-pass',
        ])->assertSessionHasErrors('branch_id');
    }

    // ── نظام الفرع ──────────────────────────────────────────────────

    public function test_the_branch_owner_runs_everything_in_its_branch_but_not_the_company_settings(): void
    {
        $account = $this->basra();

        foreach (['/merchants', '/couriers', '/users', '/cash', '/expenses', '/settlements/couriers', '/pricing/branch'] as $uri) {
            $this->actingAs($account)->get($this->host().$uri)->assertOk();
        }

        foreach (['/branches', '/pricing', '/areas', '/governorate-settings', '/permissions', '/settings/company'] as $uri) {
            $this->actingAs($account)->get($this->host().$uri)->assertForbidden();
        }

        $abilities = Tenancy::runFor($this->company, fn () => $account->fresh()->abilities());
        $this->assertSame([], array_values(array_intersect($abilities, Ability::COMPANY_WIDE)));
        $this->assertContains(Ability::SETTINGS_USERS, $abilities);
        $this->assertContains(Ability::MONEY_PAY, $abilities);
    }

    public function test_the_branch_owner_adds_its_own_staff_and_nothing_above_it(): void
    {
        $account = $this->basra();
        $staff = fn (array $data) => $this->actingAs($account)->post($this->host().'/users', $data + [
            'name' => 'عمليات البصرة', 'phone' => '07731113333', 'password' => 'secret-pass',
        ]);

        // لفرعه ولو اختار الرئيسي
        $main = Tenancy::runFor($this->company, fn () => Branch::where('code', 'B1')->value('id'));
        $staff(['role' => UserRole::Operations->value, 'branch_id' => $main])->assertSessionHasNoErrors();
        $this->assertSame(
            Tenancy::runFor($this->company, fn () => Branch::where('code', 'BSR')->value('id')),
            Tenancy::runFor($this->company, fn () => (int) User::where('phone', '07731113333')->value('branch_id')),
        );

        // ولا دور يعلوه
        foreach ([UserRole::CompanyOwner, UserRole::CompanyAdmin, UserRole::BranchOwner] as $role) {
            $staff(['role' => $role->value, 'phone' => '07731114444'])->assertSessionHasErrors('role');
        }

        // ولا يفتح حساب صاحب الشركة ولا حسابه هو (كلمة مروره تُغيَّر من الرئيسي)
        $this->actingAs($account)->get($this->host().'/users/'.$this->owner->id.'/edit')->assertNotFound();
        $this->actingAs($account)->get($this->host().'/users/'.$account->id.'/edit')->assertNotFound();

        Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'موظّف الرئيسي', 'phone' => '07731115555', 'password' => 'secret-pass',
            'role' => UserRole::Operations, 'branch_id' => $main, 'is_active' => true,
        ]));
        $this->actingAs($account)->get($this->host().'/users')->assertOk()
            ->assertSee('عمليات البصرة')->assertDontSee('موظّف الرئيسي');
    }

    // ── التسعيرة ────────────────────────────────────────────────────

    public function test_the_branch_pricing_applies_to_its_merchants(): void
    {
        $account = $this->basra();
        $basraMerchant = $this->basraMerchant();

        $fee = fn (Merchant $m) => Tenancy::runFor($this->company, fn () => app(PricingService::class)
            ->quote($m->fresh(), $this->baghdad()->id)['delivery_fee']);

        $this->assertSame(7000, $fee($basraMerchant));        // تسعيرة فرعه
        $this->assertSame(5000, $fee($this->mainMerchant));    // افتراضية الشركة

        // وما خصّ به الرئيسيُّ التاجرَ يغلب تسعيرة الفرع
        Tenancy::runFor($this->company, function () use ($basraMerchant) {
            $list = PriceList::create(['name' => 'خاصّة', 'is_active' => true]);
            PriceListRule::create(['price_list_id' => $list->id, 'weight_from_grams' => 0, 'weight_to_grams' => 5000,
                'delivery_fee' => 4000, 'return_fee' => 2000, 'extra_kg_fee' => 1000, 'is_active' => true]);
            $basraMerchant->forceFill(['price_list_id' => $list->id])->save();
        });
        $this->assertSame(4000, $fee($basraMerchant));

        // والفرع يرى تسعيرته
        $this->actingAs($account)->get($this->host().'/pricing/branch')->assertOk()
            ->assertSee('تسعيرة البصرة')->assertSee('7,000');
    }

    /** الفرع يرى تسعيرته ولا يغيّرها، ولا تسعيرة تاجره */
    public function test_branch_staff_cannot_change_pricing(): void
    {
        $account = $this->basra();
        $cheap = Tenancy::runFor($this->company, fn () => PriceList::create(['name' => 'رخيصة', 'is_active' => true]));

        $this->actingAs($account)->put($this->host().'/pricing/'.$this->basraPrices->id, ['name' => 'مغيَّرة'])->assertForbidden();

        $this->actingAs($account)->post($this->host().'/merchants', [
            'business_name' => 'متجر الزبير', 'phone' => '07711225566', 'price_list_id' => $cheap->id,
            'settlement_cycle' => 'weekly', 'payout_method' => 'cash', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () {
            $this->assertNull(Merchant::where('business_name', 'متجر الزبير')->value('price_list_id'));
            $this->assertSame('تسعيرة البصرة', PriceList::find($this->basraPrices->id)->name);
        });
    }
}

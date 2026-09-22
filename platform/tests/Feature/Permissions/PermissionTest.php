<?php

namespace Tests\Feature\Permissions;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصلاحيات.
 *
 * كانت الأدوار أسماءً بلا أثر: كل موظّف يفتح كل شاشة ويفعل كل شيء. وهذا
 * لا يظهر في اختبار وظيفيّ لأن كل شيء «يعمل» — يعمل أكثر ممّا ينبغي.
 */
class PermissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function staff(UserRole $role, ?array $permissions = null): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name'        => $role->label(),
            'phone'       => $this->phoneFrom('perm'.$role->value),
            'password'    => 'password',
            'role'        => $role,
            'permissions' => $permissions,
            'is_active'   => true,
        ]));
    }

    // ── الافتراضيّات ────────────────────────────────────────────────

    public function test_the_owner_can_do_everything(): void
    {
        $this->assertSame(Ability::all(), $this->owner->abilities());
    }

    public function test_customer_service_reads_and_creates_but_moves_no_money(): void
    {
        $user = $this->staff(UserRole::CustomerService);

        $this->assertTrue($user->hasAbility(Ability::SHIPMENTS_VIEW));
        $this->assertTrue($user->hasAbility(Ability::SHIPMENTS_CREATE));

        foreach ([Ability::MONEY_SETTLE, Ability::MONEY_PAY, Ability::MONEY_CASH,
                  Ability::MONEY_EXPENSES, Ability::CONTROL_FORCE, Ability::SETTINGS_PEOPLE] as $denied) {
            $this->assertFalse($user->hasAbility($denied), $denied.' يجب أن تكون ممنوعة');
        }
    }

    public function test_a_branch_manager_sees_money_but_does_not_move_it(): void
    {
        $user = $this->staff(UserRole::BranchManager);

        $this->assertTrue($user->hasAbility(Ability::MONEY_VIEW));
        $this->assertFalse($user->hasAbility(Ability::MONEY_SETTLE));
        $this->assertFalse($user->hasAbility(Ability::MONEY_CASH));
    }

    // ── المنع في المسار ─────────────────────────────────────────────

    public function test_customer_service_is_blocked_from_the_cash_box(): void
    {
        $this->actingAs($this->staff(UserRole::CustomerService))
            ->get($this->host().'/cash')
            ->assertForbidden();
    }

    public function test_customer_service_cannot_pay_an_expense_by_posting_directly(): void
    {
        // إخفاء الزرّ ليس منعاً: الطلب المباشر يُرفض أيضاً
        $this->actingAs($this->staff(UserRole::CustomerService))
            ->post($this->host().'/expenses', [
                'expense_category_id' => 1, 'amount' => 1000,
                'spent_on' => today()->toDateString(), 'description' => 'محاولة',
            ])
            ->assertForbidden();
    }

    public function test_an_operations_user_cannot_reach_the_settings(): void
    {
        $user = $this->staff(UserRole::Operations);

        $this->actingAs($user)->get($this->host().'/users')->assertForbidden();
        $this->actingAs($user)->get($this->host().'/permissions')->assertForbidden();
        $this->actingAs($user)->get($this->host().'/pricing')->assertForbidden();
    }

    public function test_an_operations_user_still_runs_operations(): void
    {
        $user = $this->staff(UserRole::Operations);

        $this->actingAs($user)->get($this->host().'/shipments')->assertOk();
        $this->actingAs($user)->get($this->host().'/bags')->assertOk();
        $this->actingAs($user)->get($this->host().'/returns')->assertOk();
    }

    public function test_an_accountant_settles_but_does_not_move_parcels(): void
    {
        $user = $this->staff(UserRole::Accountant);

        $this->actingAs($user)->get($this->host().'/cash')->assertOk();
        $this->actingAs($user)->get($this->host().'/expenses')->assertOk();
        $this->actingAs($user)->post($this->host().'/shipments/assign', [
            'shipment_ids' => [1], 'courier_id' => 1,
        ])->assertForbidden();
    }

    // ── التخصيص ─────────────────────────────────────────────────────

    public function test_an_override_replaces_the_role_default_entirely(): void
    {
        $user = $this->staff(UserRole::Accountant, [Ability::SHIPMENTS_VIEW]);

        $this->assertSame([Ability::SHIPMENTS_VIEW], $user->abilities());
        $this->assertFalse($user->hasAbility(Ability::MONEY_CASH));
        $this->assertTrue($user->hasCustomPermissions());
    }

    public function test_an_unknown_ability_in_the_column_is_ignored(): void
    {
        $user = $this->staff(UserRole::Operations, [Ability::SHIPMENTS_VIEW, 'money.print_money']);

        $this->assertSame([Ability::SHIPMENTS_VIEW], $user->abilities());
    }

    public function test_the_owner_grants_and_revokes_from_the_screen(): void
    {
        $user = $this->staff(UserRole::CustomerService);

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$user->id}", [
                'mode' => 'custom',
                'abilities' => [Ability::SHIPMENTS_VIEW, Ability::MONEY_CASH],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($user) {
            $fresh = $user->refresh();
            $this->assertTrue($fresh->hasAbility(Ability::MONEY_CASH));
            $this->assertFalse($fresh->hasAbility(Ability::SHIPMENTS_CREATE));
        });

        $this->actingAs($this->owner)
            ->get($this->host().'/cash')
            ->assertOk();

        $this->actingAs($user->refresh())->get($this->host().'/cash')->assertOk();
    }

    public function test_returning_to_the_role_default_clears_the_override(): void
    {
        $user = $this->staff(UserRole::Operations, [Ability::SHIPMENTS_VIEW]);

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$user->id}", ['mode' => 'role']);

        Tenancy::runFor($this->company, function () use ($user) {
            $fresh = $user->refresh();
            $this->assertFalse($fresh->hasCustomPermissions());
            $this->assertSame(Ability::defaultsFor(UserRole::Operations), $fresh->abilities());
        });
    }

    public function test_the_last_permissions_holder_cannot_lock_everyone_out(): void
    {
        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$this->owner->id}", [
                'mode' => 'custom', 'abilities' => [Ability::SHIPMENTS_VIEW],
            ])
            ->assertSessionHasErrors('abilities');

        Tenancy::runFor($this->company, fn () => $this->assertTrue(
            $this->owner->refresh()->hasAbility(Ability::SETTINGS_PERMISSIONS),
        ));
    }

    public function test_he_may_drop_it_once_someone_else_holds_it(): void
    {
        $admin = $this->staff(UserRole::CompanyAdmin);

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$this->owner->id}", [
                'mode' => 'custom', 'abilities' => [Ability::SHIPMENTS_VIEW],
            ])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($admin) {
            $this->assertFalse($this->owner->refresh()->hasAbility(Ability::SETTINGS_PERMISSIONS));
            $this->assertTrue($admin->refresh()->hasAbility(Ability::SETTINGS_PERMISSIONS));
        });
    }

    // ── الشاشة تتبع الصلاحية ────────────────────────────────────────

    public function test_the_sidebar_hides_what_the_user_cannot_open(): void
    {
        $html = $this->actingAs($this->staff(UserRole::CustomerService))
            ->get($this->host().'/shipments')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('الشحنات', $html);
        $this->assertStringNotContainsString('القاصة', $html);
        $this->assertStringNotContainsString('المصروفات', $html);
        $this->assertStringNotContainsString('الصلاحيات', $html);
    }

    public function test_a_merchant_account_is_not_managed_from_this_screen(): void
    {
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => Merchant::first()->id, 'is_active' => true,
        ]));

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$merchantUser->id}", [
                'mode' => 'custom', 'abilities' => [Ability::MONEY_CASH],
            ])
            ->assertSessionHasErrors('abilities');
    }
}

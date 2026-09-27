<?php

namespace Tests\Feature\Permissions;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Rank;
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
                  Ability::MONEY_EXPENSES, Ability::CONTROL_FORCE, Ability::SETTINGS_USERS,
                  Ability::REPORTS_FINANCIAL] as $denied) {
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

    public function test_the_owner_gives_a_rank_from_the_screen_and_takes_it_back(): void
    {
        $user = $this->staff(UserRole::CustomerService);
        $rank = Tenancy::runFor($this->company, fn () => Rank::create([
            'name' => 'أمين صندوق', 'abilities' => [Ability::SHIPMENTS_VIEW, Ability::MONEY_CASH],
        ]));

        $this->actingAs($user)->get($this->host().'/cash')->assertForbidden();

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$user->id}", ['mode' => 'rank', 'rank_id' => $rank->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($user) {
            $fresh = $user->refresh();
            $this->assertTrue($fresh->hasAbility(Ability::MONEY_CASH));
            // المرتبة تحلّ محلّ افتراضي الدور لا تُضاف إليه
            $this->assertFalse($fresh->hasAbility(Ability::SHIPMENTS_CREATE));
        });

        $this->actingAs($user->refresh())->get($this->host().'/cash')->assertOk();

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$user->id}", ['mode' => 'rank', 'rank_id' => '']);

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            Ability::defaultsFor(UserRole::CustomerService), $user->refresh()->abilities(),
        ));
    }

    public function test_the_owner_takes_no_rank(): void
    {
        $rank = Tenancy::runFor($this->company, fn () => Rank::create(['name' => 'ضيّقة', 'abilities' => [Ability::SHIPMENTS_VIEW]]));

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$this->owner->id}", ['mode' => 'rank', 'rank_id' => $rank->id])
            ->assertSessionHasErrors('rank_id');

        Tenancy::runFor($this->company, fn () => $this->assertSame(Ability::all(), $this->owner->refresh()->abilities()));
    }

    public function test_resetting_clears_the_old_override(): void
    {
        $user = $this->staff(UserRole::Operations, [Ability::SHIPMENTS_VIEW]);

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$user->id}", ['mode' => 'reset'])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($user) {
            $fresh = $user->refresh();
            $this->assertFalse($fresh->hasCustomPermissions());
            $this->assertSame(Ability::defaultsFor(UserRole::Operations), $fresh->abilities());
        });
    }

    public function test_an_old_override_is_saved_as_a_rank_without_changing_what_he_can_do(): void
    {
        $user = $this->staff(UserRole::Operations, [Ability::MONEY_CASH, Ability::SHIPMENTS_VIEW]);

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$user->id}", ['mode' => 'save_as_rank', 'name' => 'صندوق الفرع'])
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($user) {
            $fresh = $user->refresh();
            $this->assertFalse($fresh->hasCustomPermissions());
            $this->assertSame('صندوق الفرع', $fresh->rank->name);
            $this->assertSame([Ability::SHIPMENTS_VIEW, Ability::MONEY_CASH], $fresh->abilities());
        });
    }

    /**
     * صاحب الشركة وحده يملك كل شيء دائماً؛ فالإقفال يأتي من تخصيصٍ قديم
     * نزع عنه «الصلاحيات»، ومرتبةٍ هي آخر من يحملها.
     */
    public function test_the_last_permissions_holder_cannot_lock_everyone_out(): void
    {
        Tenancy::runFor($this->company, fn () => $this->owner->forceFill(['permissions' => [Ability::SHIPMENTS_VIEW]])->save());

        $rank = Tenancy::runFor($this->company, fn () => Rank::create([
            'name' => 'إدارة', 'abilities' => [Ability::SETTINGS_PERMISSIONS, Ability::SHIPMENTS_VIEW],
        ]));
        $admin = $this->staff(UserRole::Operations);
        Tenancy::runFor($this->company, fn () => $admin->forceFill(['rank_id' => $rank->id])->save());

        // المرتبة لا تُنزَع منها «الصلاحيات» وهي آخر من يحملها
        $this->actingAs($admin)
            ->put($this->host()."/permissions/ranks/{$rank->id}", ['name' => 'إدارة', 'abilities' => [Ability::SHIPMENTS_VIEW]])
            ->assertSessionHasErrors('abilities');

        // ولا يُنقل حاملها الوحيد عنها
        $this->actingAs($admin)
            ->post($this->host()."/permissions/{$admin->id}", ['mode' => 'rank', 'rank_id' => ''])
            ->assertSessionHasErrors('rank_id');

        Tenancy::runFor($this->company, fn () => $this->assertTrue(
            $admin->refresh()->hasAbility(Ability::SETTINGS_PERMISSIONS),
        ));
    }

    public function test_the_rank_may_drop_it_once_someone_else_holds_it(): void
    {
        $rank = Tenancy::runFor($this->company, fn () => Rank::create([
            'name' => 'إدارة', 'abilities' => [Ability::SETTINGS_PERMISSIONS],
        ]));

        $this->actingAs($this->owner)
            ->put($this->host()."/permissions/ranks/{$rank->id}", ['name' => 'إدارة', 'abilities' => [Ability::SHIPMENTS_VIEW]])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, fn () => $this->assertSame([Ability::SHIPMENTS_VIEW], $rank->refresh()->abilities));
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
            ->post($this->host()."/permissions/{$merchantUser->id}", ['mode' => 'reset'])
            ->assertSessionHasErrors('abilities');
    }
}

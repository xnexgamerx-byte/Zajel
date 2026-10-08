<?php

namespace Tests\Feature\Permissions;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Rank;
use App\Models\User;
use App\Models\UserGrant;
use App\Support\Permissions\Ability;
use App\Support\Permissions\RankTemplates;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المراتب والصلاحيات الاستثنائية، كما في «إعدادات صلاحيات المستخدم» و«صلاحيات
 * استثنائية» في النظام الذي تعمل عليه الشركات (docs/plan/17 §٥).
 */
class RanksTest extends TestCase
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

    private function host(?Company $company = null): string
    {
        return 'http://'.($company ?? $this->company)->slug.'.'.config('zajel.tenant_domain');
    }

    private function staff(UserRole $role = UserRole::Operations, string $seed = 'a', ?Company $company = null): User
    {
        return Tenancy::runFor($company ?? $this->company, fn () => User::create([
            'name' => 'موظّف '.$seed, 'phone' => $this->phoneFrom('rank'.$seed.$role->value),
            'password' => 'password', 'role' => $role, 'is_active' => true,
        ]));
    }

    private function rank(string $name, array $abilities, ?Company $company = null): Rank
    {
        return Tenancy::runFor($company ?? $this->company, fn () => Rank::create(['name' => $name, 'abilities' => $abilities]));
    }

    // ── القوالب ─────────────────────────────────────────────────────

    public function test_templates_carry_the_familiar_names_and_only_real_abilities(): void
    {
        $templates = RankTemplates::all();
        $names = array_column($templates, 'name');

        foreach (['مدير نظام', 'مدير فرع', 'محاسب رئيسي', 'محاسب عام', 'محاسب', 'متابعة', 'موظّف رواجع',
                  'موظّف إدخال وتحديث طلبات', 'موظّف إدخال ومخزن', 'مسؤول إدخال ومخزن',
                  'موظّف إدخال ومخزن خاص مشاوير', 'موظّف مندوب استلام'] as $familiar) {
            $this->assertContains($familiar, $names);
        }

        $this->assertSame($names, array_unique($names));

        foreach ($templates as $key => $template) {
            $this->assertNotSame([], $template['abilities'], $key);
            $this->assertSame(Ability::ordered($template['abilities']), $template['abilities'], $key);
        }

        $this->assertSame(Ability::all(), RankTemplates::find('system_admin')['abilities']);
    }

    public function test_a_rank_is_created_from_a_template_and_audited(): void
    {
        $this->actingAs($this->owner)
            ->get($this->host().'/permissions/ranks/create?template=returns_clerk')
            ->assertOk()
            ->assertSee('value="موظّف رواجع"', false)
            ->assertSee('name="template" value="returns_clerk"', false);

        $abilities = RankTemplates::find('returns_clerk')['abilities'];

        $this->actingAs($this->owner)
            ->post($this->host().'/permissions/ranks', [
                'name' => 'موظّف رواجع', 'template' => 'returns_clerk', 'abilities' => $abilities,
            ])
            ->assertRedirect($this->host().'/permissions/ranks')
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($abilities) {
            $rank = Rank::sole();
            $this->assertSame($abilities, $rank->abilities);
            $this->assertSame('returns_clerk', $rank->template);
            $this->assertSame($this->owner->id, $rank->created_by_user_id);
            $this->assertTrue(AuditLog::where('action', 'rank_created')->where('auditable_id', $rank->id)->exists());
        });

        $this->actingAs($this->owner)->get($this->host().'/permissions/ranks')
            ->assertOk()->assertSee('موظّف رواجع')->assertSee('لا يحملها أحد');
    }

    public function test_a_rank_name_is_unique_and_abilities_are_from_the_list(): void
    {
        $this->rank('محاسب', [Ability::MONEY_VIEW]);

        $this->actingAs($this->owner)
            ->post($this->host().'/permissions/ranks', ['name' => 'محاسب', 'abilities' => [Ability::MONEY_VIEW]])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->owner)
            ->post($this->host().'/permissions/ranks', ['name' => 'مخترعة', 'abilities' => ['money.print_money']])
            ->assertSessionHasErrors('abilities.0');

        // والاسم نفسه في شركةٍ أخرى لا يصطدم به
        $other = $this->makeCompany('barq', 'البرق');
        $this->rank('محاسب', [Ability::MONEY_VIEW], $other);
        $this->assertSame(2, Rank::acrossCompanies()->where('name', 'محاسب')->count());
    }

    public function test_editing_a_rank_reaches_everyone_holding_it_at_once(): void
    {
        $rank = $this->rank('إدخال', [Ability::SHIPMENTS_VIEW]);
        $clerk = $this->staff();
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['rank_id' => $rank->id])->save());

        $this->actingAs($clerk)->get($this->host().'/bags')->assertForbidden();

        $this->actingAs($this->owner)
            ->get($this->host()."/permissions/ranks/{$rank->id}/edit")
            ->assertOk()
            ->assertSee($clerk->name)
            // الشاشات تحت كل صلاحية، من الشريط نفسه
            ->assertSee('كشوف المناديب · النقل بين الفروع (إرسال واستلام) · أرشيف الكشوف');

        $this->actingAs($this->owner)
            ->put($this->host()."/permissions/ranks/{$rank->id}", [
                'name' => 'إدخال ومخزن', 'abilities' => [Ability::SHIPMENTS_VIEW, Ability::TRANSPORT_MANAGE],
            ])
            ->assertSessionHas('success');

        $this->actingAs($clerk->refresh())->get($this->host().'/bags')->assertOk();

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            ['name' => 'إدخال', 'abilities' => [Ability::SHIPMENTS_VIEW]],
            AuditLog::where('action', 'rank_updated')->sole()->old_values,
        ));
    }

    public function test_a_held_rank_is_not_deleted(): void
    {
        $held = $this->rank('محمولة', [Ability::SHIPMENTS_VIEW]);
        $free = $this->rank('فارغة', [Ability::SHIPMENTS_VIEW]);
        $clerk = $this->staff();
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['rank_id' => $held->id])->save());

        $this->actingAs($this->owner)
            ->delete($this->host()."/permissions/ranks/{$held->id}")
            ->assertSessionHasErrors('rank');

        $this->actingAs($this->owner)
            ->delete($this->host()."/permissions/ranks/{$free->id}")
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, fn () => $this->assertSame(['محمولة'], Rank::pluck('name')->all()));
    }

    public function test_another_companys_rank_is_out_of_reach(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $foreign = $this->rank('غريبة', Ability::all(), $other);
        $clerk = $this->staff();

        $this->actingAs($this->owner)
            ->post($this->host()."/permissions/{$clerk->id}", ['mode' => 'rank', 'rank_id' => $foreign->id])
            ->assertSessionHasErrors('rank_id');

        $this->actingAs($this->owner)->get($this->host()."/permissions/ranks/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($this->owner)->delete($this->host()."/permissions/ranks/{$foreign->id}")->assertNotFound();

        Tenancy::runFor($this->company, fn () => $this->assertNull($clerk->refresh()->rank_id));
    }

    // ── المستخدم ─────────────────────────────────────────────────────

    public function test_the_user_form_assigns_a_rank_but_never_to_the_owner(): void
    {
        $rank = $this->rank('عمليات مسائية', [Ability::SHIPMENTS_VIEW, Ability::SHIPMENTS_STATUS]);
        $clerk = $this->staff();

        $this->actingAs($this->owner)->get($this->host()."/users/{$clerk->id}/edit")
            ->assertOk()->assertSee('عمليات مسائية');

        $payload = fn (User $u, array $extra) => $extra + [
            'name' => $u->name, 'phone' => $u->phone, 'role' => $u->role->value, 'is_active' => 1,
        ];

        $this->actingAs($this->owner)
            ->put($this->host()."/users/{$clerk->id}", $payload($clerk, ['rank_id' => $rank->id]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->put($this->host()."/users/{$this->owner->id}", $payload($this->owner, ['rank_id' => $rank->id]))
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($clerk, $rank) {
            $this->assertSame($rank->id, $clerk->refresh()->rank_id);
            $this->assertNull($this->owner->refresh()->rank_id);
        });

        $this->actingAs($this->owner)->get($this->host().'/users')->assertSee('عمليات مسائية');
    }

    // ── الاستثنائية ───────────────────────────────────────────────────

    public function test_an_exception_adds_one_ability_on_top_of_the_rank_and_is_taken_back(): void
    {
        $clerk = $this->staff(UserRole::CustomerService);

        $this->actingAs($clerk)->get($this->host().'/bags')->assertForbidden();

        $this->actingAs($this->owner)
            ->post($this->host().'/permissions/exceptions', [
                'user_id' => $clerk->id, 'ability' => Ability::TRANSPORT_MANAGE, 'note' => 'يغطّي المخزن هذا الأسبوع',
            ])
            ->assertSessionHas('success');

        $grant = Tenancy::runFor($this->company, fn () => UserGrant::sole());
        $this->assertSame($this->owner->name, $grant->granted_by_name);

        $this->actingAs($clerk->refresh())->get($this->host().'/bags')->assertOk();

        $this->actingAs($this->owner)->get($this->host().'/permissions/exceptions')
            ->assertOk()->assertSee($clerk->name)->assertSee('يغطّي المخزن هذا الأسبوع');

        $this->actingAs($this->owner)->get($this->host().'/permissions')->assertOk()->assertSee('واحدة');

        $this->actingAs($this->owner)
            ->delete($this->host()."/permissions/exceptions/{$grant->id}")
            ->assertSessionHas('success');

        $this->actingAs($clerk->refresh())->get($this->host().'/bags')->assertForbidden();

        Tenancy::runFor($this->company, function () {
            $this->assertSame(['permission_granted', 'permission_revoked'],
                AuditLog::whereIn('action', ['permission_granted', 'permission_revoked'])->orderBy('id')->pluck('action')->all());
        });
    }

    public function test_an_exception_is_not_given_for_what_he_already_has_or_to_the_owner(): void
    {
        $clerk = $this->staff(UserRole::CustomerService);

        $this->actingAs($this->owner)
            ->post($this->host().'/permissions/exceptions', ['user_id' => $clerk->id, 'ability' => Ability::SHIPMENTS_VIEW])
            ->assertSessionHasErrors('ability');

        $this->actingAs($this->owner)
            ->post($this->host().'/permissions/exceptions', ['user_id' => $this->owner->id, 'ability' => Ability::MONEY_CASH])
            ->assertSessionHasErrors('user_id');

        $foreigner = $this->staff(UserRole::Operations, 'b', $this->makeCompany('barq', 'البرق'));

        $this->actingAs($this->owner)
            ->post($this->host().'/permissions/exceptions', ['user_id' => $foreigner->id, 'ability' => Ability::MONEY_CASH])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, UserGrant::acrossCompanies()->count());
    }

    public function test_revoking_the_exception_that_keeps_permissions_alive_is_refused(): void
    {
        Tenancy::runFor($this->company, fn () => $this->owner->forceFill(['permissions' => [Ability::SHIPMENTS_VIEW]])->save());
        $clerk = $this->staff();

        $grant = Tenancy::runFor($this->company, fn () => UserGrant::create([
            'user_id' => $clerk->id, 'ability' => Ability::SETTINGS_PERMISSIONS,
        ]));

        $this->actingAs($clerk)
            ->delete($this->host()."/permissions/exceptions/{$grant->id}")
            ->assertSessionHasErrors('abilities');

        Tenancy::runFor($this->company, fn () => $this->assertTrue(UserGrant::whereKey($grant->id)->exists()));
    }

    // ── الشاشات ─────────────────────────────────────────────────────

    public function test_the_screens_open_for_the_owner_and_not_for_others(): void
    {
        $rank = $this->rank('للعرض', [Ability::SHIPMENTS_VIEW]);
        $accountant = $this->staff(UserRole::Accountant, 'x');

        foreach (['/permissions', '/permissions/ranks', '/permissions/ranks/create',
                  "/permissions/ranks/{$rank->id}/edit", '/permissions/exceptions'] as $path) {
            $this->actingAs($this->owner)->get($this->host().$path)->assertOk();
            $this->actingAs($accountant)->get($this->host().$path)->assertForbidden();
        }
    }

    // ── ما قبل المراتب ──────────────────────────────────────────────

    public function test_old_overrides_keep_what_they_could_open_after_the_split(): void
    {
        $user = $this->staff();
        DB::table('users')->where('id', $user->id)
            ->update(['permissions' => json_encode(['shipments.view', 'settings.people', 'reports.view'])]);

        $migration = require database_path('migrations/2026_01_02_001400_create_ranks_and_user_grants.php');
        $migration->splitLegacyAbilities();
        $migration->splitLegacyAbilities();

        Tenancy::runFor($this->company, fn () => $this->assertSame([
            Ability::SHIPMENTS_VIEW, Ability::REPORTS_VIEW, Ability::REPORTS_FINANCIAL,
            Ability::SETTINGS_MERCHANTS, Ability::SETTINGS_COURIERS, Ability::SETTINGS_USERS,
        ], $user->refresh()->abilities()));
    }
}

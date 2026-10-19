<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Rank;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تقسيم صلاحيات الأرباح (docs/plan/68 §١٣): العرض غير الإعداد غير الاعتماد، والشطب والاستثناء
 * والإعدادات المالية لصاحب الشركة. ومن كان يملك القديمة يملك ما انقسمت إليه بالترحيل.
 */
class ProfitAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    public function test_role_defaults_split_viewing_preparing_and_owner_only_powers(): void
    {
        $accountant = Ability::defaultsFor(UserRole::Accountant);
        foreach (['profits.view', 'batches.prepare', 'batches.approve', 'periods.prepare'] as $ability) {
            $this->assertContains($ability, $accountant);
        }
        foreach (['batches.write_off', 'batches.approve_exception', 'finance.settings', 'periods.close'] as $ability) {
            $this->assertNotContains($ability, $accountant);
            $this->assertContains($ability, Ability::OWNER_ONLY);
        }

        $this->assertContains('finance.settings', Ability::defaultsFor(UserRole::CompanyOwner));
        $this->assertNotContains('money.profits', Ability::all());
        $this->assertSame('إدارة الأرباح', Ability::menuOf('batches.approve'));
    }

    public function test_a_viewer_reads_batches_but_cannot_prepare_or_approve_them(): void
    {
        $viewer = $this->makeUser($this->company, UserRole::Accountant);
        Tenancy::runFor($this->company, function () use ($viewer) {
            $rank = Rank::create(['name' => 'قارئ الأرباح', 'abilities' => ['profits.view', 'shipments.view']]);
            $viewer->forceFill(['rank_id' => $rank->id])->save();
        });

        $this->actingAs($viewer->fresh())->get($this->host().'/profits/batches')->assertOk()->assertDontSee('إنشاء وجبة تحصيل');
        $this->actingAs($viewer->fresh())->get($this->host().'/profits/batches/create')->assertForbidden();
        $this->actingAs($viewer->fresh())->post($this->host().'/profits/batches', [])->assertForbidden();
    }

    public function test_the_migration_gives_holders_of_the_old_abilities_what_they_split_into(): void
    {
        $user = $this->makeUser($this->company, UserRole::Accountant);
        $rank = Tenancy::runFor($this->company, fn () => Rank::create(['name' => 'محاسب أوّل', 'abilities' => ['money.view', 'money.profits', 'money.write_off']]));
        DB::table('user_grants')->insert(['company_id' => $this->company->id, 'user_id' => $user->id, 'ability' => 'money.profits', 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_01_03_006200_split_profit_abilities.php');
        $migration->up();

        $abilities = json_decode((string) DB::table('ranks')->where('id', $rank->id)->value('abilities'), true);
        sort($abilities);
        $this->assertSame(['batches.approve', 'batches.approve_exception', 'batches.prepare', 'batches.write_off', 'money.view', 'profits.view'], $abilities);
        $this->assertSame(['batches.approve', 'batches.prepare', 'profits.view'],
            DB::table('user_grants')->where('user_id', $user->id)->orderBy('ability')->pluck('ability')->all());

        $migration->down();
        $abilities = json_decode((string) DB::table('ranks')->where('id', $rank->id)->value('abilities'), true);
        sort($abilities);
        $this->assertSame(['money.profits', 'money.view', 'money.write_off'], $abilities);
    }
}

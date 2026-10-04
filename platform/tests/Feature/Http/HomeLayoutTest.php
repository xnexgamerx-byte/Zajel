<?php

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Rank;
use App\Models\User;
use App\Services\Dashboard\HomeAlerts;
use App\Support\HomeLayout;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «خصّص الرئيسية»: كل موظّفٍ يختار اختصاراته وأقسام لوحته وقوائم تنبيهاته، ومن يدير المراتب
 * يضع لكل مرتبةٍ رئيسيّتها. والاختصار لا يظهر لمن لا يفتح شاشته.
 */
class HomeLayoutTest extends TestCase
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

    private function staff(array $attributes): User
    {
        return Tenancy::runFor($this->company, fn () => User::create($attributes + [
            'name' => 'سارة المحاسبة', 'phone' => '07701112233', 'password' => 'password',
            'role' => UserRole::Operations, 'is_active' => true,
        ]));
    }

    public function test_by_default_the_home_page_is_whole_and_has_no_shortcuts(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/')
            ->assertOk()
            ->assertSee('خصّص الرئيسية')
            ->assertDontSee('aria-label="اختصاراتي"', false)
            ->assertSee('سُلّمت اليوم')->assertSee('مع المندوبين')->assertSee('نقد بيد المندوبين')
            ->assertSee('شحنات متعثّرة')->assertSee('قيد التنفيذ حسب المحافظة')->assertSee('تجاوزوا سقف النقد');
    }

    /** المحاسب يضع الحسابات المالية أمامه، ويُخفي ما لا يخصّه */
    public function test_an_accountant_pins_the_money_screens_and_hides_the_rest(): void
    {
        $accountant = $this->staff(['permissions' => [Ability::SHIPMENTS_VIEW, Ability::MONEY_VIEW, Ability::MONEY_CASH, Ability::MONEY_SETTLE]]);

        $this->actingAs($accountant)->get($this->host().'/home/customize')
            ->assertOk()
            ->assertSee('value="cash.index"', false)
            ->assertSee('value="settlements.couriers.index"', false)
            ->assertDontSee('value="users.index"', false);   // لا يفتحها فلا تُعرض اختصاراً

        $this->actingAs($accountant)->post($this->host().'/home/customize', [
            'shortcuts' => ['cash.index', 'settlements.couriers.index', 'settlements.merchants.index'],
            'sections'  => ['money', 'alerts'],
            'alerts'    => ['unpaid'],
        ])->assertRedirect($this->host())->assertSessionHas('success', 'حُفظت رئيسيتك.');

        $this->actingAs($accountant)->get($this->host().'/')
            ->assertOk()
            ->assertSee('aria-label="اختصاراتي"', false)
            ->assertSeeInOrder(['الصندوق', 'محاسبة المندوبين', 'محاسبة التجّار'])
            ->assertSee('href="'.$this->host().'/cash"', false)
            ->assertSee('نقد بيد المندوبين')
            ->assertDontSee('سُلّمت اليوم')
            ->assertDontSee('شحنات متعثّرة')
            ->assertDontSee('مع المندوبين');

        $this->assertSame('own', HomeLayout::for($accountant->fresh())['source']);
    }

    /** صاحب الشركة يضع رئيسية «المتابعة»: يراها أصحابها، ومن خصّص رئيسيّته يبقى على تخصيصه */
    public function test_a_rank_gets_a_default_home_and_a_person_may_still_make_their_own(): void
    {
        $rank = Tenancy::runFor($this->company, fn () => Rank::create([
            'name' => 'المتابعة', 'abilities' => [Ability::SHIPMENTS_VIEW, Ability::SHIPMENTS_STATUS, Ability::TICKETS_HANDLE],
        ]));
        $agent = $this->staff(['name' => 'زينب', 'phone' => '07701110001', 'rank_id' => $rank->id]);

        $this->actingAs($this->owner)->get($this->host().'/home/customize?rank='.$rank->id)
            ->assertOk()->assertSee('رئيسية أصحاب مرتبة «المتابعة»')
            ->assertSee('value="processing.index"', false)
            ->assertDontSee('value="cash.index"', false);   // ليس في صلاحيات المرتبة

        $this->actingAs($this->owner)->post($this->host().'/home/customize', [
            'rank'      => $rank->id,
            // «المستخدمون» ليست من صلاحيات المرتبة: لا يراها صاحبها ولو حُفظت
            'shortcuts' => ['processing.index', 'tickets.index', 'users.index'],
            'sections'  => ['where', 'stuck'],
            'alerts'    => [],
        ])->assertSessionHas('success');

        $home = HomeLayout::for($agent->fresh());
        $this->assertSame('rank', $home['source']);
        $this->assertSame(['processing.index', 'tickets.index'], array_column($home['shortcuts'], 'id'));
        $this->assertSame(['where' => true, 'stuck' => true], $home['sections']);

        $this->actingAs($agent->fresh())->get($this->host().'/')
            ->assertOk()->assertSee('شحنات لم تُسلَّم (للمعالجة)')->assertSee('شحنات متعثّرة')->assertDontSee('نقد بيد المندوبين');

        // تخصيصه يغلب مرتبته، و«رجوع للافتراضي» يعيده إليها
        $this->actingAs($agent->fresh())->post($this->host().'/home/customize', ['sections' => ['today'], 'shortcuts' => ['tickets.index']]);
        $this->assertSame(['tickets.index'], array_column(HomeLayout::for($agent->fresh())['shortcuts'], 'id'));

        $this->actingAs($agent->fresh())->post($this->host().'/home/customize', ['reset' => 1])
            ->assertSessionHas('success', 'عادت رئيسيتك إلى الافتراضي.');
        $this->assertSame('rank', HomeLayout::for($agent->fresh())['source']);

        // ولا يخصّص رئيسية مرتبةٍ من لا يديرها
        $this->actingAs($agent->fresh())->get($this->host().'/home/customize?rank='.$rank->id)->assertForbidden();
        $this->actingAs($agent->fresh())->post($this->host().'/home/customize', ['rank' => $rank->id, 'sections' => []])->assertForbidden();
    }

    public function test_only_known_sections_are_saved_and_only_chosen_alerts_are_computed(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/home/customize', ['sections' => ['money', 'evil']])
            ->assertSessionHasErrors('sections.1');

        $this->actingAs($this->owner)->post($this->host().'/home/customize', [
            'sections' => ['alerts'], 'alerts' => ['unpaid', 'manifests'], 'shortcuts' => ['no.such.route', 'cash.index'],
        ])->assertSessionHasNoErrors();

        $owner = $this->owner->fresh();
        $this->assertSame(['sections' => ['alerts'], 'alerts' => ['unpaid', 'manifests'], 'shortcuts' => ['cash.index']], $owner->home_layout);

        $keys = Tenancy::runFor($this->company, fn () => array_column(app(HomeAlerts::class)->for($owner, ['unpaid', 'manifests']), 'key'));
        $this->assertSame(['unpaid', 'manifests'], $keys);
    }
}

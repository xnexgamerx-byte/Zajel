<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserGrant;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحت قائمة الشحنات: «إجمالي المبالغ» و«إجمالي التوصيل» لما في البحث كلّه (docs/plan/60) —
 * يُفلتَر تاجرٌ فيُعرف كم له بالمجمل. للرئيسي والفرع والمحاسب، ولمن يُمنح بعينه.
 */
class ShipmentListTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () {
            foreach ([[$this->alpha, 31_000], [$this->alpha, 19_000], [$this->beta, 70_000]] as [$merchant, $amount]) {
                app(CreateShipment::class)->handle([
                    'merchant_id' => $merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                    'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => $amount,
                ], $this->owner);
            }
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function fees(Merchant $merchant): int
    {
        return (int) Tenancy::runFor($this->company, fn () => Shipment::where('merchant_id', $merchant->id)->sum('total_fees'));
    }

    public function test_a_merchant_filter_shows_that_merchants_totals_across_every_page(): void
    {
        $fees = $this->fees($this->alpha);
        $this->assertGreaterThan(0, $fees);

        $this->actingAs($this->owner)->get($this->host().'/shipments?merchant_id='.$this->alpha->id)->assertOk()
            ->assertSee('data-shipment-sums', false)
            ->assertSeeInOrder(['إجمالي المبالغ:', '50,000', 'إجمالي التوصيل:', number_format($fees),
                'الصافي بعد التوصيل:', number_format(50_000 - $fees)]);
    }

    public function test_accountants_and_branch_managers_see_it_and_others_only_when_granted(): void
    {
        foreach ([UserRole::Accountant, UserRole::BranchManager] as $role) {
            $this->assertContains(Ability::SHIPMENTS_TOTALS, Ability::defaultsFor($role), $role->value);
        }

        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)->get($this->host().'/shipments')->assertOk()->assertDontSee('data-shipment-sums', false);

        Tenancy::runFor($this->company, fn () => UserGrant::create([
            'user_id' => $agent->id, 'ability' => Ability::SHIPMENTS_TOTALS, 'granted_by_user_id' => $this->owner->id,
        ]));

        $this->actingAs($agent->refresh())->get($this->host().'/shipments')->assertOk()
            ->assertSee('data-shipment-sums', false)->assertSee('120,000');
    }
}

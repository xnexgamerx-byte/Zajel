<?php

namespace Tests\Feature\Money;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «اني متفق وياه على عمولة ٣٥٠٠، وهو متفق مع المندوب ٢٠٠٠… المفروض يصفاله ١٥٠٠ من كل طلب،
 * اريدها تخصم وتضاف للفرع مستحقاته» (docs/plan/51).
 */
class BranchCommissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Branch $main;

    private Branch $kut;

    private Courier $kutCourier;

    private Courier $mainCourier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company); // تاجرٌ من بغداد (الرئيسي)
        $this->owner = $this->makeUser($this->company);

        [$this->main, $this->kut, $this->kutCourier, $this->mainCourier] = Tenancy::runFor($this->company, function () {
            $main = Branch::where('code', 'B1')->firstOrFail();
            $kut = Branch::create(['code' => 'KUT', 'name' => 'فرع الكوت', 'commission_per_delivery' => 3500]);

            return [
                $main,
                $kut,
                Courier::create(['code' => 'K1', 'name' => 'مندوب الكوت', 'phone' => '07720000011', 'type' => 'delivery',
                    'status' => 'active', 'commission_per_delivery' => 2000, 'branch_id' => $kut->id]),
                Courier::create(['code' => 'B1C', 'name' => 'مندوب بغداد', 'phone' => '07720000012', 'type' => 'delivery',
                    'status' => 'active', 'commission_per_delivery' => 1000, 'branch_id' => $main->id]),
            ];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(Courier $courier, int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier, $cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'الكوت', 'landmark' => 'قرب الجسر', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    public function test_a_branch_earns_its_commission_on_every_order_its_couriers_deliver(): void
    {
        $shipment = $this->deliver($this->kutCourier);

        $this->assertSame($this->kut->id, $shipment->delivery_branch_id);
        $this->assertSame(3500, $shipment->branch_commission);
        $this->assertSame(2000, $shipment->courier_commission);

        // مندوب الرئيسي: الشركة نفسها، لا عمولة فرع
        $own = $this->deliver($this->mainCourier);
        $this->assertSame($this->main->id, $own->delivery_branch_id);
        $this->assertSame(0, $own->branch_commission);

        // ونسبةٌ تتغيّر غداً لا تمسّ ما سُلِّم
        Tenancy::runFor($this->company, fn () => $this->kut->update(['commission_per_delivery' => 4000]));
        $this->assertSame(3500, Tenancy::runFor($this->company, fn () => $shipment->refresh()->branch_commission));
    }

    public function test_the_branch_keeps_its_commission_from_what_it_owes_and_its_profit_shows(): void
    {
        $this->deliver($this->kutCourier, 50_000);
        $this->deliver($this->kutCourier, 30_000);

        // الكوت جمع ٨٠ ألفاً لتاجرٍ من بغداد: عليه ٨٠ ناقصاً عمولته ٧٠٠٠ = ٧٣ ألفاً
        $pairs = $this->actingAs($this->owner)->get($this->host().'/branch-accounts/debts')->assertOk()
            ->assertSee('فرع الكوت')->assertSee('73,000')->viewData('pairs');
        $this->assertSame([$this->kut->id, $this->main->id], [(int) $pairs[0]->debtor, (int) $pairs[0]->creditor]);
        $this->assertSame([80_000, 7_000, 73_000, 73_000], [(int) $pairs[0]->collected, (int) $pairs[0]->commission, (int) $pairs[0]->amount, $pairs[0]->remaining]);

        // ربح الفرع: ٣٥٠٠ − ٢٠٠٠ = ١٥٠٠ عن كلّ طلب
        $page = $this->actingAs($this->owner)->get($this->host().'/branch-accounts')->assertOk()
            ->assertSee('عمولة الفروع وأرباحها');
        $earned = $page->viewData('earned')[$this->kut->id];
        $this->assertSame([2, 7_000, 4_000], [(int) $earned->shipments, (int) $earned->earned, (int) $earned->couriers]);
        $page->assertSeeInOrder(['data-branch-profit', 'فرع الكوت', '7,000', '4,000', '3,000'], false);

        // وعند الشركة: تُخصم عمولة الفرع لا عمولة مندوبه
        $totals = $this->actingAs($this->owner)->get($this->host().'/reports/profit')->assertOk()->viewData('totals');
        $this->assertSame(7_000, $totals->commission);
    }

    public function test_branch_staff_see_their_own_commission_and_dues(): void
    {
        $this->deliver($this->kutCourier, 50_000);

        $manager = $this->makeUser($this->company, \App\Enums\UserRole::BranchOwner);
        Tenancy::runFor($this->company, fn () => $manager->update(['branch_id' => $this->kut->id]));

        $this->actingAs($manager->refresh())->get($this->host().'/branch-accounts')->assertOk()
            ->assertSee('فرع الكوت')->assertSee('1,500');
        $this->actingAs($manager)->get($this->host().'/branch-accounts/debts')->assertOk()
            ->assertSee('46,500');
    }

    public function test_the_owner_sets_the_commission_and_can_apply_it_to_past_deliveries(): void
    {
        Tenancy::runFor($this->company, fn () => $this->kut->update(['commission_per_delivery' => 0]));
        $before = $this->deliver($this->kutCourier);
        $this->assertSame(0, $before->branch_commission);

        $this->actingAs($this->owner)->put($this->host().'/branches/'.$this->kut->id, [
            'code' => 'KUT', 'name' => 'فرع الكوت', 'is_active' => 1,
            'commission_per_delivery' => 3500, 'apply_from' => now()->subDay()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(3500, Tenancy::runFor($this->company, fn () => $this->kut->refresh()->commission_per_delivery));
        $this->assertSame(3500, Tenancy::runFor($this->company, fn () => $before->refresh()->branch_commission));

        $this->actingAs($this->owner)->get($this->host().'/branches')->assertOk()->assertSee('3,500 / طلب');
        $this->actingAs($this->owner)->get($this->host().'/branches/'.$this->kut->id.'/edit')->assertOk()
            ->assertSee('عمولة الفرع عن كلّ طلبٍ واصل');
    }
}

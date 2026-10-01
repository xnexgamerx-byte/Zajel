<?php

namespace Tests\Feature\Shipments;

use App\Actions\Returns\HandOverReturns;
use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «الشحنات المؤرشفة»: ما رجع إلى تاجره وسُلّم له يخرج من قائمة الشحنات
 * الجارية إلى أرشيفه — لكل تاجرٍ قائمته، عند الشركة وفي بوابة التاجر.
 */
class ShipmentArchiveTest extends TestCase
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
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(Merchant $merchant, array $state = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $state) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area(), 'cod_amount' => 40_000,
            ], $this->owner);

            $shipment->forceFill($state + ['status_changed_at' => now()])->save();

            return $shipment->refresh();
        });
    }

    /** راجعٌ استُلم من المندوب ثم سُلّم لتاجره بإيصال — كما يجري فعلاً */
    private function returnedToMerchant(Merchant $merchant, string $when = 'now'): Shipment
    {
        $shipment = $this->shipment($merchant, ['status' => 'returning', 'return_received_at' => now()]);

        $this->travelTo(now()->modify($when));
        Tenancy::runFor($this->company, fn () => app(HandOverReturns::class)->handle([$shipment->id], $merchant, $this->owner));
        $this->travelBack();

        return Tenancy::runFor($this->company, fn () => $shipment->refresh());
    }

    public function test_returned_shipments_leave_the_live_list_for_the_archive(): void
    {
        $returned = $this->returnedToMerchant($this->alpha);
        $live = $this->shipment($this->alpha, ['status' => 'at_hub']);
        $this->assertSame('returned', $returned->status->value);

        $this->actingAs($this->owner)->get($this->host().'/shipments')->assertOk()
            ->assertSee($live->number)->assertDontSee($returned->number)
            ->assertSee('راجعة للتاجر (مؤرشفة)')
            ->assertSee('href="'.route('shipments.archive').'"', false);

        // ويُعثر عليه برقمه، أو بحالته صراحةً
        $this->actingAs($this->owner)->get($this->host().'/shipments?q='.$returned->number)->assertOk()->assertSee($returned->number);
        $this->actingAs($this->owner)->get($this->host().'/shipments?status=returned')->assertOk()
            ->assertSee($returned->number)->assertDontSee($live->number);
    }

    public function test_the_archive_lists_merchants_and_opens_each_one_list_in_place(): void
    {
        $older = $this->returnedToMerchant($this->alpha, '-3 days');
        $newer = $this->returnedToMerchant($this->alpha);
        $betas = $this->returnedToMerchant($this->beta, '-1 day');
        $this->shipment($this->alpha, ['status' => 'returning', 'return_received_at' => now()]); // على الرفّ: لم يُسلَّم

        // التجّار بعدد رواجعهم المسلَّمة، الأحدث تسليماً أوّلاً
        $page = $this->actingAs($this->owner)->get($this->host().'/shipments/archive')->assertOk();
        $html = $page->getContent();
        $page->assertSee($this->alpha->business_name)->assertSee($this->beta->business_name)->assertSee('شحنتان');
        $this->assertLessThan(strpos($html, $this->beta->business_name), strpos($html, $this->alpha->business_name));

        // قائمة التاجر: رواجعه المسلَّمة وحدها، الأحدث أوّلاً، بإيصال دفعتها
        $list = $this->actingAs($this->owner)->get($this->host().'/shipments/archive?merchant_id='.$this->alpha->id)->assertOk();
        $html = $list->getContent();
        $list->assertSee($older->number)->assertSee($newer->number)->assertDontSee($betas->number)
            ->assertSee('إجمالي النتائج: 2')
            ->assertSee(Tenancy::runFor($this->company, fn () => $newer->returnBatch->number));
        $this->assertLessThan(strpos($html, $older->number), strpos($html, $newer->number), 'الأحدث تسليماً أوّلاً');

        // البحث برقم الوصل وبتاريخ التسليم
        $this->actingAs($this->owner)->get($this->host().'/shipments/archive?merchant_id='.$this->alpha->id.'&q='.$older->number)
            ->assertOk()->assertSee('إجمالي النتائج: 1')->assertSee($older->number);
        $this->actingAs($this->owner)->get($this->host().'/shipments/archive?merchant_id='.$this->alpha->id.'&from='.now()->toDateString())
            ->assertOk()->assertSee('إجمالي النتائج: 1')->assertSee($newer->number);

        // البحث في التجّار باسم المتجر
        $this->actingAs($this->owner)->get($this->host().'/shipments/archive?q='.urlencode($this->beta->business_name))
            ->assertOk()->assertSee($this->beta->business_name)->assertDontSee('merchant_id='.$this->alpha->id, false);
    }

    public function test_a_branch_sees_only_its_own_merchants_archive(): void
    {
        $basra = Tenancy::runFor($this->company, fn () => Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']));
        Tenancy::runFor($this->company, fn () => $this->beta->forceFill(['branch_id' => $basra->id])->save());

        $this->returnedToMerchant($this->alpha);
        $theirs = $this->returnedToMerchant($this->beta);

        $clerk = $this->makeUser($this->company, UserRole::Operations);
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['branch_id' => $basra->id])->save());

        $this->actingAs($clerk)->get($this->host().'/shipments/archive')->assertOk()
            ->assertSee($this->beta->business_name)->assertDontSee($this->alpha->business_name);
        $this->actingAs($clerk)->get($this->host().'/shipments/archive?merchant_id='.$this->beta->id)->assertOk()->assertSee($theirs->number);
        $this->actingAs($clerk)->get($this->host().'/shipments/archive?merchant_id='.$this->alpha->id)->assertNotFound();
    }

    public function test_the_merchant_portal_has_its_own_archive_tab(): void
    {
        $returned = $this->returnedToMerchant($this->alpha);
        $live = $this->shipment($this->alpha, ['status' => 'out_for_delivery']);
        $betas = $this->returnedToMerchant($this->beta);

        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->get($this->host().'/portal/shipments')->assertOk()
            ->assertSee($live->number)->assertDontSee($returned->number)
            ->assertSee('المؤرشفة — راجعٌ سُلّم لك');

        $this->actingAs($user)->get($this->host().'/portal/shipments?tab=archive')->assertOk()
            ->assertSee($returned->number)->assertDontSee($live->number)->assertDontSee($betas->number)
            ->assertSee(Tenancy::runFor($this->company, fn () => $returned->returnBatch->number));
    }
}

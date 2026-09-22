<?php

namespace Tests\Feature\Returns;

use App\Actions\Returns\HandOverReturns;
use App\Actions\Returns\ReceiveReturns;
use App\Actions\Returns\SortReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Transport\BagShipments;
use App\Actions\Transport\RunManifest;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * فرز الراجع للفروع — الرحلة كلّها بين فرعين.
 *
 * تاجرٌ من البصرة، وزبونه في بغداد رفض الطلب. الراجع يصل رفّ بغداد،
 * وتاجره لن يأتي إلى بغداد. فيجب أن يُعرَف أنه في غير مكانه، وأن يُكيَّس
 * إلى البصرة، وأن يعبر راجعاً ويصل راجعاً، وأن يظهر هناك جاهزاً للتسليم
 * — وألّا يُسلَّم في بغداد لتاجرٍ ليس هناك.
 */
class ReturnSortingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $baghdadBranch;

    private Branch $basraBranch;

    private Hub $baghdadHub;

    private Hub $basraHub;

    private Merchant $basraMerchant;

    private Merchant $baghdadMerchant;

    /** موظّف يقف في بغداد: الراجع يُمسح عنده */
    private User $baghdadClerk;

    private User $basraClerk;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');

        // makeMerchant يُنشئ فرع B1 — نجعله بغداد ونضيف البصرة
        $this->baghdadMerchant = $this->makeMerchant($this->company, 'M0001');

        Tenancy::runFor($this->company, function () {
            $this->baghdadBranch = Branch::where('code', 'B1')->firstOrFail();
            $this->basraBranch = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true]);

            $this->baghdadHub = Hub::create(['code' => 'BGD', 'name' => 'مركز بغداد', 'type' => 'main',
                'branch_id' => $this->baghdadBranch->id, 'is_active' => true]);
            $this->basraHub = Hub::create(['code' => 'BSR', 'name' => 'مركز البصرة', 'type' => 'branch',
                'branch_id' => $this->basraBranch->id, 'is_active' => true]);

            $this->basraMerchant = Merchant::create([
                'code' => 'M0002', 'business_name' => 'متجر البصرة',
                'phone' => '07711112222', 'branch_id' => $this->basraBranch->id,
                'price_list_id' => $this->baghdadMerchant->price_list_id, 'status' => 'active',
            ]);

            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'مندوب بغداد', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'branch_id' => $this->baghdadBranch->id,
                'commission_per_delivery' => 1500, 'commission_per_return' => 750,
            ]);
        });

        $this->baghdadClerk = $this->clerk('07790000001', $this->baghdadBranch);
        $this->basraClerk = $this->clerk('07790000002', $this->basraBranch);
    }

    private function clerk(string $phone, Branch $branch): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'موظّف '.$branch->name, 'phone' => $phone, 'password' => 'password',
            'role' => UserRole::Operations, 'branch_id' => $branch->id, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** راجعٌ رفضه زبونه، ومسحه موظّف بغداد عند العدّاد. */
    private function returnReceivedInBaghdad(Merchant $merchant): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي حسين',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد — الكرادة', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->baghdadClerk);

            $change = app(ChangeShipmentStatus::class);
            $reason = FailureReason::where('code', 'no_answer')->firstOrFail();

            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->baghdadClerk);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->baghdadClerk, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::FailedAttempt, $this->baghdadClerk, ['failure_reason_id' => $reason->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Returning, $this->baghdadClerk);

            app(ReceiveReturns::class)->handle([$shipment->id], $this->baghdadClerk);

            return $shipment->refresh();
        });
    }

    // ── المكان ──────────────────────────────────────────────────────

    public function test_a_return_is_located_where_it_was_scanned_in(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        $this->assertSame($this->baghdadHub->id, (int) $shipment->hub_id);
    }

    public function test_a_return_on_a_foreign_shelf_is_listed_for_its_branch_not_for_handover(): void
    {
        $away = $this->returnReceivedInBaghdad($this->basraMerchant);
        $home = $this->returnReceivedInBaghdad($this->baghdadMerchant);

        Tenancy::runFor($this->company, function () use ($away, $home) {
            $misplaced = app(SortReturns::class)->misplaced();
            $ready = app(HandOverReturns::class)->ready();

            $this->assertSame([$away->id], $misplaced->get($this->basraBranch->id)?->pluck('id')->all());
            $this->assertSame([$home->id], $ready->pluck('id')->all());
        });
    }

    /** الشاشتان تقتسمان الرواجع المستلَمة: كلٌّ في واحدة لا في كلتيهما ولا في غيرهما. */
    public function test_every_received_return_is_in_exactly_one_of_the_two_screens(): void
    {
        $shipments = collect([
            $this->returnReceivedInBaghdad($this->basraMerchant),
            $this->returnReceivedInBaghdad($this->basraMerchant),
            $this->returnReceivedInBaghdad($this->baghdadMerchant),
        ]);

        Tenancy::runFor($this->company, function () use ($shipments) {
            $misplaced = app(SortReturns::class)->misplaced()->flatten()->pluck('id');
            $ready = app(HandOverReturns::class)->ready()->pluck('id');

            $this->assertEmpty($misplaced->intersect($ready));
            $this->assertEqualsCanonicalizing($shipments->pluck('id')->all(), $misplaced->merge($ready)->all());
        });
    }

    /** شركةٌ بفرعٍ واحد لا تعرف مراكز: راجعها في فرعها بالضرورة. */
    public function test_an_unknown_location_counts_as_here(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        Tenancy::runFor($this->company, function () use ($shipment) {
            Shipment::whereKey($shipment->id)->update(['hub_id' => null]);

            $this->assertTrue(app(SortReturns::class)->misplaced()->isEmpty());
            $this->assertContains($shipment->id, app(HandOverReturns::class)->ready()->pluck('id'));
        });
    }

    // ── التسليم ─────────────────────────────────────────────────────

    public function test_a_return_on_a_foreign_shelf_cannot_be_handed_over_there(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        Tenancy::runFor($this->company, function () use ($shipment) {
            try {
                app(HandOverReturns::class)->handle([$shipment->id], $this->basraMerchant, $this->baghdadClerk);
                $this->fail('راجعٌ على رفّ بغداد سُلِّم لتاجر البصرة.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('تُفرَز إليه', collect($e->errors())->flatten()->implode(' '));
                $this->assertSame(ShipmentStatus::Returning, $shipment->refresh()->status);
            }
        });
    }

    // ── الفرز ───────────────────────────────────────────────────────

    public function test_sorting_bags_the_return_from_where_it_sits_to_its_merchants_branch(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $bag = app(SortReturns::class)->bagFor($this->basraBranch, [$shipment->id], $this->baghdadClerk);

            $this->assertSame($this->baghdadHub->id, (int) $bag->from_hub_id);
            $this->assertSame($this->basraHub->id, (int) $bag->to_hub_id);
            $this->assertSame($bag->id, (int) $shipment->refresh()->current_bag_id);
            $this->assertSame(ShipmentStatus::Returning, $shipment->status);

            // خرج من «يُفرَز» إلى «في الطريق»
            $this->assertTrue(app(SortReturns::class)->misplaced()->isEmpty());
            $this->assertSame([$shipment->id], app(SortReturns::class)->onTheWay()->pluck('id')->all());
        });
    }

    public function test_a_return_bound_elsewhere_is_refused_from_a_branchs_bag(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->expectException(ValidationException::class);

            // تاجره في البصرة، فلا يُكيَّس لبغداد
            app(SortReturns::class)->bagFor($this->baghdadBranch, [$shipment->id], $this->baghdadClerk);
        });
    }

    public function test_a_branch_without_a_hub_cannot_be_sorted_to(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->basraHub->update(['is_active' => false]);

            try {
                app(SortReturns::class)->bagFor($this->basraBranch, [$shipment->id], $this->baghdadClerk);
                $this->fail('فُرز راجعٌ إلى فرعٍ بلا مركز مفعّل.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('لا مركز مفعّل', collect($e->errors())->flatten()->implode(' '));
                $this->assertNull($shipment->refresh()->current_bag_id);
            }
        });
    }

    // ── الرحلة كلّها ────────────────────────────────────────────────

    public function test_the_whole_journey_ends_with_the_return_handed_over_in_its_own_branch(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $bags = app(BagShipments::class);
            $runner = app(RunManifest::class);

            $bag = app(SortReturns::class)->bagFor($this->basraBranch, [$shipment->id], $this->baghdadClerk);
            $bags->seal($bag->refresh(), $this->baghdadClerk);

            $manifest = $runner->create($this->baghdadHub, $this->basraHub, [], $this->baghdadClerk);
            $runner->load($manifest, $bag->refresh(), $this->baghdadClerk);
            $runner->dispatch($manifest->refresh(), $this->baghdadClerk);

            // في الطريق: راجعٌ لا «قيد النقل»
            $this->assertSame(ShipmentStatus::Returning, $shipment->refresh()->status);

            $runner->receive($manifest->refresh(), [$bag->id], $this->basraClerk);
            $bags->open($bag->refresh(), $this->basraClerk);

            $fresh = $shipment->refresh();
            $this->assertSame(ShipmentStatus::Returning, $fresh->status);
            $this->assertSame($this->basraHub->id, (int) $fresh->hub_id);
            $this->assertContains($shipment->id, app(HandOverReturns::class)->ready()->pluck('id'));

            app(HandOverReturns::class)->handle([$shipment->id], $this->basraMerchant, $this->basraClerk);

            $this->assertSame(ShipmentStatus::Returned, $shipment->refresh()->status);
        });
    }

    // ── الشاشة ──────────────────────────────────────────────────────

    public function test_staff_sort_from_the_screen_and_land_on_the_new_bag(): void
    {
        $shipment = $this->returnReceivedInBaghdad($this->basraMerchant);

        $this->actingAs($this->baghdadClerk)
            ->get($this->host().'/returns/sorting')
            ->assertOk()
            ->assertSee($shipment->number)
            ->assertSee('إلى فرع البصرة');

        $response = $this->actingAs($this->baghdadClerk)
            ->post($this->host().'/returns/sorting', [
                'branch_id' => $this->basraBranch->id, 'shipment_ids' => [$shipment->id],
            ]);

        $bagId = (int) $shipment->refresh()->current_bag_id;
        $this->assertNotSame(0, $bagId);
        $response->assertRedirect(route('bags.show', $bagId));
    }

    public function test_a_merchant_login_cannot_reach_the_sorting_screen(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000009', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->basraMerchant->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->get($this->host().'/returns/sorting')->assertForbidden();
    }
}

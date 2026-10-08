<?php

namespace Tests\Feature\Transport;

use App\Actions\Returns\HandOverReturns;
use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ReceiveAtHub;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «النقل بين الفروع» بخطوةٍ واحدة، والراجع لا يعود شحنةً جديدة (docs/plan/38).
 *
 * كان الراجع المستلَم من فرعٍ آخر يُستلم في الفرع بتغيير حالته إلى «بالمخزن» — الانتقال
 * الوحيد الذي عرضته صفحته — فيعود شحنةً تنتظر مندوب توصيل إلى الزبون الذي رفضها.
 */
class BranchTransferTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $baghdadBranch;

    private Branch $basraBranch;

    private Hub $baghdadHub;

    private Hub $basraHub;

    private Merchant $baghdadMerchant;

    private Merchant $basraMerchant;

    private User $baghdadClerk;

    private User $basraClerk;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->baghdadMerchant = $this->makeMerchant($this->company, 'M0001');

        Tenancy::runFor($this->company, function () {
            $this->baghdadBranch = Branch::where('code', 'B1')->firstOrFail();
            $this->baghdadBranch->forceFill(['governorate_id' => $this->baghdad()->id])->save();
            $this->basraBranch = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true,
                'governorate_id' => $this->basra()->id]);

            $this->baghdadHub = Hub::create(['code' => 'BGD', 'name' => 'مركز بغداد', 'type' => 'main',
                'branch_id' => $this->baghdadBranch->id, 'governorate_id' => $this->baghdad()->id, 'is_active' => true]);
            $this->basraHub = Hub::create(['code' => 'BSR', 'name' => 'مركز البصرة', 'type' => 'branch',
                'branch_id' => $this->basraBranch->id, 'governorate_id' => $this->basra()->id, 'is_active' => true]);

            $this->basraMerchant = Merchant::create([
                'code' => 'M0002', 'business_name' => 'متجر البصرة',
                'phone' => '07711112222', 'branch_id' => $this->basraBranch->id,
                'price_list_id' => $this->baghdadMerchant->price_list_id, 'status' => 'active',
            ]);

            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'مندوب بغداد', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'branch_id' => $this->baghdadBranch->id,
            ]);
        });

        $this->baghdadClerk = $this->clerk('07790000001', $this->baghdadBranch);
        $this->basraClerk = $this->clerk('07790000002', $this->basraBranch);
    }

    private function basra(): Governorate
    {
        return Governorate::where('code', 'BSR')->firstOrFail();
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

    /** شحنة تاجرٍ في بغداد على رفّ بغداد، إلى محافظة $governorate. */
    private function atBaghdadHub(Merchant $merchant, ?Governorate $governorate = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $governorate) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي حسين',
                'recipient_phone' => '07801234567', 'governorate_id' => ($governorate ?? $this->baghdad())->id,
                'address' => 'العنوان', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->baghdadClerk);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->baghdadClerk);
            $change->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->baghdadClerk, ['hub_id' => $this->baghdadHub->id]);

            return $shipment->refresh();
        });
    }

    /** راجعٌ لتاجر البصرة رفضه زبونه في بغداد، واستُلم من مندوبه في بغداد. */
    private function returnOnBaghdadShelf(bool $received = true): Shipment
    {
        $shipment = $this->atBaghdadHub($this->basraMerchant);

        return Tenancy::runFor($this->company, function () use ($shipment, $received) {
            $change = app(ChangeShipmentStatus::class);
            $reason = FailureReason::where('code', 'no_answer')->firstOrFail();

            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->baghdadClerk, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::FailedAttempt, $this->baghdadClerk, ['failure_reason_id' => $reason->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Returning, $this->baghdadClerk);

            if ($received) {
                app(ReceiveReturns::class)->handle([$shipment->id], $this->baghdadClerk);
            }

            return $shipment->refresh();
        });
    }

    private function send(User $user, array $ids, array $extra = [])
    {
        return $this->actingAs($user)->post($this->host().'/transfers', $extra + [
            'to_hub_id'    => $this->basraHub->id,
            'shipment_ids' => $ids,
            'driver_name'  => 'سائق الخط',
        ]);
    }

    // ── الرحلة كاملة ─────────────────────────────────────────────────

    public function test_a_return_sent_to_its_merchants_branch_arrives_ready_for_the_merchant_not_as_a_new_shipment(): void
    {
        $return = $this->returnOnBaghdadShelf();

        // بغداد تراه بين رواجع تجّار البصرة حين تختارها
        $this->actingAs($this->baghdadClerk)->get($this->host().'/transfers?to='.$this->basraHub->id)
            ->assertOk()
            ->assertSee('رواجع لتجّار فرع البصرة')
            ->assertSee($return->number);

        $this->send($this->baghdadClerk, [$return->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'منها 1 راجع'));

        $manifest = Tenancy::runFor($this->company, fn () => Manifest::sole());
        $this->assertSame('dispatched', $manifest->status);
        $this->assertSame($this->basraHub->id, (int) $manifest->to_hub_id);
        $this->assertSame(ShipmentStatus::Returning, $return->refresh()->status);
        $this->assertNotNull($return->current_bag_id);

        // البصرة تراه واصلاً إليها، وتستلمه بضغطة
        $this->actingAs($this->basraClerk)->get($this->host().'/transfers')
            ->assertOk()->assertSee($manifest->code)->assertSee('استلمت الكل');

        $this->actingAs($this->basraClerk)->post($this->host()."/transfers/{$manifest->id}/receive")
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'وصل راجعاً'));

        $fresh = $return->refresh();
        $this->assertSame(ShipmentStatus::Returning, $fresh->status, 'الراجع عاد شحنةً جديدة بالاستلام.');
        $this->assertSame($this->basraHub->id, (int) $fresh->hub_id);
        $this->assertNull($fresh->current_bag_id);
        $this->assertNotNull($fresh->return_received_at);
        $this->assertSame('arrived', $manifest->refresh()->status);

        // وجاهزٌ لتسليم تاجره في البصرة
        $ready = Tenancy::runFor($this->company, fn () => app(HandOverReturns::class)
            ->ready($this->basraMerchant->id, viewer: $this->basraClerk));
        $this->assertTrue($ready->contains('id', $return->id));
    }

    public function test_a_shipment_for_another_governorate_lands_in_that_branchs_store(): void
    {
        $shipment = $this->atBaghdadHub($this->baghdadMerchant, $this->basra());
        $local = $this->atBaghdadHub($this->baghdadMerchant);

        // شحنات محافظة البصرة وحدها تُقترح للبصرة
        $this->actingAs($this->baghdadClerk)->get($this->host().'/transfers?to='.$this->basraHub->id)
            ->assertOk()
            ->assertSee($shipment->number)
            ->assertDontSee($local->number);

        $this->send($this->baghdadClerk, [$shipment->id])->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::InTransit, $shipment->refresh()->status);

        $manifest = Tenancy::runFor($this->company, fn () => Manifest::sole());
        $this->actingAs($this->basraClerk)->post($this->host()."/transfers/{$manifest->id}/receive")->assertRedirect();

        $this->assertSame(ShipmentStatus::AtHub, $shipment->refresh()->status);
        $this->assertSame($this->basraHub->id, (int) $shipment->hub_id);
        $this->assertNull($shipment->current_bag_id);
    }

    public function test_scanned_numbers_are_sent_too(): void
    {
        $shipment = $this->atBaghdadHub($this->baghdadMerchant);

        $this->send($this->baghdadClerk, [], ['numbers' => "{$shipment->number}\nNOPE-1"])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'NOPE-1'));

        $this->assertSame(ShipmentStatus::InTransit, $shipment->refresh()->status);
    }

    // ── الحدود ───────────────────────────────────────────────────────

    public function test_sending_names_who_carries_it(): void
    {
        $shipment = $this->atBaghdadHub($this->baghdadMerchant);

        $this->send($this->baghdadClerk, [$shipment->id], ['driver_name' => ''])
            ->assertSessionHasErrors('driver_name');

        $this->assertSame(ShipmentStatus::AtHub, $shipment->refresh()->status);
    }

    public function test_a_return_still_with_its_courier_is_not_sent(): void
    {
        $return = $this->returnOnBaghdadShelf(received: false);

        $this->send($this->baghdadClerk, [$return->id])
            ->assertSessionHasErrors(['shipment_ids' => 'لا شحنة بين المختارة تُرسَل من مركزك: '.$return->number.' (راجعٌ ما زال بيد المندوب: يُستلم منه أوّلاً)']);

        $this->assertNull($return->refresh()->current_bag_id);
    }

    public function test_only_the_destination_branch_receives_a_manifest(): void
    {
        // من البصرة إلى بغداد: موظّف البصرة يرى الكشف (خرج من مركزه) ولا يستلمه عن بغداد
        $shipment = Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->basraMerchant->id, 'recipient_name' => 'علي حسين',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'العنوان', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->basraClerk);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->basraClerk);
            $change->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->basraClerk, ['hub_id' => $this->basraHub->id]);

            return $shipment->refresh();
        });

        $this->send($this->basraClerk, [$shipment->id], ['to_hub_id' => $this->baghdadHub->id])->assertSessionHasNoErrors();
        $manifest = Tenancy::runFor($this->company, fn () => Manifest::sole());
        $this->assertSame($this->basraHub->id, (int) $manifest->from_hub_id);

        $this->actingAs($this->basraClerk)->post($this->host()."/transfers/{$manifest->id}/receive")->assertForbidden();
        $this->assertSame('dispatched', $manifest->refresh()->status);

        // وموظّف البصرة لا يُرسل من مخزن غيره ولو طلبه
        $other = $this->atBaghdadHub($this->baghdadMerchant, $this->basra());
        $this->send($this->basraClerk, [$other->id], ['to_hub_id' => $this->baghdadHub->id, 'from_hub_id' => $this->baghdadHub->id])
            ->assertSessionHasErrors('shipment_ids');
        $this->assertSame(ShipmentStatus::AtHub, $other->refresh()->status);
    }

    // ── الراجع لا يعود شحنةً جديدة بتغيير حالة ─────────────────────────

    public function test_a_received_return_does_not_become_a_shipment_by_choosing_at_hub(): void
    {
        $return = $this->returnOnBaghdadShelf();
        $owner = $this->makeUser($this->company);

        $this->actingAs($owner)->post($this->host()."/shipments/{$return->id}/status", ['status' => 'at_hub'])
            ->assertSessionHasErrors('status');
        $this->assertSame(ShipmentStatus::Returning, $return->refresh()->status);

        // وإعادة التوصيل بطلب التاجر قرارٌ صريح بسببه
        $this->actingAs($owner)->post($this->host()."/shipments/{$return->id}/status", ['status' => 'at_hub', 'retry' => 1])
            ->assertSessionHasErrors('note');
        $this->assertSame(ShipmentStatus::Returning, $return->refresh()->status);

        $this->actingAs($owner)->post($this->host()."/shipments/{$return->id}/status",
            ['status' => 'at_hub', 'retry' => 1, 'note' => 'التاجر طلب إعادة المحاولة'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::AtHub, $return->refresh()->status);
    }

    public function test_the_action_itself_refuses_turning_a_return_into_a_shipment(): void
    {
        $return = $this->returnOnBaghdadShelf();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($return->refresh(), ShipmentStatus::AtHub, $this->baghdadClerk));
    }

    public function test_scanning_a_return_still_in_a_transfer_bag_points_to_the_transfer_screen(): void
    {
        $return = $this->returnOnBaghdadShelf();
        $this->send($this->baghdadClerk, [$return->id]);

        $result = Tenancy::runFor($this->company, fn () => app(ReceiveAtHub::class)->handle([$return->id], $this->basraClerk));

        $this->assertStringContainsString('النقل بين الفروع', $result['skipped'][$return->number]);
        $this->assertSame(ShipmentStatus::Returning, $return->refresh()->status);
    }

    public function test_receiving_from_the_manifest_screen_opens_the_bags_too(): void
    {
        $shipment = $this->atBaghdadHub($this->baghdadMerchant, $this->basra());
        $this->send($this->baghdadClerk, [$shipment->id]);
        $manifest = Tenancy::runFor($this->company, fn () => Manifest::sole());

        $this->actingAs($this->basraClerk)->post($this->host()."/manifests/{$manifest->id}/receive", [
            'bag_ids' => $manifest->bags()->pluck('bags.id')->all(),
        ])->assertRedirect();

        $this->assertSame(ShipmentStatus::AtHub, $shipment->refresh()->status);
        $this->assertNull($shipment->current_bag_id);
    }
}

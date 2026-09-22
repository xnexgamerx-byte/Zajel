<?php

namespace Tests\Feature\Returns;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الراجع خطوتان. أكثر ما يُختلف عليه في هذا المجال طرد «راجع» في
 * النظام والتاجر لم يره — فالاختبار هنا على الفصل بين الخطوتين قبل
 * أي شيء آخر.
 */
class ReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(?Merchant $merchant = null, int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => ($merchant ?? $this->alpha)->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => $cod,
        ], $this->staff));
    }

    private function walk(Shipment $shipment, array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $path) {
            $change = app(ChangeShipmentStatus::class);

            foreach ($path as $status) {
                $change->handle($shipment->refresh(), $status, $this->staff, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    /** طرد فشل تسليمه وقُرّر إرجاعه — ما زال بيد المندوب. */
    private function returning(?Merchant $merchant = null, int $cod = 50_000): Shipment
    {
        return $this->walk($this->shipment($merchant, $cod), [
            ShipmentStatus::PickedUp,
            ShipmentStatus::AtHub,
            ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt,
            ShipmentStatus::Returning,
        ]);
    }

    private function receive(Shipment ...$shipments): void
    {
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)
            ->handle(collect($shipments)->pluck('id')->all(), $this->staff));
    }

    // ── الفصل بين الخطوتين ──────────────────────────────────────────

    public function test_a_parcel_still_with_the_courier_cannot_be_handed_to_the_merchant(): void
    {
        $shipment = $this->returning();

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($shipment, ShipmentStatus::Returned, $this->staff));
    }

    public function test_the_merchant_is_not_charged_the_return_fee_before_receiving_it(): void
    {
        $shipment = $this->returning();

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame(0, (int) $this->alpha->refresh()->balance, 'لا مال يتحرّك قبل التسليم للتاجر');
            $this->assertNull($shipment->return_received_at);
        });
    }

    public function test_receiving_from_the_courier_records_who_and_when(): void
    {
        $shipment = $this->returning();

        $this->receive($shipment);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $fresh = $shipment->refresh();

            $this->assertNotNull($fresh->return_received_at);
            $this->assertSame($this->staff->id, $fresh->return_received_by_user_id);
            $this->assertSame(
                ShipmentStatus::Returning,
                $fresh->status,
                'الاستلام من المندوب ليس تسليماً للتاجر',
            );

            $this->assertDatabaseHas('shipment_events', [
                'shipment_id' => $fresh->id,
                'event_type'  => 'return_received',
            ]);
        });
    }

    public function test_a_parcel_that_never_left_the_hub_needs_no_receiving_step(): void
    {
        // قُرّر إرجاعه وهو في المخزن: لا مندوب يستلم منه
        $shipment = $this->walk($this->shipment(), [
            ShipmentStatus::PickedUp,
            ShipmentStatus::AtHub,
            ShipmentStatus::Returning,
        ]);

        $this->assertNotNull($shipment->return_received_at, 'الطرد في المخزن أصلاً');
    }

    public function test_handing_over_charges_the_return_fee_and_the_courier_commission(): void
    {
        $shipment = $this->returning();
        $this->receive($shipment);

        $this->walk($shipment->refresh(), [ShipmentStatus::Returned]);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $fresh = $shipment->refresh();
            $returnFee = $fresh->return_fee;

            $this->assertSame(ShipmentStatus::Returned, $fresh->status);
            $this->assertNotNull($fresh->returned_at);
            $this->assertSame(-$returnFee, (int) $this->alpha->refresh()->balance);
            $this->assertSame(750, (int) $this->courier->refresh()->commission_balance);

            $ledger = app(Ledger::class);
            $this->assertTrue($ledger->reconcile('merchant', $this->alpha->id)['matches']);
            $this->assertTrue($ledger->reconcile('courier', $this->courier->id)['matches']);
        });
    }

    public function test_receiving_twice_does_not_duplicate_the_event(): void
    {
        $shipment = $this->returning();

        $this->receive($shipment);
        $first = Tenancy::runFor($this->company, fn () => $shipment->refresh()->return_received_at);

        $this->receive($shipment);

        Tenancy::runFor($this->company, function () use ($shipment, $first) {
            $this->assertEquals($first, $shipment->refresh()->return_received_at, 'الاستلام لا يُعاد ختمه');
            $this->assertSame(1, ShipmentEvent::where('shipment_id', $shipment->id)
                ->where('event_type', 'return_received')->count());
        });
    }

    // ── الشاشتان ────────────────────────────────────────────────────

    public function test_the_incoming_screen_lists_what_is_still_with_the_courier(): void
    {
        $withCourier = $this->returning();
        $received = $this->returning();
        $this->receive($received);

        $this->actingAs($this->staff)
            ->get($this->host().'/returns')
            ->assertOk()
            ->assertSee($withCourier->number)
            ->assertDontSee($received->number);
    }

    public function test_the_handover_screen_lists_only_what_reached_the_hub(): void
    {
        $withCourier = $this->returning();
        $received = $this->returning();
        $this->receive($received);

        $this->actingAs($this->staff)
            ->get($this->host().'/returns/handover')
            ->assertOk()
            ->assertSee($received->number)
            ->assertDontSee($withCourier->number);
    }

    public function test_staff_receive_returns_in_bulk_from_the_screen(): void
    {
        $one = $this->returning();
        $two = $this->returning();

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/receive', ['shipment_ids' => [$one->id, $two->id]])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($one, $two) {
            $this->assertNotNull($one->refresh()->return_received_at);
            $this->assertNotNull($two->refresh()->return_received_at);
        });
    }

    public function test_staff_hand_a_whole_batch_to_one_merchant(): void
    {
        $one = $this->returning();
        $two = $this->returning();
        $this->receive($one, $two);

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/handover', [
                'merchant_id'  => $this->alpha->id,
                'shipment_ids' => [$one->id, $two->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($one, $two) {
            $this->assertSame(ShipmentStatus::Returned, $one->refresh()->status);
            $this->assertSame(ShipmentStatus::Returned, $two->refresh()->status);
            $this->assertSame(
                -($one->return_fee + $two->return_fee),
                (int) $this->alpha->refresh()->balance,
            );
        });
    }

    public function test_handing_over_refuses_a_parcel_belonging_to_another_merchant(): void
    {
        $mine = $this->returning($this->alpha);
        $theirs = $this->returning($this->beta);
        $this->receive($mine, $theirs);

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/handover', [
                'merchant_id'  => $this->alpha->id,
                'shipment_ids' => [$mine->id, $theirs->id],
            ]);

        Tenancy::runFor($this->company, function () use ($mine, $theirs) {
            $this->assertSame(ShipmentStatus::Returned, $mine->refresh()->status);
            $this->assertSame(
                ShipmentStatus::Returning,
                $theirs->refresh()->status,
                'طرد تاجر آخر لا يُسلَّم بالخطأ مع الدفعة',
            );
        });
    }

    public function test_handing_over_an_unreceived_parcel_is_refused_as_a_whole_batch(): void
    {
        $received = $this->returning();
        $stillOut = $this->returning();
        $this->receive($received);

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/handover', [
                'merchant_id'  => $this->alpha->id,
                'shipment_ids' => [$received->id, $stillOut->id],
            ])
            ->assertSessionHasErrors('shipments');

        Tenancy::runFor($this->company, function () use ($received, $stillOut) {
            $this->assertSame(ShipmentStatus::Returning, $received->refresh()->status, 'الدفعة كلّها أو لا شيء');
            $this->assertSame(ShipmentStatus::Returning, $stillOut->refresh()->status);
            $this->assertSame(0, (int) $this->alpha->refresh()->balance);
        });
    }

    public function test_a_merchant_login_cannot_reach_the_returns_screens(): void
    {
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->get($this->host().'/returns')->assertForbidden();
        $this->actingAs($merchantUser)->get($this->host().'/returns/handover')->assertForbidden();
    }

    public function test_one_company_cannot_receive_another_companys_returns(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $otherMerchant = $this->makeMerchant($other, 'M0001');
        $otherStaff = $this->makeUser($other);

        $theirs = $this->returning();

        $this->actingAs($otherStaff)
            ->post('http://barq.'.config('zajel.tenant_domain').'/returns/receive', [
                'shipment_ids' => [$theirs->id],
            ])
            ->assertSessionHasErrors('shipment_ids');

        Tenancy::runFor($this->company, fn () => $this->assertNull($theirs->refresh()->return_received_at));
    }
}

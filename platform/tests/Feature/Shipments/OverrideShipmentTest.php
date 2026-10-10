<?php

namespace Tests\Feature\Shipments;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserGrant;
use App\Services\Ledger;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعديل الأجور والطلبية بصلاحيةٍ خاصّة، ولو انتهت الشحنة (docs/plan/38).
 */
class OverrideShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 2000, 'commission_per_return' => 1000,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(?ShipmentStatus $to = ShipmentStatus::Delivered): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($to) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);

            if ($to === null) {
                return $shipment->refresh();
            }

            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);

            if ($to === ShipmentStatus::Returned) {
                $reason = FailureReason::where('code', 'no_answer')->firstOrFail();
                $change->handle($shipment->refresh(), ShipmentStatus::FailedAttempt, $this->owner, ['failure_reason_id' => $reason->id]);
                $change->handle($shipment->refresh(), ShipmentStatus::Returning, $this->owner);
                app(\App\Actions\Returns\ReceiveReturns::class)->handle([$shipment->id], $this->owner);
                app(\App\Actions\Returns\HandOverReturns::class)->handle([$shipment->id], $this->merchant, $this->owner);
            } else {
                $change->handle($shipment->refresh(), $to, $this->owner);
            }

            return $shipment->refresh();
        });
    }

    private function override(User $user, Shipment $shipment, array $data)
    {
        return $this->actingAs($user)->put($this->host()."/shipments/{$shipment->id}/override", $data + [
            'recipient_phone' => $shipment->recipient_phone,
            'reason'          => 'اتّفاقٌ مع التاجر',
        ]);
    }

    private function assertBooksBalance(Shipment $shipment): void
    {
        Tenancy::runFor($this->company, function () use ($shipment) {
            $ledger = app(Ledger::class);
            $this->assertSame((int) $shipment->refresh()->merchant_due, $ledger->postedForShipment($shipment));
            $this->assertTrue($ledger->reconcile('merchant', $this->merchant->id)['matches']);
            $this->assertTrue($ledger->reconcile('courier', $this->courier->id)['matches']);
        });
    }

    public function test_the_owner_lowers_the_delivery_fee_of_a_delivered_shipment_and_the_merchant_is_credited(): void
    {
        $shipment = $this->shipment();
        $due = (int) $shipment->merchant_due;
        $fee = (int) $shipment->delivery_fee;
        $balance = (int) Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->balance);

        $this->actingAs($this->owner)->get($this->host()."/shipments/{$shipment->id}")->assertSee('تعديل الأجور والطلبية');

        $this->override($this->owner, $shipment, ['delivery_fee' => $fee - 1000])
            ->assertRedirect()->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame($fee - 1000, (int) $shipment->delivery_fee);
        $this->assertSame($due + 1000, (int) $shipment->merchant_due);
        $this->assertSame($balance + 1000, (int) Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->balance));

        $entry = Tenancy::runFor($this->company, fn () => Transaction::where('category', 'fee_correction')->sole());
        $this->assertSame('credit', $entry->direction);
        $this->assertStringContainsString('اتّفاقٌ مع التاجر', $entry->description);
        $this->assertBooksBalance($shipment);

        $this->actingAs($this->owner)->get($this->host()."/shipments/{$shipment->id}")->assertSee('تعديل بصلاحية خاصّة');
    }

    public function test_the_courier_fee_of_a_delivered_shipment_moves_his_commission(): void
    {
        $shipment = $this->shipment();
        $this->assertSame(2000, (int) $shipment->courier_commission);

        $this->override($this->owner, $shipment, ['courier_commission' => 3500])->assertSessionHasNoErrors();

        $this->assertSame(3500, (int) $shipment->refresh()->courier_commission);
        $this->assertSame(3500, (int) Tenancy::runFor($this->company, fn () => $this->courier->refresh()->commission_balance));
        $this->assertBooksBalance($shipment);
    }

    public function test_a_courier_fee_written_before_delivery_survives_it(): void
    {
        $shipment = $this->shipment(null);

        $this->override($this->owner, $shipment, ['courier_commission' => 5000])->assertSessionHasNoErrors();
        $this->assertTrue($shipment->refresh()->courier_commission_fixed);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->owner);
        });

        $this->assertSame(5000, (int) $shipment->refresh()->courier_commission);
        $this->assertSame(5000, (int) Tenancy::runFor($this->company, fn () => $this->courier->refresh()->commission_balance));
    }

    public function test_the_return_fee_of_a_returned_shipment_is_corrected_in_the_books(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Returned);
        $due = (int) $shipment->merchant_due;
        $returnFee = (int) $shipment->return_fee;

        $this->override($this->owner, $shipment, ['return_fee' => 0])->assertSessionHasNoErrors();

        $this->assertSame($due + $returnFee, (int) $shipment->refresh()->merchant_due);
        $this->assertBooksBalance($shipment);
    }

    public function test_the_order_details_of_a_finished_shipment_can_be_corrected(): void
    {
        $shipment = $this->shipment();

        $this->override($this->owner, $shipment, ['recipient_name' => 'حسين علي', 'notes' => 'باب أزرق'])
            ->assertSessionHasNoErrors();

        $this->assertSame('حسين علي', $shipment->refresh()->recipient_name);
        $this->assertSame('باب أزرق', $shipment->notes);
    }

    public function test_fees_of_a_shipment_in_a_closed_statement_stay_as_paid(): void
    {
        $shipment = $this->shipment();

        Tenancy::runFor($this->company, function () {
            $this->settleCourierCash($this->company);   // كشف التاجر بعد محاسبة المندوب (docs/plan/49)
            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->owner);
            app(PayMerchantSettlement::class)->confirm($sheet, $this->owner);
        });

        $this->override($this->owner, $shipment, ['delivery_fee' => 0])->assertSessionHasErrors('delivery_fee');
        $this->assertNotSame(0, (int) $shipment->refresh()->delivery_fee);
    }

    public function test_a_draft_statement_follows_the_new_fee(): void
    {
        $shipment = $this->shipment();
        $this->settleCourierCash($this->company);   // كشف التاجر بعد محاسبة المندوب (docs/plan/49)
        $sheet = Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)->handle($this->merchant, $this->owner));
        $net = (int) $sheet->net_amount;

        $this->override($this->owner, $shipment, ['delivery_fee' => (int) $shipment->delivery_fee + 2000])->assertSessionHasNoErrors();

        $this->assertSame($net - 2000, (int) $sheet->refresh()->net_amount);
    }

    public function test_only_the_owner_by_default_and_whoever_is_granted_it(): void
    {
        $shipment = $this->shipment();

        foreach ([UserRole::CompanyAdmin, UserRole::BranchOwner, UserRole::Accountant] as $role) {
            $user = $this->makeUser($this->company, $role);
            $this->assertFalse($user->can(Ability::SHIPMENTS_OVERRIDE), $role->value);
            $this->actingAs($user)->get($this->host()."/shipments/{$shipment->id}/override")->assertForbidden();
        }

        $admin = $this->makeUser($this->company, UserRole::CompanyAdmin);
        Tenancy::runFor($this->company, fn () => UserGrant::create(['user_id' => $admin->id, 'ability' => Ability::SHIPMENTS_OVERRIDE]));

        $this->actingAs($admin->refresh())->get($this->host()."/shipments/{$shipment->id}/override")->assertOk();
    }

    public function test_a_reason_is_required(): void
    {
        $shipment = $this->shipment();

        $this->override($this->owner, $shipment, ['delivery_fee' => 0, 'reason' => ''])->assertSessionHasErrors('reason');
    }
}

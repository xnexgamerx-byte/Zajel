<?php

namespace Tests\Feature\Settlements;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Support\MerchantBalance;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أجرة التوصيل تُصحَّح من سطر كشف التاجر قبل دفعه (docs/plan/61) — كعمولة المندوب في كشفه:
 * يُخصم الفرق صحيحاً من السطر ومن الكشف ومن رصيده، ولا يبقى في الحساب فرقٌ لا يفسّره شيء.
 */
class MerchantStatementFeeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** @return array{0: MerchantSettlement, 1: Shipment} */
    private function draft(): array
    {
        return Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C1', 'name' => 'عباس', 'phone' => '07720000001', 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 3000]);
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 31_000,
            ], $this->owner);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }
            app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($courier, $this->owner), $this->owner);

            return [app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner), $shipment->refresh()];
        });
    }

    public function test_the_delivery_fee_is_corrected_from_the_draft_line_and_deducted_exactly(): void
    {
        [$draft, $shipment] = $this->draft();
        $line = Tenancy::runFor($this->company, fn () => $draft->lines()->first());
        $oldFee = (int) $line->delivery_fee;
        $oldNet = (int) $draft->net_amount;
        $oldBalance = (int) Tenancy::runFor($this->company, fn () => $this->merchant->fresh()->balance);

        $this->actingAs($this->owner)->get($this->host().'/settlements/merchants/'.$draft->id)->assertOk()
            ->assertSee('name="fee"', false);

        $this->actingAs($this->owner)->post($this->host().'/settlements/merchants/'.$draft->id.'/fee', [
            'shipment_id' => $shipment->id, 'fee' => '٨٠٠٠',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $diff = 8000 - $oldFee;
        Tenancy::runFor($this->company, function () use ($draft, $shipment, $diff, $oldNet, $oldBalance) {
            $line = $draft->lines()->first();
            $this->assertSame(8000, (int) $line->delivery_fee);
            $this->assertSame((int) $shipment->merchant_due - $diff, (int) $line->net_amount);
            $this->assertSame($oldNet - $diff, (int) MerchantSettlement::find($draft->id)->net_amount);
            $merchant = $this->merchant->fresh();
            $this->assertSame($oldBalance - $diff, (int) $merchant->balance);
            // ولا فرق في الدفتر لا يفسّره كشفٌ أو مطابقة
            $this->assertSame(0, MerchantBalance::of($merchant)->unexplained());
        });
    }

    public function test_only_whoever_may_override_fees_sees_the_field(): void
    {
        [$draft] = $this->draft();
        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        $this->actingAs($accountant)->get($this->host().'/settlements/merchants/'.$draft->id)->assertOk()->assertSee('name="fee"', false);

        $manager = $this->makeUser($this->company, UserRole::BranchManager);
        $this->actingAs($manager)->post($this->host().'/settlements/merchants/'.$draft->id.'/fee', [
            'shipment_id' => 1, 'fee' => '1',
        ])->assertForbidden();
    }
}

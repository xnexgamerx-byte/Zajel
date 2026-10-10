<?php

namespace Tests\Feature\Money;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchRemittance;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\ReturnBatch;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رمز QR على الورق يُستلم به (docs/plan/61): كشف المندوب، ووصل حوالة الفرع، ووصل حساب التاجر.
 */
class DocScanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'نوفل', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 3000,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(): void
    {
        Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 31_000,
            ], $this->owner);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }
        });
    }

    public function test_the_courier_statement_carries_a_code_that_opens_his_receipt(): void
    {
        $this->deliver();
        $day = now()->format('Y-m-d');

        $this->actingAs($this->owner)->get($this->host()."/courier-manifests/{$this->courier->id}/{$day}")
            ->assertOk()->assertSee('امسحه للاستلام')->assertSee('data:image/svg+xml', false);

        // لا كشف مفتوح: ما بيده، وزرّ فتح كشفه
        $this->actingAs($this->owner)->get($this->host()."/doc/c/{$this->courier->id}")
            ->assertOk()->assertSee('نوفل')->assertSee('افتح كشف استلامه');

        // وكشفٌ مفتوح: إليه مباشرةً
        $draft = Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner));
        $this->actingAs($this->owner)->get($this->host()."/doc/c/{$this->courier->id}")
            ->assertRedirect($this->host().'/settlements/couriers/'.$draft->id);
    }

    public function test_a_merchant_receipt_and_a_branch_remittance_open_by_their_codes(): void
    {
        $this->deliver();
        $settlement = Tenancy::runFor($this->company, function () {
            app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner), $this->owner);

            return app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner);
        });

        $this->actingAs($this->owner)->get($this->host()."/settlements/merchants/{$settlement->id}")->assertOk()->assertSee('اطبع وصل الحساب');
        $this->actingAs($this->owner)->get($this->host()."/settlements/merchants/{$settlement->id}/print")
            ->assertOk()->assertSee($this->merchant->business_name)->assertSee('امسحه للاستلام');
        $this->actingAs($this->owner)->get($this->host()."/doc/m/{$settlement->id}")
            ->assertRedirect($this->host().'/settlements/merchants/'.$settlement->id);

        $remittance = Tenancy::runFor($this->company, function () {
            $main = Branch::where('is_main', true)->firstOrFail();
            $other = Branch::create(['name' => 'فرع البصرة', 'code' => 'BS', 'is_main' => false, 'is_active' => true]);

            return BranchRemittance::create(['number' => 'RM-1', 'from_branch_id' => $other->id, 'to_branch_id' => $main->id,
                'amount' => 450_000, 'sent_at' => now(), 'status' => 'sent']);
        });

        $this->actingAs($this->owner)->get($this->host()."/branch-accounts/remittances/{$remittance->id}/print")
            ->assertOk()->assertSee('450,000')->assertSee('امسحه عند الاستلام');
        $this->actingAs($this->owner)->get($this->host()."/doc/r/{$remittance->id}")
            ->assertRedirect($this->host().'/branch-accounts/remittances#remittance-'.$remittance->id);
        $this->actingAs($this->owner)->get($this->host().'/branch-accounts/remittances')
            ->assertOk()->assertSee('id="remittance-'.$remittance->id.'"', false)->assertSee('استلم بالرمز');
    }

    public function test_a_return_receipt_carries_a_code_that_opens_its_row_to_confirm(): void
    {
        $batch = Tenancy::runFor($this->company, fn () => ReturnBatch::create([
            'merchant_id' => $this->merchant->id, 'number' => 'RB-7', 'via' => 'pickup_courier', 'courier_id' => $this->courier->id,
            'handed_at' => now(),
        ]));

        $this->actingAs($this->owner)->get($this->host()."/return-batches/{$batch->id}/print")
            ->assertOk()->assertSee('RB-7')->assertSee('امسحه للاستلام');
        $this->actingAs($this->owner)->get($this->host()."/doc/b/{$batch->id}")
            ->assertRedirect($this->host().'/return-batches?merchant_id='.$this->merchant->id.'#batch-'.$batch->id);
        $this->actingAs($this->owner)->get($this->host().'/return-batches?merchant_id='.$this->merchant->id)
            ->assertOk()->assertSee('id="batch-'.$batch->id.'"', false)->assertSee('استلم بالرمز')->assertSee('أكِّد الاستلام');
    }

    public function test_whoever_cannot_see_money_cannot_open_a_code(): void
    {
        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)->get($this->host()."/doc/c/{$this->courier->id}")->assertForbidden();
    }
}

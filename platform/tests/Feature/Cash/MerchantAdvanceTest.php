<?php

namespace Tests\Feature\Cash;

use App\Actions\Cash\MerchantAdvances;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantAdvance;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سلف التجّار (docs/plan/38): تُعطى من صندوق، وتُستردّ من كشوف التاجر حتى تُسدَّد.
 *
 * والحساب بالدفتر يبقى صادقاً في كل خطوة: رصيد التاجر مستحقّه ناقصاً ما بقي عليه.
 */
class MerchantAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->box] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 500_000, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(int $cod): void
    {
        Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->owner);
        });
    }

    /** يُقفل كشفاً للتاجر ويدفعه، ويعيد [مجموع سطوره، ما خُصم، ما دُفع]. */
    private function settle(): array
    {
        return Tenancy::runFor($this->company, function () {
            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->owner);
            $lines = (int) $sheet->net_amount;

            $pay = app(PayMerchantSettlement::class);
            $pay->confirm($sheet, $this->owner);
            $pay->pay($sheet->refresh(), $this->owner, 'cash', null, $this->box->refresh());
            $sheet->refresh();

            return [$lines, (int) $sheet->advance_deduction, (int) $sheet->net_amount];
        });
    }

    private function balance(): int
    {
        return (int) Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->balance);
    }

    public function test_an_advance_leaves_the_box_and_is_owed_by_the_merchant(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/merchant-advances', [
            'merchant_id' => $this->merchant->id, 'amount' => 200_000, 'cash_box_id' => $this->box->id, 'note' => 'لشراء بضاعة',
        ])->assertRedirect()->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () {
            $advance = MerchantAdvance::sole();
            $this->assertSame('open', $advance->status);
            $this->assertSame(200_000, $advance->remaining());
            $this->assertSame(300_000, (int) $this->box->refresh()->balance);
            $this->assertSame('merchant_advance', CashMovement::latest('id')->first()->category);
            $this->assertSame(200_000, MerchantAdvances::outstanding($this->merchant));
        });

        $this->assertSame(-200_000, $this->balance());

        $this->actingAs($this->owner)->get($this->host().'/merchant-advances')
            ->assertOk()->assertSee('لشراء بضاعة')->assertSee('200,000');
    }

    public function test_no_advance_from_a_box_without_the_money(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/merchant-advances', [
            'merchant_id' => $this->merchant->id, 'amount' => 900_000, 'cash_box_id' => $this->box->id,
        ])->assertSessionHasErrors();

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => MerchantAdvance::count()));
        $this->assertSame(0, $this->balance());
    }

    public function test_settlements_pay_the_advance_back_until_it_is_repaid(): void
    {
        Tenancy::runFor($this->company, fn () => app(MerchantAdvances::class)
            ->give($this->merchant, 150_000, $this->box, $this->owner));

        // الكشف الأوّل أصغر من السلفة: يُخصم كلّه، ولا يُدفع للتاجر شيء
        $this->deliver(105_000);
        [$lines, $taken, $paid] = $this->settle();
        $this->assertGreaterThan(0, $lines);
        $this->assertSame($lines, $taken);
        $this->assertSame(0, $paid);
        $this->assertSame(-(150_000 - $taken), $this->balance());

        // الثاني يُكمل السلفة، ويُدفع الباقي
        $this->deliver(205_000);
        [$lines2, $taken2, $paid2] = $this->settle();
        $this->assertSame(150_000 - $taken, $taken2);
        $this->assertSame($lines2 - $taken2, $paid2);

        Tenancy::runFor($this->company, function () {
            $advance = MerchantAdvance::sole();
            $this->assertSame('repaid', $advance->status);
            $this->assertSame(150_000, (int) $advance->recovered);
            $this->assertCount(2, $advance->recoveries);
            $this->assertSame(0, MerchantAdvances::outstanding($this->merchant));

            // الدفتر يطابق الرصيد المخزَّن
            $this->assertTrue(app(Ledger::class)->reconcile('merchant', $this->merchant->id)['matches']);
        });

        $this->assertSame(0, $this->balance());

        // والثالث لا يُخصم منه شيء
        $this->deliver(55_000);
        [$lines3, $taken3, $paid3] = $this->settle();
        $this->assertSame(0, $taken3);
        $this->assertSame($lines3, $paid3);
    }

    public function test_a_cash_repayment_closes_the_advance_and_refills_the_box(): void
    {
        Tenancy::runFor($this->company, fn () => app(MerchantAdvances::class)
            ->give($this->merchant, 100_000, $this->box, $this->owner));

        // لا يُسدَّد أكثر ممّا عليه
        $this->actingAs($this->owner)->post($this->host().'/merchant-advances/repay', [
            'merchant_id' => $this->merchant->id, 'repay_amount' => 120_000, 'cash_box_id' => $this->box->id,
        ])->assertSessionHasErrors('repay_amount');

        $this->actingAs($this->owner)->post($this->host().'/merchant-advances/repay', [
            'merchant_id' => $this->merchant->id, 'repay_amount' => 100_000, 'cash_box_id' => $this->box->id,
        ])->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () {
            $this->assertSame('repaid', MerchantAdvance::sole()->status);
            $this->assertSame(500_000, (int) $this->box->refresh()->balance);
        });

        $this->assertSame(0, $this->balance());
    }

    public function test_the_draft_statement_warns_of_the_coming_deduction_and_the_paid_one_shows_it(): void
    {
        Tenancy::runFor($this->company, fn () => app(MerchantAdvances::class)
            ->give($this->merchant, 30_000, $this->box, $this->owner));
        $this->deliver(105_000);

        $sheet = Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)->handle($this->merchant, $this->owner));

        $this->actingAs($this->owner)->get($this->host()."/settlements/merchants/{$sheet->id}")
            ->assertOk()->assertSee('على التاجر سلفٌ باقية');

        Tenancy::runFor($this->company, fn () => app(PayMerchantSettlement::class)->confirm($sheet, $this->owner));

        $this->actingAs($this->owner)->get($this->host()."/settlements/merchants/{$sheet->id}")
            ->assertOk()->assertSee('خصم سلفة')->assertSee('30,000');
    }
}

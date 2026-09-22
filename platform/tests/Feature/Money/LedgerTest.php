<?php

namespace Tests\Feature\Money;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المال. أي خطأ هنا يظهر كخلاف مع تاجر أو مندوب، لا كصفحة خطأ.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $actor;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany();
        $this->merchant = $this->makeMerchant($this->company);
        $this->actor = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));
    }

    private function deliver(int $cod = 50_000, ?int $collected = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod, $collected) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => $cod,
            ], $this->actor);

            $change = app(ChangeShipmentStatus::class);

            $change->handle($shipment, ShipmentStatus::PickedUp, $this->actor);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->actor, [
                'courier_id' => $this->courier->id,
            ]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->actor,
                $collected === null ? [] : ['collected_amount' => $collected]);

            return $shipment->refresh();
        });
    }

    public function test_delivery_credits_the_merchant_and_charges_the_courier_with_the_cash(): void
    {
        $shipment = $this->deliver(cod: 50_000);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame(50_000, $shipment->collected_amount);
            $this->assertSame(45_000, $shipment->merchant_due);      // 50,000 − 5,000 أجرة
            $this->assertSame(1500, $shipment->courier_commission);

            $this->assertSame(45_000, (int) $this->merchant->fresh()->balance);

            $courier = $this->courier->fresh();
            $this->assertSame(50_000, (int) $courier->cash_in_hand);         // نقد فعلي بيده
            $this->assertSame(1500, (int) $courier->commission_balance);     // عمولة له
            $this->assertSame(48_500, $courier->netDue());                   // الواجب تسليمه
        });
    }

    public function test_every_balance_change_leaves_a_row_in_the_ledger(): void
    {
        $shipment = $this->deliver();

        Tenancy::runFor($this->company, function () use ($shipment) {
            $rows = Transaction::where('shipment_id', $shipment->id)->get();

            $this->assertCount(3, $rows);
            $this->assertEqualsCanonicalizing(
                ['shipment_due', 'cod_collected', 'commission'],
                $rows->pluck('category')->all(),
            );

            // كل صفّ يحمل الرصيد بعده — لتدقيق كشف الحساب لاحقاً
            $this->assertNotNull($rows->firstWhere('category', 'shipment_due')->balance_after);
        });
    }

    public function test_stored_balances_always_match_the_ledger(): void
    {
        $this->deliver(cod: 50_000);
        $this->deliver(cod: 80_000);

        Tenancy::runFor($this->company, function () {
            $ledger = app(Ledger::class);

            $merchant = $ledger->reconcile('merchant', $this->merchant->id);
            $courier = $ledger->reconcile('courier', $this->courier->id);

            $this->assertTrue($merchant['matches'], 'رصيد التاجر خالف الدفتر');
            $this->assertTrue($courier['matches'], 'أرصدة المندوب خالفت الدفتر');

            $this->assertSame(0, $courier['columns']['cash_in_hand']['drift']);
            $this->assertSame(0, $courier['columns']['commission_balance']['drift']);
        });
    }

    public function test_a_partial_delivery_uses_the_amount_actually_collected(): void
    {
        $shipment = $this->deliver(cod: 50_000, collected: 30_000);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame(30_000, $shipment->collected_amount);
            $this->assertSame(25_000, $shipment->merchant_due);   // 30,000 − 5,000
            $this->assertSame(30_000, (int) $this->courier->fresh()->cash_in_hand);
        });
    }

    public function test_a_return_charges_the_merchant_the_return_fee(): void
    {
        Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => 50_000,
            ], $this->actor);

            $this->assertSame(2500, $shipment->return_fee);   // مُجمَّدة وقت الإنشاء

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->actor);
            $change->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->actor);
            $change->handle($shipment->refresh(), ShipmentStatus::Returning, $this->actor);
            $change->handle($shipment->refresh(), ShipmentStatus::Returned, $this->actor);

            $shipment->refresh();

            $this->assertSame(-2500, $shipment->merchant_due);
            $this->assertSame(-2500, (int) $this->merchant->fresh()->balance);
            $this->assertSame(0, (int) $this->courier->fresh()->cash_in_hand);
        });
    }

    public function test_the_ledger_of_one_company_is_invisible_to_another(): void
    {
        $this->deliver();

        $other = $this->makeCompany('barq', 'البرق');

        Tenancy::runFor($other, fn () => $this->assertSame(0, Transaction::count()));
        Tenancy::runFor($this->company, fn () => $this->assertSame(3, Transaction::count()));
    }
}

<?php

namespace Tests\Feature\Settlements;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الدورة المالية كاملة. المقياس النهائي بسيط: بعد أن يسلّم المندوب
 * ويُدفَع للتاجر، يجب أن يعود كل رصيد إلى الصفر. أي بقية تعني
 * مالاً ضائعاً في مكان ما من النظام.
 */
class SettlementCycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $actor;

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

    private function shipment(int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => $cod,
        ], $this->actor));
    }

    private function walk(Shipment $shipment, array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $path) {
            $change = app(ChangeShipmentStatus::class);

            foreach ($path as $status) {
                $change->handle($shipment->refresh(), $status, $this->actor, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    private function deliver(int $cod = 50_000): Shipment
    {
        return $this->walk($this->shipment($cod), [
            ShipmentStatus::PickedUp,
            ShipmentStatus::OutForDelivery,
            ShipmentStatus::Delivered,
        ]);
    }

    private function returned(int $cod = 50_000): Shipment
    {
        $shipment = $this->walk($this->shipment($cod), [
            ShipmentStatus::PickedUp,
            ShipmentStatus::AtHub,
            ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt,
            ShipmentStatus::Returning,
        ]);

        // الطرد يعود من المندوب إلى المخزن قبل أن يُسلَّم للتاجر
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)
            ->handle([$shipment->id], $this->actor));

        return $this->walk($shipment->refresh(), [ShipmentStatus::Returned]);
    }

    // ------------------------------------------------------ تسوية المندوب

    public function test_a_courier_settlement_gathers_the_unsettled_shipments(): void
    {
        $this->deliver(50_000);
        $this->deliver(80_000);

        Tenancy::runFor($this->company, function () {
            $settlement = app(BuildCourierSettlement::class)->handle($this->courier, $this->actor);

            $this->assertSame('CS000001', $settlement->code);
            $this->assertSame(2, $settlement->shipments_count);
            $this->assertSame(130_000, $settlement->cod_total);
            $this->assertSame(3000, $settlement->commission_total);
            $this->assertSame(127_000, $settlement->net_amount);
            $this->assertSame('draft', $settlement->status);
        });
    }

    public function test_confirming_empties_the_couriers_balances_and_locks_the_shipments(): void
    {
        $shipment = $this->deliver(50_000);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $settlement = app(BuildCourierSettlement::class)->handle($this->courier, $this->actor);
            app(ConfirmCourierSettlement::class)->handle($settlement, $this->actor);

            $courier = $this->courier->fresh();

            $this->assertSame(0, (int) $courier->cash_in_hand);
            $this->assertSame(0, (int) $courier->commission_balance);

            $shipment->refresh();
            $this->assertSame($settlement->id, $shipment->courier_settlement_id);
            $this->assertNotNull($shipment->courier_settled_at);

            $this->assertSame('confirmed', $settlement->fresh()->status);
        });
    }

    public function test_a_settled_shipment_never_enters_a_second_settlement(): void
    {
        $this->deliver(50_000);

        Tenancy::runFor($this->company, function () {
            $first = app(BuildCourierSettlement::class)->handle($this->courier, $this->actor);
            app(ConfirmCourierSettlement::class)->handle($first, $this->actor);

            // لا شحنات متبقّية -> رفض صريح لا كشف فارغ
            $this->expectException(ValidationException::class);
            app(BuildCourierSettlement::class)->handle($this->courier, $this->actor);
        });
    }

    public function test_a_confirmed_settlement_cannot_be_confirmed_twice(): void
    {
        $this->deliver();

        Tenancy::runFor($this->company, function () {
            $settlement = app(BuildCourierSettlement::class)->handle($this->courier, $this->actor);
            $confirm = app(ConfirmCourierSettlement::class);

            $confirm->handle($settlement, $this->actor);

            $this->expectException(ValidationException::class);
            $confirm->handle($settlement->fresh(), $this->actor);
        });
    }

    public function test_deductions_increase_what_the_courier_hands_over(): void
    {
        $this->deliver(50_000);

        Tenancy::runFor($this->company, function () {
            $settlement = app(BuildCourierSettlement::class)->handle($this->courier, $this->actor);

            app(ConfirmCourierSettlement::class)->handle($settlement, $this->actor, deductions: 10_000);

            $settlement->refresh();

            // 50,000 محصَّل − 1,500 عمولة + 10,000 خصم
            $this->assertSame(58_500, $settlement->net_amount);
            $this->assertSame(10_000, $settlement->deductions);

            // الخصم دخل ضمن ما سلّمه، فالحساب يُقفَل على صفر
            $this->assertSame(0, (int) $this->courier->fresh()->cash_in_hand);
            $this->assertSame(0, (int) $this->courier->fresh()->commission_balance);
            $this->assertTrue(app(Ledger::class)->reconcile('courier', $this->courier->id)['matches']);
        });
    }

    // -------------------------------------------------------- تسوية التاجر

    public function test_a_merchant_statement_nets_deliveries_against_returns(): void
    {
        $this->deliver(50_000);     // له 45,000
        $this->returned(30_000);    // عليه 2,500 أجرة راجع

        Tenancy::runFor($this->company, function () {
            $settlement = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->actor);

            $this->assertSame('MS000001', $settlement->code);
            $this->assertSame(2, $settlement->shipments_count);
            $this->assertSame(1, $settlement->returned_count);
            $this->assertSame(50_000, $settlement->cod_total);
            $this->assertSame(5000, $settlement->delivery_fees_total);
            $this->assertSame(2500, $settlement->return_fees_total);
            $this->assertSame(42_500, $settlement->net_amount);   // 45,000 − 2,500
        });
    }

    public function test_paying_requires_confirming_first(): void
    {
        $this->deliver();

        Tenancy::runFor($this->company, function () {
            $settlement = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->actor);

            $this->expectException(ValidationException::class);
            app(PayMerchantSettlement::class)->pay($settlement, $this->actor, 'zaincash');
        });
    }

    public function test_paying_zeroes_the_merchant_balance_and_records_the_reference(): void
    {
        $this->deliver(50_000);

        Tenancy::runFor($this->company, function () {
            $action = app(PayMerchantSettlement::class);
            $settlement = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->actor);

            $action->confirm($settlement, $this->actor);
            $this->assertSame(45_000, (int) $this->merchant->fresh()->balance);   // الإقفال لا يحرّك مالاً

            $action->pay($settlement->fresh(), $this->actor, 'zaincash', 'ZC-99123');

            $settlement->refresh();

            $this->assertSame('paid', $settlement->status);
            $this->assertSame('zaincash', $settlement->payout_method);
            $this->assertSame('ZC-99123', $settlement->payout_reference);
            $this->assertSame(0, (int) $this->merchant->fresh()->balance);
        });
    }

    // ------------------------------------------------------ الدورة كاملة

    public function test_after_a_full_cycle_every_balance_is_zero_and_still_matches_the_ledger(): void
    {
        $this->deliver(50_000);
        $this->deliver(80_000);
        $this->returned(30_000);

        Tenancy::runFor($this->company, function () {
            $courierSettlement = app(BuildCourierSettlement::class)->handle($this->courier, $this->actor);
            app(ConfirmCourierSettlement::class)->handle($courierSettlement, $this->actor);

            $merchantSettlement = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->actor);
            $pay = app(PayMerchantSettlement::class);
            $pay->confirm($merchantSettlement, $this->actor);
            $pay->pay($merchantSettlement->fresh(), $this->actor, 'cash');

            $courier = $this->courier->fresh();
            $merchant = $this->merchant->fresh();

            $this->assertSame(0, (int) $courier->cash_in_hand);
            $this->assertSame(0, (int) $courier->commission_balance);
            $this->assertSame(0, (int) $merchant->balance);

            $ledger = app(Ledger::class);
            $this->assertTrue($ledger->reconcile('courier', $courier->id)['matches']);
            $this->assertTrue($ledger->reconcile('merchant', $merchant->id)['matches']);

            // لا شحنة بقيت بلا وسم تسوية
            $this->assertSame(0, Shipment::whereNull('merchant_settlement_id')
                ->whereIn('status', ['delivered', 'returned'])->count());
        });
    }

    public function test_settlements_of_one_company_are_invisible_to_another(): void
    {
        $this->deliver();

        Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)
            ->handle($this->courier, $this->actor));

        $other = $this->makeCompany('barq', 'البرق');

        Tenancy::runFor($other, function () {
            $this->assertSame(0, CourierSettlement::count());
            $this->assertSame(0, MerchantSettlement::count());
        });

        Tenancy::runFor($this->company, fn () => $this->assertSame(1, CourierSettlement::count()));
    }
}

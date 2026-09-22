<?php

namespace Tests\Feature\Cash;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CashBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوصل بين الكشوف والدرج.
 *
 * مندوب سلّم نقده فامتلأ الصندوق وبرئت ذمّته: حدثان لا حدث واحد. وتاجر
 * قُبض له بحوالة لم يتغيّر الدرج بشيء. هذا ما يفصل «كم لنا وكم علينا»
 * عن «كم في الخزنة»، وهو الفصل الذي بلا اختباره لا معنى للقاصة.
 */
class SettlementCashTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $courier;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));

        $this->box = Tenancy::runFor($this->company, fn () => CashBox::create([
            'code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main',
            'balance' => 0, 'is_active' => true,
        ]));
    }

    private function deliver(int $cod = 100_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff,
                ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff);

            return $shipment->refresh();
        });
    }

    public function test_a_courier_handover_fills_the_drawer_and_pays_his_commission(): void
    {
        $this->deliver(100_000);
        $this->deliver(60_000);

        Tenancy::runFor($this->company, function () {
            $sheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            app(ConfirmCourierSettlement::class)->handle($sheet, $this->staff);

            // ١٦٠ ألفاً دخلت، و٣ آلاف عمولة خرجت
            $this->assertSame(157_000, (int) $this->box->refresh()->balance);

            $movements = CashMovement::where('cash_box_id', $this->box->id)->orderBy('id')->get();

            $this->assertSame(['courier_handover', 'commission_paid'], $movements->pluck('category')->all());
            $this->assertSame(['in', 'out'], $movements->pluck('direction')->all());
            $this->assertSame(160_000, (int) $movements->first()->amount);
            $this->assertSame(3_000, (int) $movements->last()->amount);
        });
    }

    public function test_a_cash_payout_to_a_merchant_empties_it_again(): void
    {
        $this->deliver(100_000);

        Tenancy::runFor($this->company, function () {
            $courierSheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            app(ConfirmCourierSettlement::class)->handle($courierSheet, $this->staff);

            $before = (int) $this->box->refresh()->balance;

            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->staff);
            $pay = app(PayMerchantSettlement::class);
            $pay->confirm($sheet, $this->staff);
            $pay->pay($sheet->refresh(), $this->staff, 'cash');

            $this->assertSame($before - (int) $sheet->refresh()->net_amount, (int) $this->box->refresh()->balance);

            $payout = CashMovement::where('category', 'merchant_payout')->firstOrFail();
            $this->assertSame('out', $payout->direction);
        });
    }

    public function test_a_transfer_to_the_merchants_wallet_leaves_the_drawer_untouched(): void
    {
        $this->deliver(100_000);

        Tenancy::runFor($this->company, function () {
            $courierSheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            app(ConfirmCourierSettlement::class)->handle($courierSheet, $this->staff);

            $before = (int) $this->box->refresh()->balance;

            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->staff);
            $pay = app(PayMerchantSettlement::class);
            $pay->confirm($sheet, $this->staff);
            $pay->pay($sheet->refresh(), $this->staff, 'zaincash', 'ZC-99');

            $this->assertSame($before, (int) $this->box->refresh()->balance, 'الحوالة لا تُفرّغ الدرج');
            $this->assertSame(0, CashMovement::where('category', 'merchant_payout')->count());
        });
    }

    public function test_the_ledger_and_the_drawer_answer_different_questions(): void
    {
        $this->deliver(100_000);

        Tenancy::runFor($this->company, function () {
            // قبل التسوية: التاجر له مستحقّ في الدفتر، والدرج فارغ
            $this->assertGreaterThan(0, (int) $this->merchant->refresh()->balance);
            $this->assertSame(0, (int) $this->box->refresh()->balance);
        });
    }

    public function test_the_drawer_still_reconciles_after_a_full_cycle(): void
    {
        $this->deliver(100_000);
        $this->deliver(75_000);

        Tenancy::runFor($this->company, function () {
            $courierSheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            app(ConfirmCourierSettlement::class)->handle($courierSheet, $this->staff);

            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->staff);
            $pay = app(PayMerchantSettlement::class);
            $pay->confirm($sheet, $this->staff);
            $pay->pay($sheet->refresh(), $this->staff, 'cash');

            $this->assertTrue(app(CashBook::class)->reconcile($this->box)['matches']);
        });
    }

    public function test_a_company_without_a_box_still_settles(): void
    {
        // الدفتر المحاسبي كامل بلا صندوق؛ القاصة إضافة لا شرط
        Tenancy::runFor($this->company, fn () => $this->box->forceFill(['is_active' => false])->save());

        $this->deliver(100_000);

        Tenancy::runFor($this->company, function () {
            $sheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            app(ConfirmCourierSettlement::class)->handle($sheet, $this->staff);

            $this->assertSame('confirmed', $sheet->refresh()->status);
            $this->assertSame(0, CashMovement::count());
            $this->assertSame(0, (int) $this->courier->refresh()->cash_in_hand);
        });
    }
}

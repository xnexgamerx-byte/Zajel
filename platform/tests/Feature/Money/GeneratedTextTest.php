<?php

namespace Tests\Feature\Money;

use App\Actions\Cash\ManageDeposit;
use App\Actions\Cash\RecordExpense;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ConfirmAmount;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\ExpenseCategory;
use App\Models\Merchant;
use App\Models\ShipmentEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LengthException;
use Tests\Support\ColumnLengths;
use Tests\TestCase;

/**
 * نصٌّ تبنيه الشيفرة من مُدخَلٍ بأقصى طوله.
 *
 * كل حقلٍ مُدخَل يُحدّ بطول عموده — دُقِّق فوجد كلّه مطابقاً. لكن الوصف
 * الذي يُكتب في الدفتر وحركة الصندوق يُبنى: «مصروف EX000001 — » ثم وصف
 * المصروف بطوله كلّه. ٢٧٢ حرفاً في عمودٍ من ٢٥٥: تمرّ على SQLite وتُسقط
 * الدفع على MySQL. والاختبارات هنا تُكتب بأقصى ما يقبله كل حقل.
 */
class GeneratedTextTest extends TestCase
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

        Tenancy::runFor($this->company, function () {
            // الأسماء بأقصى أطوال أعمدتها: التاجر ٢٠٠، والمندوب ١٦٠
            $this->merchant->forceFill(['business_name' => str_repeat('م', 200)])->save();
            $this->courier = Courier::create([
                'code' => 'C1', 'name' => str_repeat('ن', 160), 'phone' => '07720000001',
                'type' => 'both', 'status' => 'active', 'commission_per_delivery' => 1500,
            ]);
            $this->box = CashBox::create(['code' => 'MAIN', 'name' => 'القاصة', 'type' => 'main', 'balance' => 0, 'is_active' => true]);
            app(CashBook::class)->in($this->box, 'opening', 10_000_000, null, $this->staff);
        });
    }

    private function text(int $length): string
    {
        return str_repeat('س', $length);
    }

    public function test_the_length_guard_is_real(): void
    {
        $this->assertGreaterThan(150, count(ColumnLengths::limits()));
        $this->assertSame(255, ColumnLengths::limits()['cash_movements.description']);

        $this->expectException(LengthException::class);
        Tenancy::runFor($this->company, fn () => $this->merchant->forceFill(['business_name' => $this->text(201)])->save());
    }

    public function test_paying_an_expense_with_the_longest_description(): void
    {
        Tenancy::runFor($this->company, function () {
            $category = ExpenseCategory::firstOrCreate(['code' => 'rent'], ['name_ar' => 'إيجار', 'is_active' => true]);
            $record = app(RecordExpense::class);
            $expense = $record->handle([
                'expense_category_id' => $category->id, 'amount' => 100_000,
                'spent_on' => now()->toDateString(), 'description' => $this->text(255),
            ], $this->staff);

            $record->pay($expense, $this->box, $this->staff);
            $record->cancel($expense->fresh(), $this->text(255), $this->staff);

            $this->assertSame(3, CashMovement::count());   // الافتتاحيّ، الدفع، الإعادة
            $this->assertLessThanOrEqual(255, mb_strlen(CashMovement::latest('id')->value('description')));
        });
    }

    public function test_a_deposit_with_the_longest_name_and_reason(): void
    {
        Tenancy::runFor($this->company, function () {
            app(ManageDeposit::class)->deposit($this->merchant, 50_000, $this->staff, $this->box, $this->text(255));

            $this->assertSame(50_000, (int) $this->merchant->fresh()->deposit_balance);
        });
    }

    public function test_a_confirmed_amount_with_the_longest_note(): void
    {
        Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment, ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment, ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $this->courier->id]);
            $change->handle($shipment, ShipmentStatus::Delivered, $this->staff);

            app(ConfirmAmount::class)->handle($shipment, 45_000, $this->staff, $this->text(255));

            $this->assertTrue((bool) $shipment->fresh()->amount_confirmed);
            $this->assertLessThanOrEqual(255, mb_strlen(Transaction::where('category', 'amount_correction')->value('description')));
        });
    }

    public function test_a_forced_delivery_with_the_longest_reason(): void
    {
        Tenancy::runFor($this->company, function () {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->staff);

            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::PickedUp, $this->staff);
            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $this->courier->id]);
            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::Delivered, $this->staff, [
                'force' => true, 'forced_reason' => $this->text(255), 'note' => $this->text(500),
            ]);

            $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
        });
    }

    public function test_a_cash_count_adjustment_with_the_longest_reason(): void
    {
        Tenancy::runFor($this->company, function () {
            app(CashBook::class)->out($this->box, 'adjustment', 5_000, 'تسوية جرد — '.$this->text(255), $this->staff);

            $this->assertSame(9_995_000, (int) $this->box->fresh()->balance);
        });
    }

    public function test_paying_a_pickup_agent_with_the_longest_name_and_note(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->courier->forceFill(['commission_balance' => 20_000])->save();

            $paid = app(\App\Actions\Pickups\PayPickupCommission::class)->handle($this->courier, $this->staff, $this->box, $this->text(255));

            $this->assertSame(20_000, $paid);
        });
    }
}

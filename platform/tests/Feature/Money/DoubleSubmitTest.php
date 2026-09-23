<?php

namespace Tests\Feature\Money;

use App\Actions\Cash\RecordExpense;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ConfirmAmount;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الضغطة المزدوجة.
 *
 * كل انتقالٍ يُحرّك مالاً كان يفحص حاله على النسخة التي بيد المستدعي،
 * خارج المعاملة وبلا قفل. فضغطتان على «دفعت» — أو نافذتان مفتوحتان،
 * أو إعادة إرسالٍ بعد انقطاع — تمرّان معاً وتدفعان مرّتين.
 *
 * تُحاكى المسابقة بنسختين من الصفّ قُرئتا قبل الإجراء: الأولى تنفّذ،
 * والثانية ما زالت تحمل الحال القديمة في ذاكرتها. ويجب أن تُرفض الثانية
 * لأن الحال تُقرأ من القاعدة بعد القفل لا من الذاكرة.
 */
class DoubleSubmitTest extends TestCase
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
            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
            ]);
            $this->box = CashBox::create([
                'code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true,
            ]);
        });
    }

    private function deliver(int $cod = 100_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff);

            return $shipment->refresh();
        });
    }

    /** يُنفّذ بالأولى ثم يحاول بالثانية، ويُرجع هل رُفضت الثانية. */
    private function secondIsRefused(callable $first, callable $second): bool
    {
        $first();

        try {
            $second();

            return false;
        } catch (ValidationException) {
            return true;
        }
    }

    public function test_a_merchant_settlement_is_paid_once(): void
    {
        $this->deliver();

        Tenancy::runFor($this->company, function () {
            app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($this->courier, $this->staff), $this->staff);
            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->staff);
            app(PayMerchantSettlement::class)->confirm($sheet, $this->staff);

            [$a, $b] = [MerchantSettlement::findOrFail($sheet->id), MerchantSettlement::findOrFail($sheet->id)];
            $pay = app(PayMerchantSettlement::class);

            $this->assertTrue($this->secondIsRefused(
                fn () => $pay->pay($a, $this->staff, 'cash'),
                fn () => $pay->pay($b, $this->staff, 'cash'),
            ), 'دفعةٌ ثانية لكشفٍ مدفوع مرّت.');

            $this->assertSame(1, Transaction::where('category', 'payout')->count());
            $this->assertSame(1, CashMovement::where('category', 'merchant_payout')->count());
        });
    }

    public function test_a_courier_settlement_is_confirmed_once(): void
    {
        $this->deliver();

        Tenancy::runFor($this->company, function () {
            $sheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            [$a, $b] = [CourierSettlement::findOrFail($sheet->id), CourierSettlement::findOrFail($sheet->id)];
            $confirm = app(ConfirmCourierSettlement::class);

            $this->assertTrue($this->secondIsRefused(
                fn () => $confirm->handle($a, $this->staff),
                fn () => $confirm->handle($b, $this->staff),
            ), 'تسليمٌ ثانٍ لكشفٍ مُقفَل مرّ.');

            $this->assertSame(1, Transaction::where('category', 'cash_handover')->count());
            $this->assertSame(1, CashMovement::where('category', 'courier_handover')->count());
        });
    }

    /** وأسوأها: التصحيح يُحسب فرقاً من المبلغ القديم، فيُطبَّق مرّتين. */
    public function test_an_amount_is_confirmed_once_and_the_correction_applied_once(): void
    {
        $shipment = $this->deliver(50_000);

        Tenancy::runFor($this->company, function () use ($shipment) {
            [$a, $b] = [Shipment::findOrFail($shipment->id), Shipment::findOrFail($shipment->id)];
            $confirm = app(ConfirmAmount::class);
            $dueBefore = (int) $a->merchant_due;

            $this->assertTrue($this->secondIsRefused(
                fn () => $confirm->handle($a, 45_000, $this->staff),
                fn () => $confirm->handle($b, 45_000, $this->staff),
            ), 'تأكيدٌ ثانٍ لمبلغٍ مؤكَّد مرّ.');

            $this->assertSame($dueBefore - 5_000, (int) $shipment->fresh()->merchant_due);
            // قيد التاجر وحده (قيد المندوب يقابله بإشارةٍ أخرى)
            $this->assertSame(-5_000, (int) Transaction::where('category', 'amount_correction')
                ->where('account_type', 'merchant')
                ->get()->sum(fn ($t) => $t->direction === 'credit' ? $t->amount : -$t->amount));
        });
    }

    private function outForDelivery(int $cod = 100_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $this->courier->id]);

            return $shipment->refresh();
        });
    }

    /** نقرتان على «سُلِّمت» من هاتف المندوب: الثانية لا تُضيف شيئاً. */
    public function test_a_double_tap_on_delivered_credits_once(): void
    {
        $shipment = $this->outForDelivery();

        Tenancy::runFor($this->company, function () use ($shipment) {
            [$a, $b] = [Shipment::findOrFail($shipment->id), Shipment::findOrFail($shipment->id)];
            $change = app(ChangeShipmentStatus::class);

            $change->handle($a, ShipmentStatus::Delivered, $this->staff);
            $change->handle($b, ShipmentStatus::Delivered, $this->staff);

            $this->assertSame(1, Transaction::where('category', 'cod_collected')->count());
            $this->assertSame(1, Transaction::where('category', 'shipment_due')->count());
            $this->assertSame(100_000, (int) $this->courier->fresh()->cash_in_hand);
        });
    }

    /**
     * وأسوأ منها: نسخةٌ قديمة تقول «مع المندوب» فتُسجّل محاولةً فاشلة على
     * شحنةٍ سُلِّمت وقُيّد نقدها — فتنقلب المسلَّمة فاشلةً والمال باقٍ.
     */
    public function test_a_stale_copy_cannot_undo_a_delivery(): void
    {
        $shipment = $this->outForDelivery();

        Tenancy::runFor($this->company, function () use ($shipment) {
            [$a, $b] = [Shipment::findOrFail($shipment->id), Shipment::findOrFail($shipment->id)];
            $change = app(ChangeShipmentStatus::class);
            $reason = \App\Models\FailureReason::where('code', 'no_answer')->value('id');

            $change->handle($a, ShipmentStatus::Delivered, $this->staff);

            try {
                $change->handle($b, ShipmentStatus::FailedAttempt, $this->staff, ['failure_reason_id' => $reason]);
                $this->fail('نسخةٌ قديمة أعادت شحنةً مسلَّمة إلى «محاولة فاشلة».');
            } catch (ValidationException) {
                $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
            }
        });
    }

    public function test_an_expense_is_paid_once(): void
    {
        Tenancy::runFor($this->company, function () {
            app(\App\Services\CashBook::class)->in($this->box, 'opening', 500_000, null, $this->staff);

            $category = ExpenseCategory::firstOrCreate(['code' => 'rent'], ['name_ar' => 'إيجار', 'is_active' => true]);
            $expense = app(RecordExpense::class)->handle([
                'expense_category_id' => $category->id, 'amount' => 200_000,
                'spent_on' => now()->toDateString(), 'description' => 'إيجار أيلول',
            ], $this->staff);

            [$a, $b] = [Expense::findOrFail($expense->id), Expense::findOrFail($expense->id)];
            $record = app(RecordExpense::class);

            $this->assertTrue($this->secondIsRefused(
                fn () => $record->pay($a, $this->box, $this->staff),
                fn () => $record->pay($b, $this->box, $this->staff),
            ), 'دفعةٌ ثانية لمصروفٍ مدفوع مرّت.');

            $this->assertSame(300_000, (int) $this->box->fresh()->balance);
        });
    }

    public function test_a_paid_expense_is_refunded_once_when_cancelled(): void
    {
        Tenancy::runFor($this->company, function () {
            app(\App\Services\CashBook::class)->in($this->box, 'opening', 500_000, null, $this->staff);

            $category = ExpenseCategory::firstOrCreate(['code' => 'rent'], ['name_ar' => 'إيجار', 'is_active' => true]);
            $record = app(RecordExpense::class);
            $expense = $record->handle([
                'expense_category_id' => $category->id, 'amount' => 200_000,
                'spent_on' => now()->toDateString(), 'description' => 'إيجار أيلول',
            ], $this->staff);
            $record->pay($expense, $this->box, $this->staff);

            [$a, $b] = [Expense::findOrFail($expense->id), Expense::findOrFail($expense->id)];

            $this->assertTrue($this->secondIsRefused(
                fn () => $record->cancel($a, 'خطأ في الإدخال', $this->staff),
                fn () => $record->cancel($b, 'خطأ في الإدخال', $this->staff),
            ), 'إلغاءٌ ثانٍ أعاد المبلغ إلى الصندوق مرّةً أخرى.');

            $this->assertSame(500_000, (int) $this->box->fresh()->balance);
        });
    }
}

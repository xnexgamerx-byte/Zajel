<?php

namespace Tests\Feature\Cash;

use App\Actions\Cash\ManageDeposit;
use App\Actions\Cash\RecordExpense;
use App\Actions\Pickups\PayPickupCommission;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Exceptions\InsufficientCash;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\PickupPayout;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا يخرج من صندوقٍ أكثر ممّا فيه.
 *
 * طلبُ صاحب النظام: تاجرٌ طلب مستحقّه والصندوق لم يمتلئ بعد لأن المندوبين لم
 * يسلّموا، فمرّ الدفع وصار الرصيد سالباً — والرصيد يجب أن يطابق ما في الشركة
 * فعلاً. فيُمنع كل خروجٍ أكبر من الرصيد، من أيّ شاشة، برسالةٍ واضحة، ولا
 * يُكتب شيءٌ من العملية.
 */
class NoNegativeCashTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = 'لا يمكن إتمام عملية التسديد، رصيد الصندوق غير كافٍ. يرجى تحصيل المبالغ المستحقة من المندوبين أولًا.';

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

        $this->box = $this->makeBox('MAIN', 'القاصة الرئيسية', 'main');
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function makeBox(string $code, string $name, string $type, int $opening = 0): CashBox
    {
        return Tenancy::runFor($this->company, function () use ($code, $name, $type, $opening) {
            $box = CashBox::create(['code' => $code, 'name' => $name, 'type' => $type, 'balance' => 0, 'is_active' => true]);

            if ($opening > 0) {
                app(CashBook::class)->in($box, 'opening', $opening, 'رصيد افتتاحي', $this->staff);
            }

            return $box->refresh();
        });
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
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff);

            return $shipment->refresh();
        });
    }

    /** المندوب يسلّم نقده: الصندوق يمتلئ */
    private function collectFromCourier(): void
    {
        Tenancy::runFor($this->company, function () {
            $sheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            app(ConfirmCourierSettlement::class)->handle($sheet, $this->staff);
        });
    }

    /** كشف تاجرٍ مُقفَل ينتظر الدفع */
    private function confirmedMerchantSheet(): MerchantSettlement
    {
        return Tenancy::runFor($this->company, function () {
            $this->settleCourierCash($this->company);   // كشف التاجر بعد محاسبة المندوب (docs/plan/49)
            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->staff);

            return app(PayMerchantSettlement::class)->confirm($sheet, $this->staff);
        });
    }

    private function refusal(callable $operation): InsufficientCash
    {
        try {
            Tenancy::runFor($this->company, $operation);
        } catch (InsufficientCash $e) {
            return $e;
        }

        $this->fail('مرّ خروجٌ أكبر من رصيد الصندوق.');
    }

    // ── دفع التاجر: الحالة التي ظهرت ──────────────────────────────────

    public function test_paying_a_merchant_more_cash_than_the_box_holds_is_refused_and_writes_nothing(): void
    {
        $this->deliver(100_000);              // المندوب لم يسلّم بعد: الصندوق فارغ
        $sheet = $this->confirmedMerchantSheet();
        $owed = (int) $this->merchant->refresh()->balance;

        $e = $this->refusal(fn () => app(PayMerchantSettlement::class)->pay($sheet, $this->staff, 'cash'));

        $this->assertStringStartsWith(self::REFUSAL, $e->errors()['cash_box'][0]);
        $this->assertStringContainsString('«القاصة الرئيسية» 0 د.ع', $e->errors()['cash_box'][0]);
        $this->assertSame(0, $e->available);
        $this->assertSame((int) $sheet->net_amount, $e->requested);

        Tenancy::runFor($this->company, function () use ($sheet, $owed) {
            $this->assertSame('confirmed', $sheet->refresh()->status, 'الكشف يبقى بانتظار الدفع');
            $this->assertNull($sheet->paid_at);
            $this->assertSame($owed, (int) $this->merchant->refresh()->balance, 'مستحقّ التاجر لا يُقيَّد مدفوعاً');
            $this->assertSame(0, Transaction::where('category', 'payout')->count());
            $this->assertSame(0, CashMovement::where('category', 'merchant_payout')->count());
            $this->assertSame(0, (int) $this->box->refresh()->balance, 'لا رصيد سالب');
        });
    }

    public function test_the_screen_refuses_with_the_message_and_keeps_the_sheet_waiting(): void
    {
        $this->deliver(100_000);
        $sheet = $this->confirmedMerchantSheet();
        $page = $this->host()."/settlements/merchants/{$sheet->id}";

        $this->actingAs($this->staff)->from($page)
            ->post("{$page}/pay", ['payout_method' => 'cash', 'cash_box_id' => $this->box->id])
            ->assertRedirect($page)
            ->assertSessionHasErrors('cash_box');

        // والشاشة كما يراها: ترجع إلى الكشف والرسالة أعلاها، وزرّ الدفع باقٍ
        $this->actingAs($this->staff)->from($page)->followingRedirects()
            ->post("{$page}/pay", ['payout_method' => 'cash', 'cash_box_id' => $this->box->id])
            ->assertOk()
            ->assertSee(self::REFUSAL)
            ->assertSee('سجّل الدفع');

        Tenancy::runFor($this->company, fn () => $this->assertSame('confirmed', $sheet->refresh()->status));
    }

    public function test_the_pay_form_shows_the_box_and_warns_before_the_click(): void
    {
        $this->deliver(100_000);
        $sheet = $this->confirmedMerchantSheet();

        $this->actingAs($this->staff)->get($this->host()."/settlements/merchants/{$sheet->id}")
            ->assertOk()
            ->assertSee('يُدفع النقد من')
            ->assertSee('القاصة الرئيسية (0)')
            ->assertSee('لا يُدفع نقداً منه حتى')
            ->assertSee('تُحصَّل المبالغ من المندوبين');
    }

    /** مديرُ الفرع يرى المال ولا يدفعه: لا تُعرض عليه الصناديق في نموذج الدفع */
    public function test_the_boxes_are_offered_only_to_whoever_may_pay(): void
    {
        $this->deliver(100_000);
        $sheet = $this->confirmedMerchantSheet();
        $manager = $this->makeUser($this->company, UserRole::BranchManager);

        $this->actingAs($manager)->get($this->host()."/settlements/merchants/{$sheet->id}")
            ->assertOk()
            ->assertSee('تسجيل الدفع')
            ->assertDontSee('يُدفع النقد من');
    }

    public function test_after_the_courier_hands_over_the_same_payout_goes_through(): void
    {
        $this->deliver(100_000);
        $this->collectFromCourier();
        $sheet = $this->confirmedMerchantSheet();
        $before = (int) $this->box->refresh()->balance;

        $this->actingAs($this->staff)->get($this->host()."/settlements/merchants/{$sheet->id}")
            ->assertOk()->assertDontSee('لا يُدفع نقداً منه حتى');

        $this->actingAs($this->staff)
            ->post($this->host()."/settlements/merchants/{$sheet->id}/pay", ['payout_method' => 'cash', 'cash_box_id' => $this->box->id])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($sheet, $before) {
            $this->assertSame('paid', $sheet->refresh()->status);
            $this->assertSame($before - (int) $sheet->net_amount, (int) $this->box->refresh()->balance);
            $this->assertGreaterThanOrEqual(0, (int) $this->box->balance);
            $this->assertTrue(app(CashBook::class)->reconcile($this->box)['matches']);
        });
    }

    public function test_a_transfer_to_the_merchant_does_not_wait_for_cash_in_the_box(): void
    {
        $this->deliver(100_000);
        $sheet = $this->confirmedMerchantSheet();

        // زين كاش لا يخرج من الدرج: لا يمنعه درجٌ فارغ
        Tenancy::runFor($this->company, fn () => app(PayMerchantSettlement::class)->pay($sheet, $this->staff, 'zaincash', 'ZC-1'));

        Tenancy::runFor($this->company, function () use ($sheet) {
            $this->assertSame('paid', $sheet->refresh()->status);
            $this->assertSame(0, CashMovement::count());
        });
    }

    /** والحوالة من الشاشة لا تسأل عن الصندوق المختار في النموذج */
    public function test_a_transfer_from_the_screen_ignores_the_box_field(): void
    {
        $this->deliver(100_000);
        $sheet = $this->confirmedMerchantSheet();

        $this->actingAs($this->staff)
            ->post($this->host()."/settlements/merchants/{$sheet->id}/pay", [
                'payout_method' => 'zaincash', 'payout_reference' => 'ZC-2', 'cash_box_id' => 999_999,
            ])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, fn () => $this->assertSame('paid', $sheet->refresh()->status));
    }

    public function test_cash_is_paid_from_the_box_chosen_on_the_form(): void
    {
        $this->deliver(100_000);
        $sheet = $this->confirmedMerchantSheet();
        $safe = $this->makeBox('SAFE', 'خزنة المكتب', 'branch', 500_000);

        $this->actingAs($this->staff)
            ->post($this->host()."/settlements/merchants/{$sheet->id}/pay", ['payout_method' => 'cash', 'cash_box_id' => $safe->id])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($sheet, $safe) {
            $this->assertSame('paid', $sheet->refresh()->status);
            $this->assertSame(500_000 - (int) $sheet->net_amount, (int) $safe->refresh()->balance);
            $this->assertSame(0, (int) $this->box->refresh()->balance, 'القاصة الفارغة لم تُمسّ');
        });
    }

    public function test_another_employees_box_cannot_be_chosen(): void
    {
        $this->deliver(100_000);
        $sheet = $this->confirmedMerchantSheet();

        $other = $this->makeUser($this->company, \App\Enums\UserRole::Accountant);   // زميلٌ غير الدافع
        $theirs = Tenancy::runFor($this->company, function () use ($other) {
            $box = CashBox::create(['code' => 'EMP2', 'name' => 'صندوق زميل', 'type' => 'employee', 'user_id' => $other->id, 'balance' => 0, 'is_active' => true]);
            app(CashBook::class)->in($box, 'opening', 900_000, null, $other);

            return $box->refresh();
        });

        $this->actingAs($this->staff)
            ->post($this->host()."/settlements/merchants/{$sheet->id}/pay", ['payout_method' => 'cash', 'cash_box_id' => $theirs->id])
            ->assertSessionHasErrors('cash_box_id');

        Tenancy::runFor($this->company, function () use ($sheet, $theirs) {
            $this->assertSame('confirmed', $sheet->refresh()->status);
            $this->assertSame(900_000, (int) $theirs->refresh()->balance);
        });
    }

    // ── كل خروجٍ آخر من الصندوق ──────────────────────────────────────

    public function test_a_box_may_be_emptied_to_exactly_zero_but_not_below(): void
    {
        $petty = $this->makeBox('PETTY', 'النثريّة', 'petty', 50_000);

        Tenancy::runFor($this->company, fn () => app(CashBook::class)->out($petty, 'expense', 50_000, 'إيجار', $this->staff));
        $this->assertSame(0, (int) $petty->refresh()->balance);

        $e = $this->refusal(fn () => app(CashBook::class)->out($petty, 'expense', 1, 'قلم', $this->staff));
        $this->assertStringStartsWith('لا يمكن إتمام دفع المصروف، رصيد الصندوق غير كافٍ.', $e->errors()['cash_box'][0]);
        $this->assertSame(0, (int) $petty->refresh()->balance);
    }

    public function test_an_expense_paid_on_the_spot_from_a_short_box_is_refused_and_not_recorded(): void
    {
        $this->seed(\Database\Seeders\ExpenseCategorySeeder::class);
        $fuel = ExpenseCategory::where('code', 'fuel')->firstOrFail();

        $e = $this->refusal(fn () => app(RecordExpense::class)->handle([
            'expense_category_id' => $fuel->id, 'amount' => 40_000, 'description' => 'وقود',
            'spent_on' => today()->toDateString(), 'pay_now' => true, 'cash_box_id' => $this->box->id,
        ], $this->staff));

        $this->assertStringStartsWith('لا يمكن إتمام دفع المصروف', $e->errors()['cash_box'][0]);
        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Expense::count()));
        $this->assertSame(0, (int) $this->box->refresh()->balance);
    }

    public function test_a_pickup_commission_the_box_cannot_cover_is_refused(): void
    {
        $agent = Tenancy::runFor($this->company, function () {
            $agent = Courier::create(['code' => 'P1', 'name' => 'سعد الجبوري', 'phone' => '07730000001', 'type' => 'pickup', 'status' => 'active', 'commission_per_pickup' => 500]);
            $agent->forceFill(['commission_balance' => 6_000])->save();

            return $agent;
        });

        $e = $this->refusal(fn () => app(PayPickupCommission::class)->handle($agent, $this->staff, $this->box));

        $this->assertStringStartsWith('لا يمكن إتمام دفع العمولة، رصيد الصندوق غير كافٍ.', $e->errors()['cash_box'][0]);
        Tenancy::runFor($this->company, function () use ($agent) {
            $this->assertSame(6_000, (int) $agent->refresh()->commission_balance, 'العمولة تبقى مستحقّة');
            $this->assertSame(0, PickupPayout::count());
        });
    }

    public function test_paying_a_pickup_commission_without_a_box_moves_no_cash(): void
    {
        $agent = Tenancy::runFor($this->company, function () {
            $agent = Courier::create(['code' => 'P1', 'name' => 'سعد الجبوري', 'phone' => '07730000001', 'type' => 'pickup', 'status' => 'active', 'commission_per_pickup' => 500]);
            $agent->forceFill(['commission_balance' => 6_000])->save();

            return $agent;
        });

        // «بلا صندوق» اختيارٌ صريح: قيدٌ محاسبيّ لا يمسّ درج الدافع
        $this->actingAs($this->staff)
            ->post($this->host()."/pickup-agents/{$agent->id}/pay", ['cash_box_id' => ''])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($agent) {
            $this->assertSame(0, (int) $agent->refresh()->commission_balance);
            $this->assertNull(PickupPayout::firstOrFail()->cash_box_id);
            $this->assertSame(0, CashMovement::count());
        });
    }

    public function test_a_deposit_refund_needs_the_cash_in_the_box(): void
    {
        Tenancy::runFor($this->company, function () {
            app(ManageDeposit::class)->deposit($this->merchant, 100_000, $this->staff, $this->box);
            // التأمين صُرف من الدرج في غيره
            app(CashBook::class)->out($this->box, 'expense', 80_000, 'إيجار', $this->staff);
        });

        $e = $this->refusal(fn () => app(ManageDeposit::class)->refund($this->merchant, 50_000, $this->staff, $this->box));

        $this->assertStringStartsWith('لا يمكن إتمام ردّ التأمين، رصيد الصندوق غير كافٍ.', $e->errors()['cash_box'][0]);
        Tenancy::runFor($this->company, fn () => $this->assertSame(100_000, (int) $this->merchant->refresh()->deposit_balance));
        $this->assertSame(20_000, (int) $this->box->refresh()->balance);
    }

    public function test_a_courier_sheet_whose_commission_the_box_cannot_pay_stays_open(): void
    {
        $this->deliver(0);   // طلبٌ مدفوعٌ سلفاً: لا نقد بيد المندوب، وله عمولته

        $sheet = Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier, $this->staff));
        $e = $this->refusal(fn () => app(ConfirmCourierSettlement::class)->handle($sheet, $this->staff));

        $this->assertStringStartsWith('لا يمكن إتمام دفع العمولة، رصيد الصندوق غير كافٍ.', $e->errors()['cash_box'][0]);
        Tenancy::runFor($this->company, function () use ($sheet) {
            $this->assertSame('draft', $sheet->refresh()->status);
            $this->assertSame(0, CashMovement::count());
        });
    }

    public function test_a_transfer_is_checked_in_the_book_too_not_only_on_the_screen(): void
    {
        $petty = $this->makeBox('PETTY', 'النثريّة', 'petty');

        $e = $this->refusal(fn () => app(CashBook::class)->transfer($this->box, $petty, 10_000, null, $this->staff));

        $this->assertStringStartsWith('لا يمكن إتمام المناقلة، رصيد الصندوق غير كافٍ.', $e->errors()['cash_box'][0]);
        Tenancy::runFor($this->company, fn () => $this->assertSame(0, CashMovement::count(), 'لا نصف مناقلة'));
    }

    public function test_two_payments_from_the_same_stale_box_cannot_overdraw_it(): void
    {
        $petty = $this->makeBox('PETTY', 'النثريّة', 'petty', 100_000);
        $stale = CashBox::withoutGlobalScopes()->findOrFail($petty->id);   // نسخةٌ قُرئت قبل الدفعة الأولى

        Tenancy::runFor($this->company, fn () => app(CashBook::class)->out($petty, 'expense', 70_000, 'أولى', $this->staff));

        // الثانية تحمل الرصيد القديم (١٠٠ ألف)، والفحص يقرأ الصفّ بعد قفله
        $this->refusal(fn () => app(CashBook::class)->out($stale, 'expense', 70_000, 'ثانية', $this->staff));

        $this->assertSame(30_000, (int) $petty->refresh()->balance);
    }

    public function test_a_box_already_below_zero_takes_cash_in_but_gives_none_out(): void
    {
        // رصيدٌ سالب من دفعاتٍ سبقت هذا المنع
        Tenancy::runFor($this->company, fn () => $this->box->forceFill(['balance' => -50_000])->save());

        Tenancy::runFor($this->company, fn () => app(CashBook::class)->in($this->box, 'courier_handover', 20_000, null, $this->staff));
        $this->assertSame(-30_000, (int) $this->box->refresh()->balance);

        $e = $this->refusal(fn () => app(CashBook::class)->out($this->box, 'merchant_payout', 1_000, null, $this->staff));
        $this->assertStringContainsString('رصيد «القاصة الرئيسية» تحت الصفر بـ30,000 د.ع', $e->errors()['cash_box'][0]);

        $this->actingAs($this->staff)->get($this->host().'/cash?box_id='.$this->box->id)
            ->assertOk()
            ->assertSee('رصيد هذا الصندوق تحت الصفر بـ')
            ->assertSee('جرد الصندوق');
    }

    /**
     * والمندوب يأخذ عمولته ممّا سلّمه قبل أن تُمسّ نقود الدرج: صندوقٌ تحت
     * الصفر من قبلُ يستقبل كشفه ويرتفع، ولا يُحبَس عن أوّل ما يُصلحه.
     */
    public function test_a_box_below_zero_still_takes_a_courier_handover_that_covers_its_commission(): void
    {
        Tenancy::runFor($this->company, fn () => $this->box->forceFill(['balance' => -500_000])->save());
        $this->deliver(100_000);

        $sheet = Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier, $this->staff));
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle($sheet, $this->staff));

        Tenancy::runFor($this->company, function () use ($sheet) {
            $this->assertSame('confirmed', $sheet->refresh()->status);
            $this->assertSame(1_500, (int) $sheet->commission_total);
            // ١٠٠ ألف دخلت، وخرجت منها عمولته: ٩٨٬٥٠٠ أقلّ ممّا كان تحت الصفر
            $this->assertSame(-401_500, (int) $this->box->refresh()->balance);
            $this->assertSame(['courier_handover', 'commission_paid'], CashMovement::orderBy('id')->pluck('category')->all());
        });
    }

    /** وعمولةٌ أكبر ممّا سلّمه يُدفع فرقها من الدرج: فيُسأل عنه الرصيد. */
    public function test_the_part_of_a_commission_beyond_the_handover_needs_cash_in_the_box(): void
    {
        Tenancy::runFor($this->company, fn () => app(CashBook::class)->in($this->box, 'opening', 1_000, null, $this->staff));
        $this->deliver(0);   // مدفوعٌ سلفاً: لا نقد بيده، وعمولته ١٬٥٠٠

        $sheet = Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier, $this->staff));
        $this->refusal(fn () => app(ConfirmCourierSettlement::class)->handle($sheet, $this->staff));

        Tenancy::runFor($this->company, fn () => app(CashBook::class)->in($this->box, 'opening', 500, null, $this->staff));
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle($sheet->refresh(), $this->staff));

        $this->assertSame(0, (int) $this->box->refresh()->balance);
    }
}

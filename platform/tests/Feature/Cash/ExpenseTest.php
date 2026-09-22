<?php

namespace Tests\Feature\Cash;

use App\Actions\Cash\RecordExpense;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\CashBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * المصروفات. مصروف مسجَّل ومصروف مدفوع ليسا واحداً: الأوّل التزام،
 * والثاني نقد غادر الدرج. خلطهما يجعل رصيد الصندوق رقماً لا يُقارَن
 * بعدّ اليد.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->seed(\Database\Seeders\ExpenseCategorySeeder::class);

        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);

        $this->box = Tenancy::runFor($this->company, fn () => CashBox::create([
            'code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main',
            'balance' => 0, 'is_active' => true,
        ]));

        Tenancy::runFor($this->company, fn () => app(CashBook::class)
            ->in($this->box, 'opening', 2_000_000, 'رصيد افتتاحي', $this->staff));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function category(string $code = 'fuel'): ExpenseCategory
    {
        return ExpenseCategory::where('code', $code)->firstOrFail();
    }

    private function record(array $overrides = []): Expense
    {
        return Tenancy::runFor($this->company, fn () => app(RecordExpense::class)->handle(array_merge([
            'expense_category_id' => $this->category()->id,
            'amount'              => 150_000,
            'description'         => 'تعبئة وقود لسيارة التوزيع',
            'spent_on'            => today()->toDateString(),
        ], $overrides), $this->staff));
    }

    // ── التسجيل والدفع خطوتان ───────────────────────────────────────

    public function test_a_recorded_expense_does_not_touch_the_drawer(): void
    {
        $expense = $this->record();

        $this->assertSame('recorded', $expense->status);
        $this->assertNull($expense->paid_at);

        Tenancy::runFor($this->company, function () {
            $this->assertSame(2_000_000, (int) $this->box->refresh()->balance, 'الالتزام ليس نقداً غادر');
            $this->assertSame(0, CashMovement::where('category', 'expense')->count());
        });
    }

    public function test_paying_an_expense_takes_it_out_of_the_drawer(): void
    {
        $expense = $this->record();

        Tenancy::runFor($this->company, fn () => app(RecordExpense::class)
            ->pay($expense, $this->box, $this->staff));

        Tenancy::runFor($this->company, function () use ($expense) {
            $fresh = $expense->refresh();

            $this->assertSame('paid', $fresh->status);
            $this->assertSame($this->box->id, (int) $fresh->cash_box_id);
            $this->assertSame($this->staff->id, $fresh->paid_by_user_id);
            $this->assertSame(1_850_000, (int) $this->box->refresh()->balance);

            $movement = CashMovement::where('reference_type', 'expense')
                ->where('reference_id', $fresh->id)->firstOrFail();

            $this->assertSame('out', $movement->direction);
            $this->assertSame(150_000, (int) $movement->amount);
        });
    }

    public function test_pay_now_records_and_pays_in_one_step(): void
    {
        $expense = $this->record(['pay_now' => true]);

        $this->assertSame('paid', $expense->status);

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            1_850_000, (int) $this->box->refresh()->balance,
        ));
    }

    public function test_an_expense_cannot_be_paid_twice(): void
    {
        $expense = $this->record(['pay_now' => true]);

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(RecordExpense::class)
            ->pay($expense->refresh(), $this->box, $this->staff));
    }

    public function test_a_second_payment_attempt_leaves_the_drawer_alone(): void
    {
        $expense = $this->record(['pay_now' => true]);

        try {
            Tenancy::runFor($this->company, fn () => app(RecordExpense::class)
                ->pay($expense->refresh(), $this->box, $this->staff));
        } catch (ValidationException) {
            // متوقّع
        }

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            1_850_000, (int) $this->box->refresh()->balance,
        ));
    }

    // ── الإلغاء ─────────────────────────────────────────────────────

    public function test_cancelling_a_paid_expense_returns_the_money_by_a_reverse_movement(): void
    {
        $expense = $this->record(['pay_now' => true]);

        Tenancy::runFor($this->company, fn () => app(RecordExpense::class)
            ->cancel($expense->refresh(), 'الفاتورة مكرّرة', $this->staff));

        Tenancy::runFor($this->company, function () use ($expense) {
            $this->assertSame('cancelled', $expense->refresh()->status);
            $this->assertSame(2_000_000, (int) $this->box->refresh()->balance);

            // لا حذف لحركة: الخروج والعودة كلاهما مقروء في الجرد
            $movements = CashMovement::where('reference_type', 'expense')
                ->where('reference_id', $expense->id)->orderBy('id')->get();

            $this->assertCount(2, $movements);
            $this->assertSame(['out', 'in'], $movements->pluck('direction')->all());
            $this->assertStringContainsString('الفاتورة مكرّرة', $movements->last()->description);
            $this->assertTrue(app(CashBook::class)->reconcile($this->box)['matches']);
        });
    }

    public function test_cancelling_an_unpaid_expense_moves_no_cash(): void
    {
        $expense = $this->record();

        Tenancy::runFor($this->company, fn () => app(RecordExpense::class)
            ->cancel($expense, 'أُلغي الطلب', $this->staff));

        Tenancy::runFor($this->company, function () {
            $this->assertSame(2_000_000, (int) $this->box->refresh()->balance);
            $this->assertSame(0, CashMovement::where('category', 'expense')->count());
        });
    }

    // ── الشاشة ──────────────────────────────────────────────────────

    public function test_staff_record_an_expense_from_the_screen(): void
    {
        $this->actingAs($this->staff)
            ->post($this->host().'/expenses', [
                'expense_category_id' => $this->category('salaries')->id,
                'amount'              => 900_000,
                'spent_on'            => today()->toDateString(),
                'description'         => 'راتب موظّف الفرز',
                'payee'               => 'سعد جبار',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () {
            $expense = Expense::firstOrFail();
            $this->assertSame(900_000, (int) $expense->amount);
            $this->assertSame('recorded', $expense->status);
            $this->assertStringStartsWith('EX', $expense->number);
        });
    }

    public function test_the_screen_refuses_to_pay_more_than_the_box_holds(): void
    {
        $expense = $this->record(['amount' => 5_000_000]);

        $this->actingAs($this->staff)
            ->post($this->host()."/expenses/{$expense->id}/pay", ['cash_box_id' => $this->box->id])
            ->assertSessionHasErrors('cash_box_id');

        Tenancy::runFor($this->company, function () use ($expense) {
            $this->assertSame('recorded', $expense->refresh()->status);
            $this->assertSame(2_000_000, (int) $this->box->refresh()->balance);
        });
    }

    public function test_a_future_dated_expense_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->post($this->host().'/expenses', [
                'expense_category_id' => $this->category()->id,
                'amount'              => 10_000,
                'spent_on'            => today()->addDay()->toDateString(),
                'description'         => 'مصروف الغد',
            ])
            ->assertSessionHasErrors('spent_on');
    }

    public function test_the_screen_totals_the_period_and_names_the_biggest_category(): void
    {
        $this->record(['amount' => 150_000]);
        $this->record(['expense_category_id' => $this->category('rent')->id, 'amount' => 800_000, 'pay_now' => true]);

        $this->actingAs($this->staff)
            ->get($this->host().'/expenses')
            ->assertOk()
            ->assertSee('950,000')          // المجموع
            ->assertSee('150,000')          // غير المدفوع
            ->assertSee('إيجار مخزن أو مكتب');
    }

    public function test_an_expense_category_of_another_company_is_refused(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $theirs = ExpenseCategory::create([
            'company_id' => $other->id, 'code' => 'private', 'name_ar' => 'باب خاصّ بالبرق',
            'group' => 'overhead', 'is_active' => true,
        ]);

        $this->actingAs($this->staff)
            ->post($this->host().'/expenses', [
                'expense_category_id' => $theirs->id,
                'amount'              => 10_000,
                'spent_on'            => today()->toDateString(),
                'description'         => 'محاولة',
            ])
            ->assertSessionHasErrors('expense_category_id');

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Expense::count()));
    }

    public function test_one_company_cannot_pay_another_companys_expense(): void
    {
        $expense = $this->record();

        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);

        $this->actingAs($otherStaff)
            ->post('http://barq.'.config('zajel.tenant_domain')."/expenses/{$expense->id}/pay", [
                'cash_box_id' => $this->box->id,
            ])
            ->assertNotFound();

        Tenancy::runFor($this->company, fn () => $this->assertSame('recorded', $expense->refresh()->status));
    }
}

<?php

namespace Tests\Feature\Cash;

use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\User;
use App\Services\CashBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * القاصة. دفتر الحركات يقول مَن له ومَن عليه؛ هذا يقول كم في الدرج.
 * الرقم الوحيد الذي يُقارَن بعدّ اليد آخر الدوام، فإن كذب كذب كلّ شيء.
 */
class CashBoxTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
        $this->box = $this->makeBox('MAIN', 'القاصة الرئيسية');
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function makeBox(string $code, string $name, string $type = 'main'): CashBox
    {
        return Tenancy::runFor($this->company, fn () => CashBox::create([
            'code' => $code, 'name' => $name, 'type' => $type, 'balance' => 0, 'is_active' => true,
        ]));
    }

    private function cash(): CashBook
    {
        return app(CashBook::class);
    }

    // ── الدفتر ──────────────────────────────────────────────────────

    public function test_a_movement_writes_the_balance_it_left_behind(): void
    {
        Tenancy::runFor($this->company, function () {
            $one = $this->cash()->in($this->box, 'opening', 500_000, 'رصيد افتتاحي', $this->staff);
            $two = $this->cash()->out($this->box, 'expense', 120_000, 'وقود', $this->staff);

            $this->assertSame(500_000, (int) $one->balance_after);
            $this->assertSame(380_000, (int) $two->balance_after);
            $this->assertSame(380_000, (int) $this->box->refresh()->balance);
        });
    }

    public function test_the_stored_balance_always_matches_the_movements(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->cash()->in($this->box, 'opening', 1_000_000, null, $this->staff);
            $this->cash()->out($this->box, 'expense', 250_000, null, $this->staff);
            $this->cash()->in($this->box, 'courier_handover', 780_000, null, $this->staff);
            $this->cash()->out($this->box, 'merchant_payout', 600_000, null, $this->staff);

            $check = $this->cash()->reconcile($this->box);

            $this->assertTrue($check['matches']);
            $this->assertSame(0, $check['drift']);
            $this->assertSame(930_000, $check['stored']);
        });
    }

    public function test_a_zero_movement_leaves_no_row(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->assertNull($this->cash()->in($this->box, 'opening', 0, null, $this->staff));
            $this->assertSame(0, CashMovement::where('cash_box_id', $this->box->id)->count());
        });
    }

    public function test_a_negative_amount_is_refused_rather_than_flipping_the_direction(): void
    {
        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => $this->cash()
            ->in($this->box, 'opening', -5_000, null, $this->staff));
    }

    public function test_a_transfer_is_two_linked_movements_not_one(): void
    {
        $other = $this->makeBox('BSR', 'صندوق البصرة', 'branch');

        Tenancy::runFor($this->company, function () use ($other) {
            $this->cash()->in($this->box, 'opening', 900_000, null, $this->staff);

            [$sent, $received] = $this->cash()->transfer($this->box, $other, 300_000, null, $this->staff);

            $this->assertSame(600_000, (int) $this->box->refresh()->balance);
            $this->assertSame(300_000, (int) $other->refresh()->balance);

            // المال لم يظهر من العدم: كل طرف يشير إلى الآخر
            $this->assertSame($other->id, (int) $sent->counterpart_box_id);
            $this->assertSame($this->box->id, (int) $received->counterpart_box_id);
            $this->assertSame('out', $sent->direction);
            $this->assertSame('in', $received->direction);
        });
    }

    public function test_a_transfer_to_the_same_box_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => $this->cash()
            ->transfer($this->box, $this->box, 1_000, null, $this->staff));
    }

    public function test_a_stocktake_difference_is_posted_not_written_over(): void
    {
        Tenancy::runFor($this->company, fn () => $this->cash()
            ->in($this->box, 'opening', 500_000, null, $this->staff));

        // عُدّ الدرج فوُجد أنقص بعشرين ألفاً
        $this->actingAs($this->staff)
            ->post($this->host()."/cash/{$this->box->id}/adjust", [
                'counted' => 480_000,
                'reason'  => 'نقص عند جرد المساء',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () {
            $this->assertSame(480_000, (int) $this->box->refresh()->balance);

            $adjustment = CashMovement::where('cash_box_id', $this->box->id)
                ->where('category', 'adjustment')->firstOrFail();

            $this->assertSame('out', $adjustment->direction);
            $this->assertSame(20_000, (int) $adjustment->amount);
            $this->assertStringContainsString('نقص عند جرد المساء', $adjustment->description);
            $this->assertTrue($this->cash()->reconcile($this->box)['matches']);
        });
    }

    public function test_a_matching_stocktake_writes_nothing(): void
    {
        Tenancy::runFor($this->company, fn () => $this->cash()
            ->in($this->box, 'opening', 500_000, null, $this->staff));

        $this->actingAs($this->staff)
            ->post($this->host()."/cash/{$this->box->id}/adjust", [
                'counted' => 500_000, 'reason' => 'جرد يومي',
            ]);

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            0,
            CashMovement::where('cash_box_id', $this->box->id)->where('category', 'adjustment')->count(),
        ));
    }

    // ── الشاشة ──────────────────────────────────────────────────────

    public function test_the_screen_shows_the_balance_and_the_days_movement(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->cash()->in($this->box, 'courier_handover', 750_000, 'تسليم نقد', $this->staff);
            $this->cash()->out($this->box, 'expense', 50_000, 'وقود', $this->staff);
        });

        $this->actingAs($this->staff)
            ->get($this->host().'/cash')
            ->assertOk()
            ->assertSee('700,000')
            ->assertSee('750,000')
            ->assertSee('تسليم نقد من مندوب');
    }

    public function test_an_opening_balance_is_recorded_as_a_movement(): void
    {
        $this->actingAs($this->staff)
            ->post($this->host().'/cash', [
                'name' => 'صندوق نثريّة', 'code' => 'PETTY', 'type' => 'petty', 'opening' => 200_000,
            ])
            ->assertRedirect();

        Tenancy::runFor($this->company, function () {
            $box = CashBox::where('code', 'PETTY')->firstOrFail();

            $this->assertSame(200_000, (int) $box->balance);
            $this->assertSame(1, CashMovement::where('cash_box_id', $box->id)
                ->where('category', 'opening')->count(), 'الرصيد الافتتاحي حركة لا قيمة ابتدائية');
        });
    }

    public function test_a_duplicate_box_code_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->post($this->host().'/cash', ['name' => 'آخر', 'code' => 'MAIN', 'type' => 'petty'])
            ->assertSessionHasErrors('code');
    }

    public function test_a_transfer_larger_than_the_balance_is_refused(): void
    {
        $other = $this->makeBox('BSR', 'صندوق البصرة', 'branch');

        Tenancy::runFor($this->company, fn () => $this->cash()
            ->in($this->box, 'opening', 100_000, null, $this->staff));

        $this->actingAs($this->staff)
            ->post($this->host().'/cash/transfer', [
                'from_box_id' => $this->box->id, 'to_box_id' => $other->id, 'amount' => 500_000,
            ])
            ->assertSessionHasErrors('amount');

        Tenancy::runFor($this->company, function () use ($other) {
            $this->assertSame(100_000, (int) $this->box->refresh()->balance);
            $this->assertSame(0, (int) $other->refresh()->balance);
        });
    }

    public function test_a_merchant_login_cannot_see_the_cash_box(): void
    {
        $merchant = $this->makeMerchant($this->company);
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->get($this->host().'/cash')->assertForbidden();
    }

    public function test_one_company_cannot_adjust_another_companys_box(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);

        $this->actingAs($otherStaff)
            ->post('http://barq.'.config('zajel.tenant_domain')."/cash/{$this->box->id}/adjust", [
                'counted' => 999_000, 'reason' => 'محاولة',
            ])
            ->assertNotFound();

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, (int) $this->box->refresh()->balance));
    }
}

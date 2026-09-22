<?php

namespace Tests\Feature\Cash;

use App\Actions\Cash\ManageDeposit;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantDeposit;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تأمينات التجّار.
 *
 * التأمين مالُ التاجر محجوزاً عندنا لا مالُنا: يُردّ كاملاً إن انتهت
 * العلاقة بلا خلاف. فخلطه برصيد الحساب يُظهر للتاجر مالاً يملكه وهو
 * غير قابل للسحب، وخصمُه بلا سبب مكتوب خلافٌ مؤجَّل إلى يوم المطالبة.
 */
class MerchantDepositTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company, 'M0001');
        $this->staff = $this->makeUser($this->company);

        $this->box = Tenancy::runFor($this->company, fn () => CashBox::create([
            'code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main',
            'balance' => 0, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deposits(): ManageDeposit
    {
        return app(ManageDeposit::class);
    }

    // ── الحركة ──────────────────────────────────────────────────────

    public function test_a_deposit_raises_the_held_balance_and_enters_the_drawer(): void
    {
        Tenancy::runFor($this->company, function () {
            $row = $this->deposits()->deposit($this->merchant, 250_000, $this->staff, $this->box);

            $this->assertSame(250_000, $row->balance_after);
            $this->assertSame(250_000, (int) $this->merchant->fresh()->deposit_balance);
            $this->assertSame(250_000, (int) $this->box->fresh()->balance);
        });
    }

    /** التأمين محجوزٌ لا مدفوع: رصيد الحساب لا يتحرّك به. */
    public function test_a_deposit_never_touches_the_account_balance(): void
    {
        Tenancy::runFor($this->company, function () {
            $before = (int) $this->merchant->balance;

            $this->deposits()->deposit($this->merchant, 250_000, $this->staff, $this->box);

            $this->assertSame($before, (int) $this->merchant->fresh()->balance);
        });
    }

    public function test_a_refund_lowers_the_held_balance_and_leaves_the_drawer(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->deposits()->deposit($this->merchant, 250_000, $this->staff, $this->box);
            $row = $this->deposits()->refund($this->merchant, 100_000, $this->staff, $this->box);

            $this->assertSame(150_000, $row->balance_after);
            $this->assertSame(150_000, (int) $this->box->fresh()->balance);
        });
    }

    /** الخصم يحوّل المال إلى إيراد ولا يُخرجه من الدرج. */
    public function test_a_forfeit_lowers_the_deposit_without_moving_cash(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->deposits()->deposit($this->merchant, 250_000, $this->staff, $this->box);
            $row = $this->deposits()->forfeit($this->merchant, 60_000, 'شحنات تالفة', $this->staff);

            $this->assertSame(190_000, $row->balance_after);
            $this->assertSame(250_000, (int) $this->box->fresh()->balance);
            $this->assertSame('شحنات تالفة', $row->reason);
        });
    }

    // ── ما لا يمرّ ──────────────────────────────────────────────────

    public function test_a_forfeit_without_a_reason_is_refused(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->deposits()->deposit($this->merchant, 100_000, $this->staff, $this->box);

            $this->expectException(ValidationException::class);
            $this->deposits()->forfeit($this->merchant, 10_000, '   ', $this->staff);
        });
    }

    public function test_more_cannot_be_taken_out_than_was_put_in(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->deposits()->deposit($this->merchant, 100_000, $this->staff, $this->box);

            try {
                $this->deposits()->refund($this->merchant, 100_001, $this->staff, $this->box);
                $this->fail('ردٌّ أكبر من التأمين يجب أن يُرفض.');
            } catch (ValidationException) {
                $this->assertSame(100_000, (int) $this->merchant->fresh()->deposit_balance);
            }
        });
    }

    /**
     * الغطاء يُفحَص بعد قفل الصفّ لا قبله.
     *
     * الفحص على نسخةٍ قديمة يمرّ لطلبين متزامنين فيخرج تأمينٌ أكثر
     * ممّا أُودع. وتُحاكى المسابقة بنسختين من النموذج قُرئتا قبل الردّ.
     */
    public function test_a_stale_copy_cannot_overdraw_the_deposit(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->deposits()->deposit($this->merchant, 100_000, $this->staff, $this->box);

            $first = Merchant::findOrFail($this->merchant->id);
            $second = Merchant::findOrFail($this->merchant->id);

            $this->deposits()->refund($first, 100_000, $this->staff, $this->box);

            try {
                // $second ما زال يحمل ١٠٠ ألف — والقفل يقرأ الصفر
                $this->deposits()->refund($second, 100_000, $this->staff, $this->box);
                $this->fail('الردّ الثاني على نسخة قديمة يجب أن يُرفض.');
            } catch (ValidationException) {
                $this->assertSame(0, (int) $this->merchant->fresh()->deposit_balance);
                $this->assertSame(0, (int) $this->box->fresh()->balance);
            }
        });
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        Tenancy::runFor($this->company, function () {
            foreach ([0, -5_000] as $amount) {
                try {
                    $this->deposits()->deposit($this->merchant, $amount, $this->staff, $this->box);
                    $this->fail("المبلغ {$amount} يجب أن يُرفض.");
                } catch (ValidationException) {
                    $this->assertSame(0, (int) $this->merchant->fresh()->deposit_balance);
                }
            }
        });
    }

    // ── الأثر ───────────────────────────────────────────────────────

    /** كل حركة تحفظ الرصيد الذي خلّفته: الكشف يُقرأ ولا يُعاد حسابه. */
    public function test_every_movement_records_the_balance_it_left_behind(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->deposits()->deposit($this->merchant, 100_000, $this->staff, $this->box);
            $this->deposits()->deposit($this->merchant, 50_000, $this->staff, $this->box);
            $this->deposits()->refund($this->merchant, 30_000, $this->staff, $this->box);

            $this->assertSame(
                [100_000, 150_000, 120_000],
                MerchantDeposit::orderBy('id')->pluck('balance_after')->map(fn ($v) => (int) $v)->all(),
            );
        });
    }

    // ── الشاشة ──────────────────────────────────────────────────────

    public function test_the_screen_holds_the_company_total(): void
    {
        Tenancy::runFor($this->company, fn () => $this->deposits()
            ->deposit($this->merchant, 175_000, $this->staff, $this->box));

        $this->assertSame(175_000, $this->actingAs($this->staff)
            ->get($this->host().'/branch-accounts/deposits')
            ->assertOk()
            ->viewData('held'));
    }

    public function test_a_forfeit_posted_from_the_screen_needs_its_reason(): void
    {
        Tenancy::runFor($this->company, fn () => $this->deposits()
            ->deposit($this->merchant, 100_000, $this->staff, $this->box));

        $this->actingAs($this->staff)
            ->from($this->host().'/branch-accounts/deposits')
            ->post($this->host().'/branch-accounts/deposits', [
                'merchant_id' => $this->merchant->id, 'kind' => 'forfeit', 'amount' => 20_000,
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(100_000, (int) $this->merchant->fresh()->deposit_balance);
    }

    public function test_one_companys_deposits_never_appear_in_anothers_screen(): void
    {
        Tenancy::runFor($this->company, fn () => $this->deposits()
            ->deposit($this->merchant, 400_000, $this->staff, $this->box));

        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);

        $this->assertSame(0, $this->actingAs($otherStaff)
            ->get('http://barq.'.config('zajel.tenant_domain').'/branch-accounts/deposits')
            ->assertOk()
            ->viewData('held'));
    }
}

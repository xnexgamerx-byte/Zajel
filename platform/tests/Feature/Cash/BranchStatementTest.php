<?php

namespace Tests\Feature\Cash;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\User;
use App\Services\CashBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كشف حساب الفرع.
 *
 * رقمٌ واحد في كشف الحساب إن كذب كذب الكشف كلّه: الافتتاحيّ زائد
 * الوارد ناقص الصادر يساوي الختاميّ، والختاميّ يساوي ما في الصناديق.
 */
class BranchStatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $baghdad;

    private Branch $basra;

    private CashBox $main;

    private CashBox $petty;

    private CashBox $basraBox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->makeMerchant($this->company);   // يُنشئ الفرع B1
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () {
            $this->baghdad = Branch::where('code', 'B1')->firstOrFail();
            $this->basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true]);

            $box = fn ($code, $name, $branch, $type = 'branch') => CashBox::create([
                'code' => $code, 'name' => $name, 'type' => $type,
                'branch_id' => $branch->id, 'balance' => 0, 'is_active' => true,
            ]);

            $this->main = $box('MAIN', 'قاصة بغداد', $this->baghdad, 'main');
            $this->petty = $box('PETTY', 'نثرية بغداد', $this->baghdad, 'petty');
            $this->basraBox = $box('BSR', 'قاصة البصرة', $this->basra);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function cash(): CashBook
    {
        return app(CashBook::class);
    }

    /** يُرجع الحركة إلى الماضي: الكشف يُقرأ بالتاريخ */
    private function backdate(int $days): void
    {
        Tenancy::runFor($this->company, fn () => CashMovement::where('created_at', '>=', now()->subMinute())
            ->update(['created_at' => now()->subDays($days)]));
    }

    private function statement(array $query = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->get($this->host().'/branch-accounts/statement?'.http_build_query($query + [
                'branch_id' => $this->baghdad->id,
                'from'      => now()->subDays(10)->toDateString(),
                'to'        => now()->toDateString(),
            ]));
    }

    public function test_opening_plus_in_minus_out_is_the_closing_and_matches_the_boxes(): void
    {
        Tenancy::runFor($this->company, fn () => $this->cash()->in($this->main, 'opening', 1_000_000, 'رصيد افتتاحي', $this->owner));
        $this->backdate(30);   // قبل المدّة: يصير افتتاحيّاً

        Tenancy::runFor($this->company, function () {
            $this->cash()->in($this->main, 'courier_handover', 400_000, 'تسليم مندوب', $this->owner);
            $this->cash()->out($this->main, 'expense', 150_000, 'إيجار', $this->owner);
        });

        $response = $this->statement()->assertOk();
        $totals = $response->viewData('totals');

        $this->assertSame(1_000_000, $totals->opening);
        $this->assertSame(400_000, $totals->in);
        $this->assertSame(150_000, $totals->out);
        $this->assertSame(1_250_000, $totals->closing);
        $this->assertSame(0, $response->viewData('check')->drift);
    }

    public function test_every_line_carries_the_running_balance(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->cash()->in($this->main, 'courier_handover', 300_000, null, $this->owner);
            $this->cash()->out($this->petty, 'expense', 20_000, null, $this->owner);
            $this->cash()->in($this->petty, 'courier_handover', 50_000, null, $this->owner);
        });

        $this->assertSame(
            [300_000, 280_000, 330_000],
            $this->statement()->viewData('movements')->pluck('running')->all(),
        );
    }

    /** المناقلة داخل الفرع تُغيّر صندوقين ولا تُغيّر نقد الفرع. */
    public function test_a_transfer_inside_the_branch_is_shown_but_not_counted(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->cash()->in($this->main, 'courier_handover', 500_000, null, $this->owner);
            $this->cash()->transfer($this->main, $this->petty, 100_000, null, $this->owner);
        });

        $response = $this->statement();
        $totals = $response->viewData('totals');

        $this->assertSame(500_000, $totals->in);
        $this->assertSame(0, $totals->out);
        $this->assertSame(500_000, $totals->closing);
        $this->assertSame(2, $response->viewData('movements')->where('internal', true)->count());
        $this->assertArrayNotHasKey('transfer_out', $response->viewData('summary')->all());
    }

    /** والمناقلة إلى فرعٍ آخر خروجٌ حقيقيّ من هذا الفرع. */
    public function test_a_transfer_to_another_branch_is_money_leaving(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->cash()->in($this->main, 'courier_handover', 500_000, null, $this->owner);
            $this->cash()->transfer($this->main, $this->basraBox, 200_000, null, $this->owner);
        });

        $totals = $this->statement()->viewData('totals');

        $this->assertSame(200_000, $totals->out);
        $this->assertSame(300_000, $totals->closing);
    }

    public function test_another_branchs_movements_are_not_in_this_statement(): void
    {
        Tenancy::runFor($this->company, fn () => $this->cash()->in($this->basraBox, 'courier_handover', 999_000, null, $this->owner));

        $this->assertCount(0, $this->statement()->viewData('movements'));
    }

    /** فرقٌ بين الدفتر والصندوق يُقال: حركةٌ لم تمرّ بالدفتر. */
    public function test_a_box_changed_outside_the_book_shows_as_drift(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->cash()->in($this->main, 'courier_handover', 100_000, null, $this->owner);
            CashBox::whereKey($this->main->id)->update(['balance' => 130_000]);
        });

        $this->assertSame(30_000, $this->statement()->viewData('check')->drift);
    }

    /** كشفٌ ينتهي في الماضي لا يُقابَل بصناديق اليوم. */
    public function test_a_past_statement_is_not_checked_against_todays_boxes(): void
    {
        $this->assertNull($this->statement([
            'from' => now()->subDays(60)->toDateString(),
            'to'   => now()->subDays(30)->toDateString(),
        ])->viewData('check'));
    }

    public function test_a_branch_limited_user_sees_only_their_branch_whatever_the_link_asks(): void
    {
        $accountant = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'محاسب البصرة', 'phone' => '07790000011', 'password' => 'password',
            'role' => UserRole::Accountant, 'branch_id' => $this->basra->id, 'is_active' => true,
        ]));

        $response = $this->statement(['branch_id' => $this->baghdad->id], $accountant)->assertOk();

        $this->assertSame($this->basra->id, $response->viewData('branch')->id);
        $this->assertSame([$this->basra->id], $response->viewData('branches')->pluck('id')->values()->all());
    }

    public function test_the_printed_statement_carries_the_same_numbers(): void
    {
        Tenancy::runFor($this->company, fn () => $this->cash()->in($this->main, 'courier_handover', 275_000, null, $this->owner));

        $this->actingAs($this->owner)
            ->get($this->host().'/branch-accounts/statement/print?branch_id='.$this->baghdad->id)
            ->assertOk()
            ->assertSee('275,000')
            ->assertSee('كشف حساب فرع');
    }
}

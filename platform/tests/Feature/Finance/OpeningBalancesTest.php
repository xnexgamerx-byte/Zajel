<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Finance\JournalCheck;
use App\Services\Finance\OpeningBalances;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * القيد الافتتاحي (docs/plan/68 ق١٣): أرصدةٌ مسجَّلة بلا حركاتٍ تبنيها — كأنها نُقلت من نظامٍ
 * قديم — تُقيَّد مرّةً بقيدٍ افتتاحيٍّ طرفه 3100، فتطابق المطابقة ويتوازن الميزان. ولا يُكتب
 * قيدٌ ثانٍ بلا فرق، ولا يكتبه إلا صاحب الشركة.
 */
class OpeningBalancesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->box] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);

        // أرصدةٌ من النظام القديم: بلا حركةٍ واحدة تشرحها
        DB::table('cash_boxes')->where('id', $this->box->id)->update(['balance' => 900_000]);
        DB::table('couriers')->where('id', $this->courier->id)->update(['cash_in_hand' => 120_000, 'commission_balance' => 15_000]);
        DB::table('merchants')->where('id', $this->merchant->id)->update(['balance' => 300_000]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function checks(): array
    {
        return Tenancy::runFor($this->company, fn () => collect(app(JournalCheck::class)->run())->pluck('status', 'key')->all());
    }

    public function test_the_opening_entry_books_every_difference_against_capital(): void
    {
        $rows = Tenancy::runFor($this->company, fn () => collect(app(OpeningBalances::class)->differences())->keyBy('kind'));
        $this->assertSame(900_000, $rows['box']['diff']);
        $this->assertSame(120_000, $rows['custody']['diff']);
        $this->assertSame(-15_000, $rows['commission']['diff']);
        $this->assertSame(-300_000, $rows['merchant']['diff']);
        $this->assertSame('fail', $this->checks()['boxes']);

        $entry = Tenancy::runFor($this->company, fn () => app(OpeningBalances::class)->post(now()->startOfDay(), 'أرصدة النظام القديم', $this->owner));

        $checks = $this->checks();
        foreach (['boxes', 'custody', 'commissions', 'merchants', 'balanced', 'chain', 'audit_chain'] as $key) {
            $this->assertSame('ok', $checks[$key], $key);
        }
        // النقد والعهدة مدينان، والعمولة والتاجر دائنان، والفرق رأس مال
        $opening = (int) DB::table('journal_lines')->where('journal_entry_id', $entry)->where('account', A::OPENING)->sum(DB::raw('credit - debit'));
        $this->assertSame(900_000 + 120_000 - 15_000 - 300_000, $opening);
        $this->assertSame([], Tenancy::runFor($this->company, fn () => app(OpeningBalances::class)->differences()));

        try {
            Tenancy::runFor($this->company, fn () => app(OpeningBalances::class)->post(now(), 'مرّة ثانية', $this->owner));
            $this->fail('قيدٌ افتتاحيٌّ بلا فرق.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening', $e->errors());
        }
    }

    public function test_the_page_lists_differences_and_only_the_owner_posts(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/finance/opening')->assertOk()
            ->assertSee('الأرصدة الافتتاحية')->assertSee('القاصة الرئيسية')->assertSee('اكتب القيد الافتتاحي');

        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        $this->actingAs($accountant)->get($this->host().'/finance/opening')->assertOk()->assertDontSee('اكتب القيد الافتتاحي');
        $this->actingAs($accountant)->post($this->host().'/finance/opening', ['opened_on' => now()->toDateString(), 'reason' => 'x'])->assertForbidden();

        $this->actingAs($this->owner)->post($this->host().'/finance/opening', ['opened_on' => now()->toDateString(), 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post($this->host().'/finance/opening', ['opened_on' => now()->toDateString(), 'reason' => 'أرصدة النظام القديم'])
            ->assertRedirect();

        $this->actingAs($this->owner)->get($this->host().'/finance/opening')->assertOk()->assertSee('القيود الافتتاحية المكتوبة');
        $this->assertTrue(Tenancy::runFor($this->company, fn () => \App\Models\AuditLog::where('action', 'opening_balance_posted')->exists()));
    }
}

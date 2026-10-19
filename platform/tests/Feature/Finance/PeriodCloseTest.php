<?php

namespace Tests\Feature\Finance;

use App\Actions\Cash\RecordExpense;
use App\Actions\Profits\ManageCollectionBatch;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\ExpenseCategory;
use App\Models\FinancialPeriod;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Finance\JournalCheck;
use App\Services\Finance\PeriodClose;
use App\Services\Finance\ProfitDashboard;
use App\Services\Reports\ReportPeriod;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * إقفال الفترات (docs/plan/68 §٩، المرحلة الخامسة). المقياس: لا إقفال وفحصٌ فاشل بلا استثناءٍ
 * موثّق، ولا يعتمد المُعِدّ إن فُعِّل الاعتماد المزدوج، والفترة المُقفَلة لا يُكتب قيدٌ بتاريخها —
 * وما يمسّها يُكتب اليوم ويظهر في نسخةٍ ثانية من تقريرها والأولى كما هي.
 */
class PeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private CashBox $box;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->box] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);

        // الفترة: الشهر قبل الماضي، ونشاطها فيه
        $this->from = now()->subMonthsNoOverflow(2)->startOfMonth();
        $this->to = $this->from->copy()->endOfMonth()->startOfDay();
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(int $cod, ?Courier $courier = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod, $courier) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? ($courier ?? $this->courier)->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    /** نشاط الفترة: تسليمان محاسَبان في وجبةٍ معتمدة، ومصروف */
    private function activity(): void
    {
        $this->travelTo($this->from->copy()->addDays(10)->setTime(10, 0));
        $this->deliver(50_000);
        $this->deliver(40_000);

        Tenancy::runFor($this->company, function () {
            $settlement = app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner), $this->owner, box: $this->box);
            $manage = app(ManageCollectionBatch::class);
            $manage->approve($manage->create([$settlement->id], null, 'week', now()->subDays(6), now(), null, $this->owner, $this->box->id), $this->owner);

            $category = ExpenseCategory::create(['code' => 'fuel', 'name_ar' => 'وقود', 'group' => 'operating', 'is_active' => true]);
            app(RecordExpense::class)->handle(['expense_category_id' => $category->id, 'amount' => 2_000, 'description' => 'وقود', 'pay_now' => true, 'cash_box_id' => $this->box->id], $this->owner);
        });
        $this->travelBack();
    }

    private function close(): PeriodClose
    {
        return app(PeriodClose::class);
    }

    private function balance(string $account, ?string $from = null, ?string $to = null): int
    {
        return (int) DB::table('journal_lines')->where('company_id', $this->company->id)->where('account', $account)
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->sum(DB::raw('debit - credit'));
    }

    private function expectValidation(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('توقّعت رفضاً.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, implode(' ', $e->validator->errors()->all()));
        }
    }

    public function test_a_period_is_prepared_approved_and_closed_with_its_entry_and_report(): void
    {
        $this->activity();
        $query = '?from='.$this->from->toDateString().'&to='.$this->to->toDateString();

        $this->actingAs($this->owner)->get($this->host().'/finance/periods')->assertOk()->assertSee('مراجعة ما قبل الإقفال');
        $this->actingAs($this->owner)->get($this->host().'/finance/periods/review'.$query)
            ->assertOk()->assertSee('أرقام الفترة')->assertSee('الدفتر العام = الدفاتر الفرعية')->assertSee('ميزان المراجعة للفترة');

        $this->actingAs($this->owner)->post($this->host().'/finance/periods', ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()])
            ->assertSessionHasNoErrors()->assertRedirect();

        $period = Tenancy::runFor($this->company, fn () => FinancialPeriod::firstOrFail());
        $this->assertSame(['FP-0001', 'pending_approval'], [$period->number, $period->status]);
        $net = (int) $period->figures['net']['total'];
        $this->assertGreaterThan(0, $net);

        $this->actingAs($this->owner)->post($this->host()."/finance/periods/{$period->id}/approve")->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($period, $net) {
            $period->refresh();
            $this->assertSame('closed', $period->status);
            $this->assertNotNull($period->closing_entry_id);
            $this->assertSame(1, $period->reports()->count());

            // قيد الإقفال صفّى إيرادات الفترة وتكاليفها إلى «أرباح فتراتٍ مُقفَلة»
            [$f, $t] = [$this->from->toDateString(), $this->to->toDateString()];
            foreach ([...ProfitDashboard::REVENUE, ...ProfitDashboard::COSTS] as $account) {
                $this->assertSame(0, $this->balance($account, $f, $t), "الحساب {$account} لم يُصفَّر.");
            }
            $this->assertSame(-$net, $this->balance(A::RETAINED));

            // واللوحة تقرأ الربح كما كان: قيد الإقفال ليس ربحاً
            $this->assertSame($net, (new ProfitDashboard)->netProfit(new ReportPeriod($this->from, $this->to->copy()->endOfDay()))['total']);
            $checks = collect(app(JournalCheck::class)->run())->pluck('status', 'key');
            $this->assertSame('ok', $checks['balanced']);
        });

        $this->actingAs($this->owner)->get($this->host()."/finance/periods/{$period->id}")->assertOk()->assertSee('مُقفَلة')->assertSee('نسخ التقرير');
        $this->actingAs($this->owner)->get($this->host()."/finance/periods/{$period->id}/reports/1")
            ->assertOk()->assertSee('تقرير إقفال الفترة')->assertSee(number_format($net));
    }

    public function test_a_failing_check_needs_a_documented_exception(): void
    {
        $this->activity();
        // عهدةٌ أقدم من الحدّ: تسليمٌ في الفترة لم يُحاسَب مندوبه
        $this->travelTo($this->from->copy()->addDays(20));
        $this->deliver(30_000);
        $this->travelBack();

        Tenancy::runFor($this->company, function () {
            $checks = collect($this->close()->checks($this->from, $this->to))->keyBy('key');
            $this->assertFalse($checks['custody']['ok']);

            $this->expectValidation(fn () => $this->close()->prepare($this->from, $this->to, [], $this->owner), 'بلا استثناءٍ موثّق');
            $period = $this->close()->prepare($this->from, $this->to, ['custody' => 'المندوب في إجازة، يُحاسَب الأسبوع القادم'], $this->owner);
            $this->assertSame(['custody' => 'المندوب في إجازة، يُحاسَب الأسبوع القادم'], $period->exceptions);
        });
    }

    public function test_the_range_must_follow_the_last_period_and_end_before_today(): void
    {
        $this->activity();

        Tenancy::runFor($this->company, function () {
            $this->expectValidation(fn () => $this->close()->prepare(now()->subDays(3)->startOfDay(), now()->startOfDay(), [], $this->owner), 'اليوم أو بعده');
            $first = $this->close()->prepare($this->from, $this->to, [], $this->owner);
            $this->close()->approve($first, $this->owner);

            // فجوة أو تداخل: لا
            $this->expectValidation(fn () => $this->close()->prepare($this->to->copy()->addDays(3), $this->to->copy()->addDays(10), [], $this->owner), 'بلا فجوةٍ ولا تداخل');
            $this->expectValidation(fn () => $this->close()->prepare($this->from, $this->to, [], $this->owner), 'بلا فجوةٍ ولا تداخل');
            $this->assertTrue($this->close()->suggest()[0]->isSameDay($this->to->copy()->addDay()));
        });
    }

    public function test_dual_approval_and_permissions(): void
    {
        $this->activity();
        Tenancy::runFor($this->company, fn () => $this->company->forceFill(['settings' => ['finance' => ['dual_approval' => true]]])->save());
        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        $admin = $this->makeUser($this->company, UserRole::CompanyAdmin);

        // المحاسب يُعِدّ ولا يعتمد
        $this->actingAs($accountant)->post($this->host().'/finance/periods', ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()])
            ->assertSessionHasNoErrors();
        $period = Tenancy::runFor($this->company, fn () => FinancialPeriod::firstOrFail());
        $this->actingAs($accountant)->post($this->host()."/finance/periods/{$period->id}/approve")->assertForbidden();
        $this->actingAs($admin)->post($this->host()."/finance/periods/{$period->id}/approve")->assertForbidden();

        // صاحب الشركة يعتمد ما أعدّه غيره، ولا يعتمد ما أعدّه بنفسه
        $this->actingAs($this->owner)->post($this->host()."/finance/periods/{$period->id}/cancel", ['reason' => 'أعيده بنفسي'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->host().'/finance/periods', ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()]);
        $mine = Tenancy::runFor($this->company, fn () => FinancialPeriod::firstOrFail());
        $this->actingAs($this->owner)->post($this->host()."/finance/periods/{$mine->id}/approve")->assertSessionHasErrors('period');
        $this->actingAs($this->owner)->post($this->host()."/finance/periods/{$mine->id}/cancel", ['reason' => 'يعدّه المحاسب'])->assertSessionHasNoErrors();

        $this->actingAs($accountant)->post($this->host().'/finance/periods', ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()]);
        $theirs = Tenancy::runFor($this->company, fn () => FinancialPeriod::firstOrFail());
        $this->actingAs($this->owner)->post($this->host()."/finance/periods/{$theirs->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('closed', Tenancy::runFor($this->company, fn () => $theirs->refresh()->status));
    }

    public function test_entries_dated_in_a_closed_period_land_today_and_a_new_report_version_lists_them(): void
    {
        $this->activity();

        Tenancy::runFor($this->company, function () {
            $period = $this->close()->prepare($this->from, $this->to, [], $this->owner);
            $this->close()->approve($period, $this->owner);

            // مصروفٌ بتاريخٍ في الفترة المُقفَلة: يُكتب اليوم ويُشير إليها
            $category = ExpenseCategory::first();
            app(RecordExpense::class)->handle(['expense_category_id' => $category->id, 'amount' => 700, 'description' => 'فاتورة متأخّرة',
                'spent_on' => $this->from->copy()->addDays(5)->toDateString()], $this->owner);

            $entry = DB::table('journal_entries')->where('adjusts_period_id', $period->id)->first();
            $this->assertNotNull($entry);
            $this->assertSame(now()->toDateString(), substr((string) $entry->entry_date, 0, 10));
            $this->assertSame(0, $this->balance(A::OPERATING_EXPENSES, $this->from->toDateString(), $this->to->toDateString()));

            $adjustments = $this->close()->adjustments($period);
            $this->assertSame(700, $adjustments['costs']);
            $this->assertSame(-700, $adjustments['net']);

            $v1 = $period->reports()->first()->figures;
            $report = $this->close()->reissue($period, 'فاتورة وقودٍ وصلت متأخّرة', $this->owner);
            $this->assertSame(2, $report->version);
            $this->assertSame((int) $v1['net']['total'] - 700, $report->figures['after_adjustments']['net']);
            $this->assertSame($v1, $period->reports()->where('version', 1)->first()->figures);
        });

        $period = Tenancy::runFor($this->company, fn () => FinancialPeriod::firstOrFail());
        $this->actingAs($this->owner)->get($this->host()."/finance/periods/{$period->id}")->assertOk()->assertSee('تعديلاتٌ بعد الإقفال')->assertSee('فاتورة متأخّرة');
        $this->actingAs($this->owner)->get($this->host()."/finance/periods/{$period->id}/reports/2")->assertOk()->assertSee('الصافي بعد التعديلات');
    }

    public function test_the_delivering_branch_commission_is_internal_and_nets_to_zero(): void
    {
        [$basra, $courier] = Tenancy::runFor($this->company, function () {
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_main' => false, 'commission_per_delivery' => 2_000]);

            return [$basra, Courier::create(['code' => 'C2', 'name' => 'علي الكعبي', 'phone' => '07720000002', 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 1000, 'branch_id' => $basra->id])];
        });

        $shipment = $this->deliver(30_000, $courier);
        $this->assertSame([$basra->id, 2_000], [(int) $shipment->delivery_branch_id, (int) $shipment->branch_commission]);

        Tenancy::runFor($this->company, function () use ($basra, $shipment) {
            $line = fn (string $account, ?int $branch) => (int) DB::table('journal_lines')->where('company_id', $this->company->id)
                ->where('account', $account)->where('branch_id', $branch)->sum(DB::raw('debit - credit'));

            $this->assertSame(-2_000, $line(A::INTERNAL_REVENUE, $basra->id));
            $this->assertSame(2_000, $line(A::INTERNAL_COST, (int) $shipment->branch_id));
            $this->assertSame(0, $this->balance(A::INTERBRANCH));
            $this->assertSame('ok', collect(app(JournalCheck::class)->run())->pluck('status', 'key')['interbranch']);
            // ولا يمسّ صافي الشركة
            $p = new ReportPeriod(now()->startOfMonth(), now()->endOfDay());
            $this->assertSame((int) $shipment->total_fees - 1000, (new ProfitDashboard)->netProfit($p)['total']);
        });
    }
}

<?php

namespace Tests\Feature\Finance;

use App\Actions\Cash\RecordExpense;
use App\Actions\Profits\ManageCollectionBatch;
use App\Actions\Profits\RecordProfitWithdrawal;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CollectionBatchItem;
use App\Models\Company;
use App\Models\Courier;
use App\Models\ExpenseCategory;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Finance\ProfitDashboard;
use App\Services\Reports\ReportPeriod;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «لوحة الأرباح» (docs/plan/68، المرحلة الثالثة). المقياس: كل بطاقةٍ تساوي قيودها، ومجموع
 * الفروع يساوي الشركة، والمتحقّق لا يزيد على المعترف به، والمتاح للسحب لا يقلّ عن صفر،
 * والنطاق (فرع، مندوب، تاجر) يحصر الأرقام فيه.
 */
class ProfitDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Merchant $baghdadMerchant;

    private Merchant $basraMerchant;

    private Branch $basra;

    private Courier $ahmed;

    private Courier $ali;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->baghdadMerchant = $this->makeMerchant($this->company);
        $this->basraMerchant = $this->makeMerchant($this->company, 'M0002');
        $this->owner = $this->makeUser($this->company);

        [$this->basra, $this->ahmed, $this->ali, $this->box] = Tenancy::runFor($this->company, function () {
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_main' => false]);
            $this->basraMerchant->forceFill(['branch_id' => $basra->id])->save();

            return [
                $basra,
                Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]),
                Courier::create(['code' => 'C2', 'name' => 'علي الكعبي', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1000, 'branch_id' => $basra->id]),
                CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
            ];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(Merchant $merchant, Courier $courier, int $cod): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $courier, $cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    /** دورة: تسليمٌ لكلّ فرع، كشفان ووجبة بعجزٍ ذمّةً، كشف تاجرٍ يُدفع، مصروف، سحب — وتسليمٌ يبقى بيد المندوب */
    private function cycle(): array
    {
        $a = $this->deliver($this->baghdadMerchant, $this->ahmed, 50_000);
        $b = $this->deliver($this->basraMerchant, $this->ali, 30_000);

        Tenancy::runFor($this->company, function () {
            $settlements = [
                app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($this->ahmed->refresh(), $this->owner), $this->owner, box: $this->box),
                app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($this->ali->refresh(), $this->owner), $this->owner, box: $this->box),
            ];

            $manage = app(ManageCollectionBatch::class);
            $batch = $manage->create(array_map(fn ($s) => $s->id, $settlements), null, 'week', now()->subDays(6), now(), null, $this->owner, $this->box->id);
            $item = CollectionBatchItem::where('collection_batch_id', $batch->id)->where('courier_id', $this->ahmed->id)->first();
            $manage->receive($batch, $item, (int) $item->expected_cash - 2_000, null, $this->owner);
            $manage->resolve($batch, $item, 'courier_debt', null, $this->owner);
            $manage->approve($batch, $this->owner);

            $pay = app(PayMerchantSettlement::class);
            $statement = $pay->confirm(app(BuildMerchantSettlement::class)->handle($this->baghdadMerchant->refresh(), $this->owner), $this->owner);
            $pay->pay($statement, $this->owner, 'cash', null, $this->box->refresh());

            $category = ExpenseCategory::create(['code' => 'fuel', 'name_ar' => 'وقود', 'group' => 'operating', 'is_active' => true]);
            app(RecordExpense::class)->handle(['expense_category_id' => $category->id, 'amount' => 1_500, 'description' => 'وقود', 'pay_now' => true, 'cash_box_id' => $this->box->id], $this->owner);

            app(RecordProfitWithdrawal::class)->handle(500, now(), $this->box->refresh(), 'صاحب الشركة', null, $this->owner);
        });

        // تسليمٌ لم يُحاسَب مندوبه: أجرته بيده لم تُحصَّل
        $c = $this->deliver($this->baghdadMerchant, $this->ahmed, 20_000);

        return Tenancy::runFor($this->company, fn () => [$a->refresh(), $b->refresh(), $c->refresh()]);
    }

    private function month(): ReportPeriod
    {
        return new ReportPeriod(now()->startOfMonth(), now()->endOfDay());
    }

    private function board(array $scope = [], ?User $viewer = null): ProfitDashboard
    {
        return new ProfitDashboard($viewer ?? $this->owner, $scope);
    }

    private function balance(string $account): int
    {
        return (int) DB::table('journal_lines')->where('company_id', $this->company->id)->where('account', $account)->sum(DB::raw('debit - credit'));
    }

    public function test_the_cards_equal_their_entries_and_hold_the_invariants(): void
    {
        [$a, $b, $c] = $this->cycle();

        Tenancy::runFor($this->company, function () use ($a, $b, $c) {
            $board = $this->board();
            $p = $this->month();

            // أ. والإيراد (ب) أجور ما سُلِّم كما كُتبت على الشحنات
            $this->assertSame(['delivered' => 3, 'returned' => 0], $board->delivered($p));
            $fees = (int) $a->total_fees + (int) $b->total_fees + (int) $c->total_fees;
            $this->assertSame($fees, $board->revenue($p)['total']);
            $this->assertSame(-$this->balance(A::DELIVERY_REVENUE), $board->revenue($p)['delivery']);

            // ج ود. التكاليف والصافي من حساباتها
            $costs = $board->costs($p);
            $this->assertSame([1500 * 2 + 1000, 1_500], [$costs['commissions'], $costs['expenses']]);
            $this->assertSame($fees - $costs['total'], $board->netProfit($p)['total']);

            // هـ. المحصَّل نقداً: أجور الوجبة ناقصاً عجزها، ناقص العمولات والمصروف المدفوعين
            $realized = $board->realized($p->from, $p->to);
            $batchFees = (int) $a->total_fees + (int) $b->total_fees - 2_000;
            $this->assertSame($batchFees, $realized['batches']);
            // العمولة المدفوعة ما أُقفل كشفه وحده: الشحنة الثالثة عمولتها مستحقّة لم تُدفع
            $this->assertSame([1_500 + 1_000, 1_500], [$realized['commissions'], $realized['expenses']]);
            $this->assertSame($batchFees - 2_500 - 1_500, $realized['total']);
            // المتحقّق لا يزيد على المعترف به
            $this->assertLessThanOrEqual($board->revenue($p)['total'], $realized['fees']);

            // و. غير المحصَّل: أجرة الشحنة التي بيد المندوب، وعجزه
            $unrealized = $board->unrealized();
            $this->assertSame((int) $c->total_fees, $unrealized['couriers']['total']);
            $this->assertSame((int) $c->total_fees, $unrealized['couriers']['0_7']);
            $this->assertSame(2_000, $unrealized['shortages']['total']);
            $this->assertSame(0, $unrealized['batches']['total']);

            // ز وح. المحتجزة كل الربح ناقص المسحوب، والمتاح لا يقلّ عن صفر ولا يزيد على النقد الحرّ
            $available = $board->available();
            $this->assertSame((int) $this->box->refresh()->balance, $available['cash']);
            $this->assertGreaterThanOrEqual(0, $available['total']);
            $this->assertLessThanOrEqual(max(0, $available['free']), $available['total']);
            $this->assertLessThanOrEqual($available['realized'] - $available['drawn'], $available['total']);
            $retained = $board->retained($available['free']);
            $this->assertSame($board->netProfit($p)['total'] - 500, $retained['total']);

            // ط. تاجر بغداد دُفع كشفه؛ وتسليمه الأخير نقده عند المندوب — مستقبليّ
            $payables = $board->merchantPayables();
            $this->assertSame((int) $c->merchant_due, $payables['future']);
            $this->assertSame((int) $b->merchant_due, $payables['now']);
            $this->assertSame(0, $payables['late']);
        });
    }

    public function test_branches_sum_to_the_company_and_scopes_narrow_every_number(): void
    {
        [$a, $b, $c] = $this->cycle();

        Tenancy::runFor($this->company, function () use ($a, $b, $c) {
            $p = $this->month();
            $company = $this->board()->netProfit($p);
            $branches = $this->board()->branches($p);

            $this->assertSame($company['total'], (int) $branches->sum('net'));
            $this->assertSame($company['fees'], (int) $branches->sum('revenue'));

            $basra = $branches->firstWhere('id', $this->basra->id);
            $this->assertSame((int) $b->total_fees, $basra->revenue);
            $this->assertSame(1, $basra->delivered);

            // بالمندوب: إيراد شحناته وعمولاته وحدها، ولا مصروفات عامّة عليه
            $ahmed = $this->board(['courier' => $this->ahmed->id]);
            $this->assertSame((int) $a->total_fees + (int) $c->total_fees, $ahmed->revenue($p)['total']);
            $this->assertSame(1500 * 2, $ahmed->costs($p)['commissions']);
            $this->assertSame(0, $ahmed->costs($p)['expenses']);

            // بالتاجر: شحناته وحدها
            $this->assertSame((int) $b->total_fees, $this->board(['merchant' => $this->basraMerchant->id])->revenue($p)['total']);

            // ترتيب المناديب بمساهمتهم: أجور شحناتهم ناقص عمولاتهم، وفرق وجباتهم
            $ranked = $this->board()->couriers($p);
            $first = $ranked->first();
            $this->assertSame($this->ahmed->id, $first->id);
            $this->assertSame((int) $a->total_fees + (int) $c->total_fees - 3_000, $first->contribution);
            $this->assertSame(-2_000, $first->discrepancy);
            $this->assertSame($this->ali->id, $this->board()->couriers($p, 'discrepancy')->last()->id);
        });
    }

    public function test_a_branch_user_sees_only_their_branch_and_no_company_cash(): void
    {
        $this->cycle();
        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        Tenancy::runFor($this->company, fn () => $accountant->forceFill(['branch_id' => $this->basra->id])->save());

        Tenancy::runFor($this->company, function () use ($accountant) {
            $board = $this->board(['branch' => null], $accountant);
            $this->assertSame($this->basra->id, $board->scope()['branch']);
            $this->assertCount(1, $board->branches($this->month()));
        });

        $this->actingAs($accountant)->get($this->host().'/profits')
            ->assertOk()->assertSee('فرع البصرة')->assertDontSee('النقد المتاح للسحب')->assertDontSee('الأرباح المحتجزة');
    }

    public function test_the_dashboard_renders_with_its_charts_filters_and_exports(): void
    {
        [$a] = $this->cycle();

        $this->actingAs($this->owner)->get($this->host().'/profits')
            ->assertOk()
            ->assertSeeInOrder(['الشحنات المسلَّمة', 'إيراد أجور التوصيل', 'التكاليف التشغيلية', 'صافي الربح المحاسبي',
                'الربح المحصَّل نقداً', 'أرباحٌ لم تُحصَّل بعد', 'الأرباح المحتجزة', 'النقد المتاح للسحب', 'مستحقّات التجّار غير المسدّدة'])
            ->assertSee('كيف حُسب')->assertSee('الإيرادات والتكاليف وصافي الربح')->assertSee('صافي الربح حسب الفروع')
            ->assertSee('المحصَّل مقابل غير المحصَّل')->assertSee('التحصيلات اليومية')->assertSee('أفضل المناديب')
            ->assertSee('مقارنة الفروع')->assertSee('فرع البصرة')->assertSee('أحمد الساعدي')
            ->assertSee('/finance/ledger?account=4100', false);

        foreach (['range=today', 'range=7', 'range=15', 'range=custom&from='.now()->subDays(40)->toDateString().'&to='.now()->toDateString(),
            'branch_id='.$this->basra->id, 'courier_id='.$this->ahmed->id, 'merchant_id='.$this->baghdadMerchant->id, 'rank=on_time', 'rank=discrepancy'] as $query) {
            $this->actingAs($this->owner)->get($this->host().'/profits?'.$query)->assertOk();
        }

        // بالمندوب: لا مقارنة فروع، والمصروفات العامّة لا تُحمَّل عليه
        $this->actingAs($this->owner)->get($this->host().'/profits?courier_id='.$this->ahmed->id)
            ->assertOk()->assertDontSee('مقارنة الفروع')->assertSee('المصروفات العامّة لا تُحمَّل على مندوبٍ أو تاجر.');

        foreach (['cards', 'branches', 'couriers'] as $what) {
            $this->actingAs($this->owner)->get($this->host().'/profits/export?what='.$what)
                ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        // التحصيلات اليومية تفتح وجبات يوم اعتمادها
        $this->actingAs($this->owner)->get($this->host().'/profits/batches?status=approved&approved_on='.now()->toDateString())
            ->assertOk()->assertSee('COL-0001');
        $this->actingAs($this->owner)->get($this->host().'/profits/batches?status=approved&approved_on='.now()->subDay()->toDateString())
            ->assertOk()->assertDontSee('COL-0001</a>', false);
    }

    public function test_the_operating_reserve_comes_off_the_free_cash(): void
    {
        $this->cycle();

        Tenancy::runFor($this->company, function () {
            $before = $this->board()->available();
            $this->company->forceFill(['settings' => ['finance' => ['reserve_fixed' => 3_000]]])->save();
            Tenancy::runFor($this->company->refresh(), function () use ($before) {
                $after = $this->board()->available();
                $this->assertSame(3_000, $after['reserve']);
                $this->assertSame($before['free'] - 3_000, $after['free']);
            });
        });
    }
}

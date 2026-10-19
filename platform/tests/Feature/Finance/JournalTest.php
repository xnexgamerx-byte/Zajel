<?php

namespace Tests\Feature\Finance;

use App\Actions\Cash\RecordExpense;
use App\Actions\Profits\ManageCollectionBatch;
use App\Actions\Profits\RecordProfitWithdrawal;
use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ConfirmAmount;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\Journal;
use App\Services\Finance\JournalBackfill;
use App\Services\Finance\JournalCheck;
use App\Services\Finance\PostingRules;
use App\Services\Ledger;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * دفتر القيود (docs/plan/68، المرحلة الأولى). المقياس: كل حركةٍ قيدٌ متوازن مرّةً واحدة، والدفتر
 * يطابق الصناديق والمناديب والتجّار بعد دورةٍ كاملة، وما بقي في الوسيط بعد إغلاق الشحنة هو أجورها،
 * وبناء التاريخ يعطي ما تعطيه الكتابة الحيّة.
 */
class JournalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $owner;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->box] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
                'commission_per_delivery' => 1500, 'commission_per_return' => 750]),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(int $cod): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
        ], $this->owner));
    }

    private function walk(Shipment $shipment, array $path, ?Courier $courier = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $path, $courier) {
            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? ($courier ?? $this->courier)->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    private function deliver(int $cod): Shipment
    {
        return $this->walk($this->shipment($cod), [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
    }

    private function returned(int $cod): Shipment
    {
        $shipment = $this->walk($this->shipment($cod), [
            ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt, ShipmentStatus::Returning,
        ]);
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->owner));

        return $this->walk($shipment->refresh(), [ShipmentStatus::Returned]);
    }

    /** رصيد حسابٍ (مدين − دائن)، بأبعادٍ إن طُلبت */
    private function balance(string $account, array $where = []): int
    {
        return (int) DB::table('journal_lines')->where('company_id', $this->company->id)->where('account', $account)
            ->where($where)->sum(DB::raw('debit - credit'));
    }

    /** @return array<string, string> حال كل فحص */
    private function checks(): array
    {
        return Tenancy::runFor($this->company, fn () => collect(app(JournalCheck::class)->run())->pluck('status', 'key')->all());
    }

    /** دورة كاملة: تسليمان وراجع، كشف مندوب ووجبة، كشف تاجر يُدفع، مصروف، سحب أرباح */
    private function fullCycle(): array
    {
        $a = $this->deliver(50_000);
        $b = $this->deliver(30_000);
        $r = $this->returned(20_000);

        Tenancy::runFor($this->company, function () {
            $settlement = app(ConfirmCourierSettlement::class)->handle(
                app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner), $this->owner, box: $this->box,
            );

            $batch = app(ManageCollectionBatch::class)->create([$settlement->id], null, 'week', now()->subDays(6), now(), null, $this->owner);
            app(ManageCollectionBatch::class)->approve($batch, $this->owner);

            $pay = app(PayMerchantSettlement::class);
            $statement = $pay->confirm(app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner), $this->owner);
            $pay->pay($statement, $this->owner, 'cash', null, $this->box);

            $category = ExpenseCategory::create(['code' => 'fuel', 'name_ar' => 'وقود', 'group' => 'operating', 'is_active' => true]);
            app(RecordExpense::class)->handle(['expense_category_id' => $category->id, 'amount' => 2_000, 'description' => 'وقود', 'pay_now' => true, 'cash_box_id' => $this->box->id], $this->owner);

            app(RecordProfitWithdrawal::class)->handle(1_000, now(), $this->box->refresh(), 'صاحب الشركة', null, $this->owner);
        });

        return Tenancy::runFor($this->company, fn () => [$a->refresh(), $b->refresh(), $r->refresh()]);
    }

    public function test_a_full_cycle_balances_and_matches_every_subledger(): void
    {
        [$a, $b, $r] = $this->fullCycle();

        $this->assertSame(0, (int) DB::table('journal_lines')->where('company_id', $this->company->id)->sum(DB::raw('debit - credit')));
        $checks = $this->checks();
        ksort($checks);
        $expected = array_fill_keys(['balanced', 'coverage_transaction', 'coverage_cash_movement', 'boxes', 'custody', 'commissions',
            'merchants', 'unbooked', 'clearing', 'transfers', 'unmapped', 'bridge', 'outside', 'chain'], 'ok');
        ksort($expected);
        $this->assertSame($expected, $checks);

        // الإيراد ما احتفظت به الشركة: أجور المسلَّم، وأجرة الراجع
        $this->assertSame((int) $a->total_fees + (int) $b->total_fees, -$this->balance(A::DELIVERY_REVENUE));
        $this->assertSame((int) $r->return_fee, -$this->balance(A::RETURN_REVENUE));
        $this->assertSame(1500 * 2 + 750, $this->balance(A::COURIER_COMMISSIONS));
        $this->assertSame(2_000, $this->balance(A::OPERATING_EXPENSES));
        // السحب حقوق ملكية لا مصروف
        $this->assertSame(1_000, $this->balance(A::DRAWINGS));

        // الصندوق: ما سلّمه المندوب − عمولته − ما دُفع للتاجر − المصروف − السحب
        $this->assertSame((int) $this->box->refresh()->balance, $this->balance(A::CASH, ['cash_box_id' => $this->box->id]));
        $this->assertSame(0, $this->balance(A::SHIPMENT_CLEARING));
        $this->assertSame(0, $this->balance(A::CASH_BRIDGE));

        // كل قيدٍ متوازن على حدة
        $unbalanced = DB::table('journal_lines')->where('company_id', $this->company->id)
            ->groupBy('journal_entry_id')->havingRaw('sum(debit) <> sum(credit)')->select('journal_entry_id')->get();
        $this->assertCount(0, $unbalanced);
    }

    public function test_the_same_movement_is_never_posted_twice(): void
    {
        $this->deliver(50_000);

        Tenancy::runFor($this->company, function () {
            $tx = Transaction::where('category', 'cod_collected')->firstOrFail();
            $before = JournalEntry::count();

            $first = app(PostingRules::class)->transaction($tx);
            $again = app(PostingRules::class)->transaction($tx);

            $this->assertSame($first, $again);
            $this->assertSame($before, JournalEntry::count());
            $this->assertSame(1, JournalEntry::where('idempotency_key', 'tx:'.$tx->id)->count());
        });
    }

    public function test_an_unbalanced_entry_is_refused(): void
    {
        $this->expectException(LogicException::class);

        app(Journal::class)->post($this->company->id, 'test:1', 'test', now(), [
            ['account' => A::CASH, 'debit' => 100],
            ['account' => A::MERCHANTS, 'credit' => 90],
        ]);
    }

    public function test_corrections_move_custody_or_revenue_by_the_difference_only(): void
    {
        $shipment = $this->deliver(50_000);
        $fees = (int) $shipment->total_fees;

        // تصحيح المبلغ: عهدة المندوب ومستحقّ التاجر معاً، والإيراد لا يتغيّر
        Tenancy::runFor($this->company, fn () => app(ConfirmAmount::class)->handle($shipment->refresh(), 45_000, $this->owner));
        $this->assertSame($fees, -$this->balance(A::DELIVERY_REVENUE));
        $this->assertSame(45_000, $this->balance(A::CUSTODY, ['courier_id' => $this->courier->id]));

        // تعديل الأجور: الفرق في حساب التاجر يقابله إيراد
        Tenancy::runFor($this->company, fn () => app(Ledger::class)->recordFeeCorrection($shipment->refresh(), 1_000, $this->owner, 'خصم'));
        $this->assertSame($fees - 1_000, -$this->balance(A::DELIVERY_REVENUE));
        $this->assertSame(0, $this->balance(A::SHIPMENT_CLEARING));
        $this->assertSame('ok', $this->checks()['merchants']);
    }

    public function test_history_rebuilt_from_the_ledgers_equals_live_posting(): void
    {
        $this->fullCycle();

        $live = DB::table('journal_lines')->where('company_id', $this->company->id)
            ->groupBy('account')->selectRaw('account, sum(debit) as d, sum(credit) as c')->orderBy('account')->get()
            ->map(fn ($r) => [$r->account, (int) $r->d, (int) $r->c])->all();

        // كأنّ الدفتر جاء بعد الحركات: يُمحى، وتُعلَّم الشركة غير مهيّأة، ثم يُبنى
        DB::table('journal_lines')->where('company_id', $this->company->id)->delete();
        DB::table('journal_entries')->where('company_id', $this->company->id)->delete();
        DB::table('journal_heads')->where('company_id', $this->company->id)
            ->update(['initialized_at' => null, 'last_number' => 0, 'last_hash' => null]);

        // وهي غير مهيّأة لا يُكتب لها قيدٌ حيّ — يلتقطه البناء
        $this->deliver(10_000);
        $this->assertSame(0, (int) DB::table('journal_entries')->where('company_id', $this->company->id)->count());

        $this->artisan('finance:journal-backfill')->assertSuccessful();

        $this->assertNotNull(DB::table('journal_heads')->where('company_id', $this->company->id)->value('initialized_at'));
        $this->assertNotContains('fail', $this->checks());

        // بلا الشحنة الأخيرة، الأرصدة كما كتبتها الكتابة الحيّة
        $rebuilt = DB::table('journal_lines')->where('company_id', $this->company->id)
            ->groupBy('account')->selectRaw('account, sum(debit) as d, sum(credit) as c')->orderBy('account')->get();
        $this->assertSame(collect($live)->pluck(0)->all(), $rebuilt->pluck('account')->all());

        // والإعادة لا تكرّر قيداً
        $count = DB::table('journal_entries')->where('company_id', $this->company->id)->count();
        Tenancy::runFor($this->company, fn () => app(JournalBackfill::class)->run($this->company->refresh()));
        $this->assertSame($count, DB::table('journal_entries')->where('company_id', $this->company->id)->count());
    }

    /** سُلِّمت بلا مندوبٍ مسجَّل: النقد في «تحصيلٌ بلا مندوب» لا في عهدةٍ ولا في الإيراد */
    public function test_cash_collected_without_a_courier_is_held_apart_from_revenue(): void
    {
        $shipment = $this->shipment(40_000);
        Tenancy::runFor($this->company, function () use ($shipment) {
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    $status === ShipmentStatus::Delivered ? ['force' => true, 'forced_reason' => 'سلّمها الموظف بنفسه'] : []);
            }
        });
        $shipment = Tenancy::runFor($this->company, fn () => $shipment->refresh());

        $this->assertNull($shipment->delivery_courier_id ?? null);
        $this->assertSame(40_000, $this->balance(A::OUTSIDE_COURIER, ['shipment_id' => $shipment->id]));
        $this->assertSame((int) $shipment->total_fees, -$this->balance(A::DELIVERY_REVENUE, ['shipment_id' => $shipment->id]));
        $this->assertSame(0, $this->balance(A::SHIPMENT_CLEARING));
        $this->assertSame('note', $this->checks()['outside']);
    }

    public function test_a_tampered_entry_breaks_the_hash_chain(): void
    {
        $this->deliver(50_000);
        $this->assertSame('ok', $this->checks()['chain']);

        $line = DB::table('journal_lines')->where('company_id', $this->company->id)->where('debit', '>', 0)->orderBy('id')->first();
        DB::table('journal_lines')->where('id', $line->id)->update(['debit' => $line->debit + 1]);

        $this->assertSame('fail', $this->checks()['chain']);
        $this->artisan('finance:journal-check')->assertFailed();
    }

    public function test_screens_trace_a_number_to_its_entries(): void
    {
        $shipment = $this->deliver(50_000);

        $this->actingAs($this->owner)->get($this->host().'/finance/trial-balance')
            ->assertOk()->assertSee('ميزان المراجعة والمطابقة')->assertSee('إيراد أجور التوصيل')->assertSee('مطابقة الدفتر')
            ->assertSee(number_format((int) $shipment->total_fees));

        $this->actingAs($this->owner)->get($this->host().'/finance/ledger?shipment='.$shipment->number)
            ->assertOk()->assertSee('إثبات إيراد الشحنة')->assertSee('حساب مندوب: تحصيل نقد')->assertSee('وسيط الشحنات');

        $entry = Tenancy::runFor($this->company, fn () => JournalEntry::where('event', 'revenue')->firstOrFail());
        $this->actingAs($this->owner)->get($this->host().'/finance/entries/'.$entry->id)
            ->assertOk()->assertSee('القيد رقم '.$entry->number)->assertSee('إيراد أجور التوصيل')->assertSee($entry->hash);

        $this->actingAs($this->owner)->get($this->host().'/')->assertSee('دفتر القيود')->assertSee('ميزان المراجعة');

        $service = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($service)->get($this->host().'/finance/trial-balance')->assertForbidden();
    }
}

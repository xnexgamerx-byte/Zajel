<?php

namespace Tests\Feature\Money;

use App\Actions\Cash\RecordExpense;
use App\Actions\Money\UndoWithinDay;
use App\Actions\Profits\ManageCollectionBatch;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchItem;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Merchant;
use App\Models\ProfitWithdrawal;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Money\Profits;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * «العوائد»: وجبات التحصيل والأرباح. المقياس: أموال التجّار لا تُحسب ربحاً، والكشف لا يُحسب
 * في وجبتين، والعجز يُحصَّل مرّةً واحدة، والسحب لا يزيد على ما تحقّق نقداً.
 */
class CollectionBatchesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $ahmed;

    private Courier $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->ahmed, $this->ali] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]),
            Courier::create(['code' => 'C2', 'name' => 'علي الكعبي', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1000]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(Courier $courier, int $cod): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($courier, $cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
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

    /** كشف مندوبٍ مُقفَل بشحناتٍ مسلَّمة */
    private function settled(Courier $courier, array $cods): CourierSettlement
    {
        foreach ($cods as $cod) {
            $this->deliver($courier, $cod);
        }

        return Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle(
            app(BuildCourierSettlement::class)->handle($courier->refresh(), $this->owner), $this->owner,
        ));
    }

    private function batch(array $settlements, ?User $actor = null): CollectionBatch
    {
        return Tenancy::runFor($this->company, fn () => app(ManageCollectionBatch::class)->create(
            array_map(fn ($s) => $s->id, $settlements), null, 'week', now()->subDays(6), now(), null, $actor ?? $this->owner,
        ));
    }

    public function test_a_batch_separates_merchant_money_fees_and_net_profit(): void
    {
        $a = $this->settled($this->ahmed, [50_000, 30_000]);
        $b = $this->settled($this->ali, [20_000]);

        $this->actingAs($this->owner)->get($this->host().'/profits/batches/create')
            ->assertOk()->assertSee($a->code)->assertSee($b->code)->assertSee('أحمد الساعدي');

        $this->actingAs($this->owner)
            ->post($this->host().'/profits/batches', [
                'period' => 'week', 'from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString(),
                'settlement_ids' => [$a->id, $b->id],
            ])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'COL-0001'));

        Tenancy::runFor($this->company, function () use ($a, $b) {
            $batch = CollectionBatch::where('number', 'COL-0001')->firstOrFail();
            $shipments = Shipment::whereIn('courier_settlement_id', [$a->id, $b->id])->get();

            $fees = (int) $shipments->sum('total_fees');
            $merchant = (int) $shipments->sum('merchant_due');
            $commissions = 1500 * 2 + 1000;

            $this->assertSame([2, 2, 3, 3], [$batch->couriers_count, $batch->settlements_count, $batch->shipments_count, $batch->delivered_count]);
            $this->assertSame(100_000, (int) $batch->cod_collected);
            $this->assertSame($merchant, (int) $batch->merchant_money);
            $this->assertSame($fees, (int) $batch->delivery_fees);
            $this->assertSame($commissions, (int) $batch->courier_commissions);
            $this->assertSame((int) $a->net_amount + (int) $b->net_amount, (int) $batch->expected_cash);
            $this->assertSame((int) $batch->expected_cash, (int) $batch->received_cash);
            $this->assertSame(0, (int) $batch->discrepancy);
            // أموال التجّار ليست ربحاً: الصافي الأجور ناقص العمولات، والمتحقّق ما بقي للشركة من النقد
            $this->assertSame($fees - $commissions, (int) $batch->net_profit);
            $this->assertSame((int) $batch->received_cash - $merchant, (int) $batch->realized_profit);
            // من كشوفٍ مقفلة: النقد استُلم، والوجبة بانتظار المطابقة
            $this->assertSame('awaiting_reconciliation', $batch->status);
        });

        $batch = Tenancy::runFor($this->company, fn () => CollectionBatch::firstOrFail());
        $this->actingAs($this->owner)->get($this->host().'/profits/batches/'.$batch->id)
            ->assertOk()->assertSee('الورقة المفصّلة لكل مندوب')->assertSee('أحمد الساعدي')->assertSee('علي الكعبي')
            ->assertSee('أموال التجّار في هذا النقد')->assertSee('أُنشئت الوجبة');
        $this->actingAs($this->owner)->get($this->host().'/profits/batches/'.$batch->id.'/print')
            ->assertOk()->assertSee('COL-0001')->assertSee($a->code);
    }

    public function test_a_settlement_is_never_counted_in_two_batches(): void
    {
        $a = $this->settled($this->ahmed, [50_000]);
        $first = $this->batch([$a]);

        Tenancy::runFor($this->company, function () use ($a) {
            $this->assertFalse(ManageCollectionBatch::eligible($this->owner)->whereKey($a->id)->exists());

            try {
                app(ManageCollectionBatch::class)->create([$a->id], null, 'week', now()->subDays(6), now(), null, $this->owner);
                $this->fail('دخل الكشف وجبتين');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('settlement_ids', $e->errors());
            }
        });

        // ولا يُحذف الكشف من تحت الوجبة
        Tenancy::runFor($this->company, function () use ($a) {
            $this->expectException(ValidationException::class);
            app(UndoWithinDay::class)->courierSettlement($a->refresh(), $this->owner, 'خطأ');
        });

        $this->assertSame(1, Tenancy::runFor($this->company, fn () => CollectionBatchItem::count()));
        $this->assertSame('COL-0001', $first->number);
    }

    public function test_shortage_is_documented_approved_corrected_with_reason_and_collected_once(): void
    {
        $a = $this->settled($this->ahmed, [50_000]);
        $batch = $this->batch([$a]);
        $item = Tenancy::runFor($this->company, fn () => $batch->items()->firstOrFail());
        $expected = (int) $item->expected_cash;

        // المندوب سلّم ناقصاً ١٠٠٠٠
        $this->actingAs($this->owner)
            ->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/received", ['received_cash' => $expected - 10_000])
            ->assertSessionHasNoErrors();
        $this->assertSame(-10_000, (int) $batch->refresh()->discrepancy);
        $realizedBefore = (int) $batch->realized_profit;

        // العجز يُعالَج قبل الاعتماد: ذمّةٌ على المندوب
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasErrors('batch');
        $this->actingAs($this->owner)
            ->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/resolve", ['resolution' => 'courier_debt'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve")->assertSessionHasNoErrors();
        $this->assertTrue($batch->refresh()->isApproved());
        $this->assertSame($this->owner->id, $batch->approved_by_user_id);

        // بعد الاعتماد: لا كشوف تُضاف، والتصحيح بسببٍ مكتوب
        $this->actingAs($this->owner)
            ->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/received", ['received_cash' => $expected - 8_000])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)
            ->post($this->host()."/profits/batches/{$batch->id}/items/{$item->id}/received", ['received_cash' => $expected - 8_000, 'reason' => 'وُجد ٢٠٠٠ بالدرج'])
            ->assertSessionHasNoErrors();
        $this->assertSame(-8_000, (int) $batch->refresh()->discrepancy);
        $this->assertSame($realizedBefore + 2_000, (int) $batch->realized_profit);

        Tenancy::runFor($this->company, function () use ($batch) {
            $corrected = $batch->audits()->where('action', 'corrected')->firstOrFail();
            $this->assertSame('وُجد ٢٠٠٠ بالدرج', $corrected->reason);
            $this->assertSame(8_000, app(Profits::class)->outstandingShortage($this->owner));
        });

        // تحصيل العجز في وجبةٍ تالية: يُشير إليه، ولا يزيد على باقيه
        $b = $this->settled($this->ali, [20_000]);
        $next = $this->batch([$b]);

        $this->actingAs($this->owner)->get($this->host().'/profits/batches/'.$next->id)
            ->assertOk()->assertSee('تحصيل عجزٍ سابق')->assertSee($batch->number);

        $this->actingAs($this->owner)
            ->post($this->host()."/profits/batches/{$next->id}/remainders", ['remainder_of_item_id' => $item->id, 'amount' => 9_000, 'note' => 'سلّم الباقي'])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->owner)
            ->post($this->host()."/profits/batches/{$next->id}/remainders", ['remainder_of_item_id' => $item->id, 'amount' => 8_000, 'note' => 'سلّم الباقي'])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($next, $item) {
            $next->refresh();
            $this->assertSame(8_000, (int) $next->remainders);
            $this->assertSame((int) $next->expected_cash + 8_000, (int) $next->received_cash);
            $this->assertSame(0, (int) $next->discrepancy);
            $this->assertSame(0, $item->refresh()->outstanding());
            $this->assertSame(0, app(Profits::class)->outstandingShortage($this->owner));
        });

        // ودفع العجز لا يُفتح ثانيةً لما سُدِّد
        $this->actingAs($this->owner)
            ->post($this->host()."/profits/batches/{$next->id}/remainders", ['remainder_of_item_id' => $item->id, 'amount' => 1, 'note' => 'مرّة ثانية'])
            ->assertSessionHasErrors('amount');
    }

    public function test_costs_reduce_profit_and_an_expense_counts_in_one_batch_only(): void
    {
        $a = $this->settled($this->ahmed, [50_000]);
        $b = $this->settled($this->ali, [20_000]);
        $first = $this->batch([$a]);
        $second = $this->batch([$b]);

        $expense = Tenancy::runFor($this->company, function () {
            $category = ExpenseCategory::create(['code' => 'fuel', 'name_ar' => 'وقود', 'group' => 'operating', 'is_active' => true]);

            return app(RecordExpense::class)->handle(['expense_category_id' => $category->id, 'amount' => 3_000, 'description' => 'وقود السيارة'], $this->owner);
        });

        $profit = (int) $first->net_profit;
        $this->actingAs($this->owner)->get($this->host().'/profits/batches/'.$first->id)->assertSee('وقود السيارة');
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$first->id}/costs", ['expense_ids' => [$expense->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(3_000, (int) $first->refresh()->costs);
        $this->assertSame($profit - 3_000, (int) $first->net_profit);

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$second->id}/costs", ['expense_ids' => [$expense->id]])
            ->assertSessionHasErrors('expense_ids');

        // ولا يُلغى المصروف وصافي الوجبة قائمٌ عليه
        Tenancy::runFor($this->company, function () use ($expense) {
            $this->expectException(ValidationException::class);
            app(RecordExpense::class)->cancel($expense->refresh(), 'خطأ', $this->owner);
        });
    }

    public function test_withdrawals_never_exceed_realized_profit_and_leave_the_box(): void
    {
        $box = Tenancy::runFor($this->company, fn () => CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]));
        $batch = $this->batch([$this->settled($this->ahmed, [50_000, 40_000])]);

        // المسودّة لا يُسحب منها
        $this->actingAs($this->owner)
            ->post($this->host().'/profits/withdrawals', ['amount' => 1_000, 'withdrawn_on' => now()->toDateString()])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve");
        $available = (int) $batch->refresh()->realized_profit;
        $this->assertGreaterThan(2_000, $available);

        $this->actingAs($this->owner)
            ->post($this->host().'/profits/withdrawals', ['amount' => $available + 1, 'withdrawn_on' => now()->toDateString()])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->owner)
            ->post($this->host().'/profits/withdrawals', [
                'amount' => 2_000, 'withdrawn_on' => now()->toDateString(), 'withdrawn_by' => 'صاحب الشركة', 'cash_box_id' => $box->id,
            ])->assertSessionHas('success', fn ($m) => str_contains($m, 'WD-0001'));

        Tenancy::runFor($this->company, function () use ($box, $available) {
            $withdrawal = ProfitWithdrawal::firstOrFail();
            $this->assertSame(2_000, (int) $withdrawal->amount);
            $this->assertSame(1, CashMovement::where('category', 'profit_withdrawal')->where('direction', 'out')->count());
            $this->assertSame($available - 2_000, app(Profits::class)->available());
            $balance = (int) $box->refresh()->balance;

            app(\App\Actions\Profits\RecordProfitWithdrawal::class)->cancel($withdrawal, 'سُجِّل خطأً', $this->owner);
            $this->assertSame($balance + 2_000, (int) $box->refresh()->balance);
            $this->assertSame($available, app(Profits::class)->available());
        });

        $this->actingAs($this->owner)->get($this->host().'/profits/withdrawals')
            ->assertOk()->assertSee('سجل عمليات السحب')->assertSee('WD-0001')->assertSee('ملغى');
    }

    public function test_only_the_owner_withdraws_and_the_accountant_runs_batches(): void
    {
        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        $service = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($accountant)->get($this->host().'/profits')->assertOk()->assertSee('لوحة الأرباح');
        $this->actingAs($accountant)->get($this->host().'/profits/batches/create')->assertOk();
        $this->actingAs($accountant)
            ->post($this->host().'/profits/withdrawals', ['amount' => 1, 'withdrawn_on' => now()->toDateString()])
            ->assertForbidden();

        $this->actingAs($service)->get($this->host().'/profits')->assertForbidden();
    }

    public function test_overview_report_and_menu(): void
    {
        $a = $this->settled($this->ahmed, [50_000]);
        $b = $this->settled($this->ali, [20_000]);
        $batch = $this->batch([$a, $b]);
        $this->actingAs($this->owner)->post($this->host()."/profits/batches/{$batch->id}/approve");
        $this->batch([$this->settled($this->ahmed, [10_000])]);

        $this->actingAs($this->owner)->get($this->host().'/')
            ->assertSee('لوحة الأرباح')->assertSee(route('profits.index', [], false), false);

        // اللوحة من دفتر القيود: إيراد الأجور فيها ما قُيِّد للشحنات المسلَّمة
        $fees = (int) Tenancy::runFor($this->company, fn () => Shipment::whereNotNull('delivered_at')->sum('total_fees'));
        $this->actingAs($this->owner)->get($this->host().'/profits')
            ->assertOk()->assertSee('صافي الربح المحاسبي')->assertSee('الربح المحصَّل نقداً')->assertSee('وجبات لم تُعتمد بعد')
            ->assertSee('أفضل المناديب')->assertSee('كيف حُسب')->assertSee(number_format($fees));

        $this->actingAs($this->owner)->get($this->host().'/profits/batches?status=open')
            ->assertOk()->assertSee('COL-0002</a>', false)->assertDontSee('COL-0001</a>', false);

        // تقرير المدّة يشمل وجبةً تنتهي اليوم، والمسودّة بطلبها
        $this->actingAs($this->owner)->get($this->host().'/profits/report')
            ->assertOk()->assertSee('COL-0001</a>', false)->assertDontSee('COL-0002</a>', false);
        $this->actingAs($this->owner)->get($this->host().'/profits/report?drafts=1')->assertSee('COL-0002</a>', false);

        $this->actingAs($this->owner)->get($this->host().'/profits/report?courier_id='.$this->ali->id)
            ->assertOk()->assertSee('مساهمة المندوب')->assertSee('علي الكعبي')->assertDontSee('أحمد الساعدي</td>', false);
    }
}

<?php

namespace Tests\Feature\Cash;

use App\Actions\Cash\MerchantAdvances;
use App\Actions\Cash\RecordExpense;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\ExpenseCategory;
use App\Models\Merchant;
use App\Models\MerchantAdvance;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حذف كشفٍ أو حركةٍ ماليّة في يومها وحده (docs/plan/38): بحركاتٍ معاكسة تعيد كل رصيدٍ
 * كما كان، وبعد أربعٍ وعشرين ساعة لا يُحذف شيء.
 */
class UndoWithinDayTest extends TestCase
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
            Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 2000]),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);

        // رصيدٌ افتتاحيّ بحركته: الجرد يطابق الرصيد بمجموع الحركات
        Tenancy::runFor($this->company, fn () => app(CashBook::class)->in($this->box, 'opening', 100_000, 'رصيد افتتاحي', $this->owner));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->owner, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->owner);

            return $shipment->refresh();
        });
    }

    private function courierSheet(): CourierSettlement
    {
        return Tenancy::runFor($this->company, function () {
            $sheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->owner);

            return app(ConfirmCourierSettlement::class)->handle($sheet, $this->owner, box: $this->box);
        });
    }

    private function assertAllBalance(): void
    {
        Tenancy::runFor($this->company, function () {
            $ledger = app(Ledger::class);
            $this->assertTrue($ledger->reconcile('courier', $this->courier->id)['matches']);
            $this->assertTrue($ledger->reconcile('merchant', $this->merchant->id)['matches']);
            $this->assertTrue(app(CashBook::class)->reconcile($this->box)['matches']);
        });
    }

    public function test_a_courier_statement_deleted_in_its_day_puts_every_balance_back(): void
    {
        $shipment = $this->deliver();
        $cashBefore = (int) Tenancy::runFor($this->company, fn () => $this->courier->refresh()->cash_in_hand);
        $sheet = $this->courierSheet();

        $this->assertSame(148_000, (int) $this->box->refresh()->balance);
        $this->assertSame(0, (int) Tenancy::runFor($this->company, fn () => $this->courier->refresh()->cash_in_hand));

        $this->actingAs($this->owner)->get($this->host()."/settlements/couriers/{$sheet->id}")->assertSee('حذف الكشف');

        $this->actingAs($this->owner)->post($this->host()."/settlements/couriers/{$sheet->id}/cancel", ['reason' => 'أُقفل على مندوبٍ خطأ'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $sheet->refresh()->status);
        $this->assertSame(100_000, (int) $this->box->refresh()->balance);
        Tenancy::runFor($this->company, function () use ($cashBefore) {
            $this->courier->refresh();
            $this->assertSame($cashBefore, (int) $this->courier->cash_in_hand);
            $this->assertSame(2000, (int) $this->courier->commission_balance);
        });
        $this->assertNull($shipment->refresh()->courier_settlement_id);
        $this->assertAllBalance();

        // شحناته تدخل كشفاً جديداً، والملغى لا يُقفَل ثانيةً
        $again = Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier, $this->owner));
        $this->assertSame(1, (int) $again->shipments_count);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle($sheet->refresh(), $this->owner));
    }

    public function test_after_a_day_a_statement_is_not_deleted(): void
    {
        $this->deliver();
        $sheet = $this->courierSheet();

        $this->travel(25)->hours();

        $this->actingAs($this->owner)->get($this->host()."/settlements/couriers/{$sheet->id}")
            ->assertDontSee('سبب الحذف')->assertSee('لا يُحذف ولا يُعدَّل');

        $this->actingAs($this->owner)->post($this->host()."/settlements/couriers/{$sheet->id}/cancel", ['reason' => 'متأخّر'])
            ->assertSessionHasErrors('settlement');

        $this->assertSame('confirmed', $sheet->refresh()->status);
    }

    public function test_a_paid_merchant_statement_comes_back_with_its_advance(): void
    {
        $shipment = $this->deliver(105_000);
        $this->courierSheet();

        $balanceBefore = (int) Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->balance);

        $sheet = Tenancy::runFor($this->company, function () {
            app(MerchantAdvances::class)->give($this->merchant, 30_000, $this->box, $this->owner);
            $sheet = app(BuildMerchantSettlement::class)->handle($this->merchant, $this->owner);
            $pay = app(PayMerchantSettlement::class);
            $pay->confirm($sheet, $this->owner);

            return $pay->pay($sheet->refresh(), $this->owner, 'cash', null, $this->box->refresh());
        });

        $boxAfterPay = (int) $this->box->refresh()->balance;
        $this->assertSame(30_000, (int) $sheet->advance_deduction);

        $this->actingAs($this->owner)->post($this->host()."/settlements/merchants/{$sheet->id}/cancel", ['reason' => 'دُفع لتاجرٍ خطأ'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $sheet->refresh()->status);
        $this->assertSame($boxAfterPay + (int) $sheet->net_amount, (int) $this->box->refresh()->balance);
        $this->assertNull($shipment->refresh()->merchant_settlement_id);

        Tenancy::runFor($this->company, function () use ($balanceBefore) {
            $advance = MerchantAdvance::sole();
            $this->assertSame('open', $advance->status);
            $this->assertSame(0, (int) $advance->recovered);
            $this->assertSame(30_000, MerchantAdvances::outstanding($this->merchant));
            // رصيده: مستحقّه ناقصاً السلفة، كما قبل الكشف
            $this->assertSame($balanceBefore - 30_000, (int) $this->merchant->refresh()->balance);
        });

        $this->assertAllBalance();
    }

    public function test_a_transfer_is_undone_on_both_boxes_once(): void
    {
        $other = Tenancy::runFor($this->company, fn () => CashBox::create([
            'code' => 'B2', 'name' => 'صندوق الفرع', 'type' => 'branch', 'balance' => 0, 'is_active' => true,
        ]));

        Tenancy::runFor($this->company, fn () => app(CashBook::class)->transfer($this->box, $other, 40_000, null, $this->owner));
        $sent = Tenancy::runFor($this->company, fn () => CashMovement::where('category', 'transfer_out')->sole());

        $this->actingAs($this->owner)->get($this->host().'/cash?box_id='.$this->box->id)->assertSee('ألغِ الحركة');

        $this->actingAs($this->owner)->post($this->host()."/cash/movements/{$sent->id}/undo", ['reason' => 'الصندوق الخطأ'])
            ->assertSessionHasNoErrors();

        $this->assertSame(100_000, (int) $this->box->refresh()->balance);
        $this->assertSame(0, (int) $other->refresh()->balance);

        $this->actingAs($this->owner)->post($this->host()."/cash/movements/{$sent->id}/undo", ['reason' => 'مرّة ثانية'])
            ->assertSessionHasErrors('movement');
        $this->assertSame(100_000, (int) $this->box->refresh()->balance);
    }

    public function test_a_movement_from_yesterday_or_from_a_statement_is_not_undone_here(): void
    {
        Tenancy::runFor($this->company, fn () => app(CashBook::class)->out($this->box, 'adjustment', 5_000, 'تسوية جرد — نقص', $this->owner));
        $adjust = Tenancy::runFor($this->company, fn () => CashMovement::where('category', 'adjustment')->sole());

        $this->deliver();
        $this->courierSheet();
        $handover = Tenancy::runFor($this->company, fn () => CashMovement::where('category', 'courier_handover')->sole());

        $this->actingAs($this->owner)->post($this->host()."/cash/movements/{$handover->id}/undo", ['reason' => 'x'])
            ->assertSessionHasErrors('movement');

        $this->travel(25)->hours();

        $this->actingAs($this->owner)->post($this->host()."/cash/movements/{$adjust->id}/undo", ['reason' => 'x'])
            ->assertSessionHasErrors('movement');
    }

    public function test_a_paid_expense_is_cancelled_in_its_day_only(): void
    {
        [$today, $old] = Tenancy::runFor($this->company, function () {
            $category = ExpenseCategory::create(['code' => 'fuel', 'name_ar' => 'وقود', 'group' => 'operations', 'is_active' => true]);
            $record = app(RecordExpense::class);
            $make = fn () => $record->pay($record->handle([
                'expense_category_id' => $category->id, 'amount' => 10_000, 'description' => 'وقود',
                'expense_date' => now()->toDateString(),
            ], $this->owner), $this->box->refresh(), $this->owner);

            return [$make(), $make()];
        });

        $this->travel(25)->hours();

        $this->actingAs($this->owner)->post($this->host()."/expenses/{$old->id}/cancel", ['cancel_reason' => 'خطأ'])
            ->assertSessionHasErrors('status');
        $this->assertSame('paid', $old->refresh()->status);

        $this->travelBack();

        $this->actingAs($this->owner)->post($this->host()."/expenses/{$today->id}/cancel", ['cancel_reason' => 'خطأ'])
            ->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $today->refresh()->status);
    }

    public function test_an_advance_given_by_mistake_is_cancelled_in_its_day(): void
    {
        $advance = Tenancy::runFor($this->company, fn () => app(MerchantAdvances::class)->give($this->merchant, 20_000, $this->box, $this->owner));

        $this->actingAs($this->owner)->post($this->host()."/merchant-advances/{$advance->id}/cancel", ['reason' => 'تاجرٌ خطأ'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $advance->refresh()->status);
        $this->assertSame(100_000, (int) $this->box->refresh()->balance);
        $this->assertSame(0, (int) Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->balance));
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => MerchantAdvances::outstanding($this->merchant)));
        $this->assertAllBalance();
    }
}

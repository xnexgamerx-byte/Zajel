<?php

namespace Tests\Feature\Finance;

use App\Actions\Profits\ManageCollectionBatch;
use App\Actions\Profits\RecordProfitWithdrawal;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\ProfitWithdrawal;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserGrant;
use App\Services\Finance\CashPosition;
use App\Support\Finance\Accounts as A;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * المرحلة الرابعة (docs/plan/68 §١٠ و§١١): سحب الأرباح بمساره، والحساب المصرفي، والموقف النقدي
 * وتوقّعه. المقياس: لا سحب فوق المتاح ولو اعتُمد، ولا دفع مرّتين، والعكس في يومه بقيده.
 */
class WithdrawalsAndCashTest extends TestCase
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
            Courier::create(['code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(int $cod): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    /** أرباحٌ محصَّلة: تسليماتٌ وكشفٌ في الصندوق ووجبةٌ معتمدة */
    private function earn(array $cods = [50_000, 40_000, 30_000]): int
    {
        foreach ($cods as $cod) {
            $this->deliver($cod);
        }

        return Tenancy::runFor($this->company, function () {
            $settlement = app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner), $this->owner, box: $this->box);
            $manage = app(ManageCollectionBatch::class);
            $manage->approve($manage->create([$settlement->id], null, 'week', now()->subDays(6), now(), null, $this->owner, $this->box->id), $this->owner);

            return $this->withdraw()->available();
        });
    }

    private function withdraw(): RecordProfitWithdrawal
    {
        return app(RecordProfitWithdrawal::class);
    }

    private function form(int $amount, array $extra = []): array
    {
        return $extra + ['amount' => $amount, 'withdrawn_on' => now()->toDateString(), 'withdrawn_by' => 'صاحب الشركة',
            'cash_box_id' => $this->box->id, 'note' => 'أرباح الأسبوع'];
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

    public function test_a_withdrawal_is_requested_approved_then_paid_once_as_equity(): void
    {
        $available = $this->earn();
        $this->assertGreaterThan(5_000, $available);

        $this->actingAs($this->owner)->get($this->host().'/profits/withdrawals')
            ->assertOk()->assertSee('الأرباح المحاسبية المتراكمة')->assertSee('تفصيل المتاح للسحب')->assertSee(number_format($available));

        // طلبٌ أُرسل مرّتين بمفتاحه سحبٌ واحد
        $key = 'k-'.uniqid();
        $this->actingAs($this->owner)->post($this->host().'/profits/withdrawals', $this->form(3_000, ['idempotency_key' => $key]))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ينتظر الاعتماد'));
        $this->actingAs($this->owner)->post($this->host().'/profits/withdrawals', $this->form(3_000, ['idempotency_key' => $key]));

        Tenancy::runFor($this->company, function () use ($available) {
            $this->assertSame(1, ProfitWithdrawal::count());
            $w = ProfitWithdrawal::first();
            $this->assertSame(['requested', $available, $available - 3_000], [$w->status, (int) $w->available_before, (int) $w->available_after]);
            // المطلوب محجوزٌ من المتاح، ولا مال تحرّك بعد
            $this->assertSame($available - 3_000, $available - $this->withdraw()->committed());
            $this->assertSame(0, CashMovement::where('category', 'profit_withdrawal')->count());
            $this->assertSame(0, (int) DB::table('journal_lines')->where('account', A::DRAWINGS)->sum('debit'));
        });

        $w = Tenancy::runFor($this->company, fn () => ProfitWithdrawal::first());
        // لا يُدفع قبل الاعتماد
        $this->actingAs($this->owner)->post($this->host()."/profits/withdrawals/{$w->id}/pay")->assertSessionHasErrors('withdrawal');
        $this->actingAs($this->owner)->post($this->host()."/profits/withdrawals/{$w->id}/approve")->assertSessionHasNoErrors();
        $box = (int) Tenancy::runFor($this->company, fn () => $this->box->refresh()->balance);
        $this->actingAs($this->owner)->post($this->host()."/profits/withdrawals/{$w->id}/pay")->assertSessionHasNoErrors();
        // ولا يُدفع مرّتين
        $this->actingAs($this->owner)->post($this->host()."/profits/withdrawals/{$w->id}/pay")->assertSessionHasErrors('withdrawal');

        Tenancy::runFor($this->company, function () use ($w, $box, $available) {
            $w->refresh();
            $this->assertSame('paid', $w->status);
            $this->assertSame($box - 3_000, (int) $this->box->refresh()->balance);
            $this->assertSame(3_000, (int) DB::table('journal_lines')->where('account', A::DRAWINGS)->sum(DB::raw('debit - credit')));
            $this->assertSame(0, (int) DB::table('journal_lines')->where('account', A::OPERATING_EXPENSES)->sum(DB::raw('debit - credit')) - 0);
            $this->assertSame($available - 3_000, $this->withdraw()->available());
        });

        $this->actingAs($this->owner)->get($this->host()."/profits/withdrawals/{$w->id}/print")
            ->assertOk()->assertSee('سند صرف')->assertSee($w->number)->assertSee('3,000')->assertSee('صاحب الشركة');
        $this->actingAs($this->owner)->get($this->host().'/profits/withdrawals/export?status=paid')
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_the_available_is_rechecked_at_payment_and_requests_cannot_overbook(): void
    {
        $available = $this->earn();

        Tenancy::runFor($this->company, function () use ($available) {
            $first = $this->withdraw()->request($available - 1_000, now(), $this->box, 'شريك', 'أرباح', $this->owner);
            // ما حُجز لا يُطلب ثانيةً
            $this->expectValidation(fn () => $this->withdraw()->request(2_000, now(), $this->box, 'شريك', 'أرباح', $this->owner), 'المتاح للسحب');
            $this->withdraw()->approve($first, $this->owner);

            // احتياطيٌّ رُفع بعد الاعتماد يُنقص النقد الحرّ: الدفع يُعاد حسابه تحت القفل
            $this->company->forceFill(['settings' => ['finance' => ['reserve_fixed' => 100_000_000]]])->save();
            Tenancy::runFor($this->company->refresh(), function () use ($first) {
                $this->assertSame(0, $this->withdraw()->available());
                $this->expectValidation(fn () => $this->withdraw()->pay($first, $this->owner), 'المتاح للسحب');
                $this->assertSame('approved', $first->refresh()->status);
                $this->assertSame(0, CashMovement::where('reference_type', 'profit_withdrawal')->count());
            });
        });
    }

    public function test_above_the_company_limit_another_approver_is_required(): void
    {
        $this->earn();
        Tenancy::runFor($this->company, fn () => $this->company->forceFill(['settings' => ['finance' => ['withdrawal_approval_over' => 1_000]]])->save());
        $partner = $this->makeUser($this->company, UserRole::CompanyAdmin);
        Tenancy::runFor($this->company, fn () => UserGrant::create(['user_id' => $partner->id, 'ability' => 'withdrawals.approve']));

        // «ادفع الآن» فوق الحدّ: لا يعتمد الطالب سحبه
        $this->actingAs($this->owner)->post($this->host().'/profits/withdrawals', $this->form(2_000, ['pay_now' => 1]))
            ->assertSessionHasErrors('withdrawal');
        $this->actingAs($this->owner)->post($this->host().'/profits/withdrawals', $this->form(2_000))->assertSessionHasNoErrors();
        $w = Tenancy::runFor($this->company, fn () => ProfitWithdrawal::latest('id')->first());
        $this->actingAs($this->owner)->post($this->host()."/profits/withdrawals/{$w->id}/approve")->assertSessionHasErrors('withdrawal');
        $this->actingAs($partner->fresh())->post($this->host()."/profits/withdrawals/{$w->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->host()."/profits/withdrawals/{$w->id}/pay")->assertSessionHasNoErrors();

        // وتحت الحدّ يُطلب ويُدفع في خطوةٍ واحدة
        $this->actingAs($this->owner)->post($this->host().'/profits/withdrawals', $this->form(500, ['pay_now' => 1]))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'دُفع'));
    }

    public function test_cancel_before_payment_and_reverse_only_on_its_day(): void
    {
        $this->earn();

        Tenancy::runFor($this->company, function () {
            $requested = $this->withdraw()->request(1_000, now(), $this->box, 'شريك', 'أرباح', $this->owner);
            $this->withdraw()->cancel($requested, 'عدلنا', $this->owner);
            $this->assertSame('cancelled', $requested->refresh()->status);
            $this->assertSame(0, CashMovement::where('reference_type', 'profit_withdrawal')->count());

            $paid = $this->withdraw()->handle(2_000, now(), $this->box, 'شريك', 'أرباح', $this->owner);
            $balance = (int) $this->box->refresh()->balance;
            $this->withdraw()->cancel($paid, 'خطأ في المبلغ', $this->owner);
            $this->assertSame(['reversed', $balance + 2_000], [$paid->refresh()->status, (int) $this->box->refresh()->balance]);
            $this->assertSame(0, (int) DB::table('journal_lines')->where('account', A::DRAWINGS)->sum(DB::raw('debit - credit')));

            $late = $this->withdraw()->handle(1_000, now(), $this->box, 'شريك', 'أرباح', $this->owner);
            $this->travel(25)->hours();
            $this->expectValidation(fn () => $this->withdraw()->cancel($late, 'متأخّر', $this->owner), 'أربعٌ وعشرون ساعة');
        });
    }

    public function test_only_withdrawal_holders_request_and_a_branch_user_never_does(): void
    {
        $this->earn();
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($accountant)->get($this->host().'/profits/withdrawals')->assertOk()->assertDontSee('تسجيل السحب');
        $this->actingAs($accountant)->post($this->host().'/profits/withdrawals', $this->form(500))->assertForbidden();

        Tenancy::runFor($this->company, fn () => UserGrant::create(['user_id' => $accountant->id, 'ability' => 'withdrawals.request']));
        $this->actingAs($accountant->fresh())->post($this->host().'/profits/withdrawals', $this->form(500, ['pay_now' => 1]))->assertSessionHasNoErrors();
        // يطلب ولا يدفع: «ادفع الآن» لمن يعتمد وحده
        $this->assertSame('requested', Tenancy::runFor($this->company, fn () => ProfitWithdrawal::latest('id')->first()->status));
    }

    public function test_a_bank_account_is_a_box_withdrawals_can_leave_from(): void
    {
        $this->earn();

        $this->actingAs($this->owner)->post($this->host().'/cash', ['name' => 'حساب الرافدين', 'code' => 'BANK1', 'type' => 'bank'])
            ->assertSessionHasErrors('bank_name');
        $this->actingAs($this->owner)->post($this->host().'/cash', ['name' => 'حساب الرافدين', 'code' => 'BANK1', 'type' => 'bank',
            'bank_name' => 'مصرف الرافدين', 'account_label' => 'الجاري — آخره 4217', 'opening' => 10_000])->assertSessionHasNoErrors();

        $bank = Tenancy::runFor($this->company, fn () => CashBox::where('code', 'BANK1')->firstOrFail());
        $this->assertSame(['bank', 'مصرف الرافدين'], [$bank->type, $bank->bank_name]);
        // الحساب المصرفي لا يقبض نقداً باليد
        $this->assertNotSame($bank->id, Tenancy::runFor($this->company, fn () => CashBox::forBranch(null)?->id));

        $this->actingAs($this->owner)->post($this->host().'/profits/withdrawals', $this->form(1_000, ['cash_box_id' => $bank->id, 'pay_now' => 1]))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'حساب الرافدين'));
        $this->assertSame(9_000, (int) Tenancy::runFor($this->company, fn () => $bank->refresh()->balance));

        $this->actingAs($this->owner)->get($this->host().'/profits/cash')->assertOk()->assertSee('حساب مصرفي — مصرف الرافدين');
    }

    public function test_finance_settings_have_their_own_page_for_the_owner_and_are_audited(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/settings/company')->assertOk()->assertSee('الإعدادات المالية');
        $this->actingAs($this->owner)->get($this->host().'/finance/settings')->assertOk()->assertSee('دورة تسوية التجّار')->assertSee('مهلة تسليم المندوب نقده');
        $this->actingAs($this->owner)->put($this->host().'/finance/settings', [
            'dual_approval' => '1', 'reserve_fixed' => 25_000, 'reserve_days' => 3, 'merchant_cycle_days' => 10, 'withdrawal_approval_over' => 500_000,
            'cash_handover_hours' => 12, 'cost_ratio_max' => 80, 'margin_min' => 5,
        ])->assertSessionHasNoErrors();

        $finance = Tenancy::runFor($this->company, fn () => $this->company->refresh()->setting('finance'));
        $this->assertSame([25_000, 3, 10, 500_000, 12, 80, 5, true], [$finance['reserve_fixed'], $finance['reserve_days'], $finance['merchant_cycle_days'],
            $finance['withdrawal_approval_over'], $finance['cash_handover_hours'], $finance['cost_ratio_max'], $finance['margin_min'], $finance['dual_approval']]);

        $log = Tenancy::runFor($this->company, fn () => \App\Models\AuditLog::where('action', 'finance_settings_updated')->latest('id')->first());
        $this->assertSame(12, $log->new_values['cash_handover_hours']);
        $this->assertSame(24, $log->old_values['cash_handover_hours']);

        // المحاسب يرى الأرباح ولا يغيّر إعداداتها
        $accountant = $this->makeUser($this->company, \App\Enums\UserRole::Accountant);
        $this->actingAs($accountant)->get($this->host().'/finance/settings')->assertForbidden();
        $this->actingAs($accountant)->put($this->host().'/finance/settings', ['reserve_fixed' => 0])->assertForbidden();
    }

    public function test_the_cash_position_separates_its_categories_and_forecasts_only_with_history(): void
    {
        $this->earn([50_000, 40_000]);
        $this->deliver(20_000); // بيد المندوب

        $this->actingAs($this->owner)->get($this->host().'/profits/cash')
            ->assertOk()->assertSee('النقد في الصناديق والحسابات')->assertSee('النقد عند المناديب')->assertSee('بالطريق بين الفروع')
            ->assertSee('تحصيلاتٌ متوقَّعة')->assertSee('مستحقّات التجّار')->assertSee('الاحتياطي التشغيلي')->assertSee('المتاح للسحب')
            ->assertSee('لا يُعرض التوقّع قبل');

        Tenancy::runFor($this->company, function () {
            $c = (new CashPosition($this->owner))->categories();
            $this->assertSame((int) $this->box->refresh()->balance, $c['cash']);
            $this->assertSame((int) $this->courier->refresh()->cash_in_hand, $c['couriers']['total']);
            $this->assertSame(20_000, $c['couriers']['ages']['0_1']);
            $this->assertNull((new CashPosition($this->owner))->forecast());
        });

        // تاريخٌ أطول من ٣٠ يوماً: تسليمٌ قديم، وشحناتٌ في طريقها الآن
        $this->travel(-40)->days();
        $this->deliver(15_000);
        $this->travelBack();
        Tenancy::runFor($this->company, function () {
            foreach ([25_000, 35_000] as $cod) {
                $shipment = app(CreateShipment::class)->handle([
                    'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                    'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
                ], $this->owner);
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->owner);
            }

            $forecast = (new CashPosition($this->owner))->forecast();
            $this->assertNotNull($forecast);
            $this->assertCount(14, $forecast['days']);
            $this->assertGreaterThan(0, array_sum(array_column($forecast['days'], 'in')));
            foreach ($forecast['days'] as $day) {
                $this->assertLessThanOrEqual($day['high'], $day['low']);
                $this->assertGreaterThanOrEqual($day['low'], $day['balance']);
                $this->assertLessThanOrEqual($day['high'], $day['balance']);
            }
        });

        $this->actingAs($this->owner)->get($this->host().'/profits/cash')
            ->assertOk()->assertSee('التدفّق المتوقَّع')->assertSee('الافتراضات')->assertDontSee('لا يُعرض التوقّع قبل');
    }
}

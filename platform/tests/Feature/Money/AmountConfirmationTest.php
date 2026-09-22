<?php

namespace Tests\Feature\Money;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ConfirmAmount;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تأكيد مبلغ الوصل.
 *
 * الرقم المكتوب والرقم المقبوض يختلفان أحياناً؛ الخلاف لا يبدأ من الفرق
 * بل من تعديله بعد أن بُني عليه حساب. فالاختبار على أمرين: أن التصحيح
 * يمرّ في الدفتر لا فوقه، وأن التأكيد لا رجعة فيه.
 */
class AmountConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function delivered(int $cod = 50_000, ?int $collected = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod, $collected) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => 'علي حسين',
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff,
                ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff,
                $collected === null ? [] : ['collected_amount' => $collected]);

            return $shipment->refresh();
        });
    }

    private function confirm(Shipment $shipment, int $amount, ?string $note = null): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(ConfirmAmount::class)
            ->handle($shipment->refresh(), $amount, $this->staff, $note));
    }

    // ── التأكيد بلا تغيير ───────────────────────────────────────────

    public function test_confirming_the_same_amount_only_freezes_it(): void
    {
        $shipment = $this->delivered(50_000);
        $before = Tenancy::runFor($this->company, fn () => (int) $this->merchant->refresh()->balance);

        $fresh = $this->confirm($shipment, 50_000);

        $this->assertTrue($fresh->amount_confirmed);
        $this->assertNotNull($fresh->amount_confirmed_at);
        $this->assertSame($this->staff->id, $fresh->amount_confirmed_by_user_id);

        Tenancy::runFor($this->company, function () use ($before, $shipment) {
            $this->assertSame($before, (int) $this->merchant->refresh()->balance, 'لا حركة بلا فرق');
            $this->assertSame(0, Transaction::where('shipment_id', $shipment->id)
                ->where('category', 'amount_correction')->count());
        });
    }

    // ── التصحيح ─────────────────────────────────────────────────────

    public function test_a_shortfall_moves_the_same_amount_off_both_sides(): void
    {
        // الوصل ٥٠ ألفاً والزبون دفع ٤٥: التاجر يستحقّ أقلّ، وبيد المندوب أقلّ
        $shipment = $this->delivered(50_000);

        $dueBefore = $shipment->merchant_due;
        $cashBefore = Tenancy::runFor($this->company, fn () => (int) $this->courier->refresh()->cash_in_hand);

        $fresh = $this->confirm($shipment, 45_000, 'ردّ الزبون قطعة');

        $this->assertSame(45_000, (int) $fresh->collected_amount);
        $this->assertSame($dueBefore - 5_000, (int) $fresh->merchant_due);

        Tenancy::runFor($this->company, function () use ($cashBefore, $shipment) {
            $this->assertSame($cashBefore - 5_000, (int) $this->courier->refresh()->cash_in_hand);

            $corrections = Transaction::where('shipment_id', $shipment->id)
                ->where('category', 'amount_correction')->get();

            $this->assertCount(2, $corrections, 'التصحيح يمسّ حسابين: التاجر والمندوب');
            $this->assertStringContainsString('ردّ الزبون قطعة', $corrections->first()->description);
        });
    }

    public function test_an_overpayment_moves_the_same_amount_onto_both_sides(): void
    {
        $shipment = $this->delivered(50_000, collected: 45_000);

        $fresh = $this->confirm($shipment, 50_000);

        $this->assertSame(50_000, (int) $fresh->collected_amount);

        Tenancy::runFor($this->company, function () {
            $this->assertSame(50_000, (int) $this->courier->refresh()->cash_in_hand);
        });
    }

    public function test_the_ledger_still_reconciles_after_a_correction(): void
    {
        $this->confirm($this->delivered(50_000), 42_000);
        $this->confirm($this->delivered(80_000), 85_000);

        Tenancy::runFor($this->company, function () {
            $ledger = app(Ledger::class);

            $this->assertTrue($ledger->reconcile('merchant', $this->merchant->id)['matches']);
            $this->assertTrue($ledger->reconcile('courier', $this->courier->id)['matches']);
        });
    }

    public function test_the_correction_is_a_new_entry_not_an_edited_one(): void
    {
        $shipment = $this->delivered(50_000);
        $original = Tenancy::runFor($this->company, fn () => Transaction::where('shipment_id', $shipment->id)
            ->where('category', 'cod_collected')->firstOrFail());

        $this->confirm($shipment, 45_000);

        Tenancy::runFor($this->company, function () use ($original) {
            $this->assertSame(
                50_000,
                (int) $original->refresh()->amount,
                'الحركة الأصلية تبقى كما هي — التصحيح بحركة معاكسة',
            );
        });
    }

    // ── لا رجعة ─────────────────────────────────────────────────────

    public function test_an_amount_cannot_be_confirmed_twice(): void
    {
        $shipment = $this->confirm($this->delivered(50_000), 45_000);

        $this->expectException(ValidationException::class);
        $this->confirm($shipment, 60_000);
    }

    public function test_a_second_confirmation_changes_nothing(): void
    {
        $shipment = $this->confirm($this->delivered(50_000), 45_000);
        $balance = Tenancy::runFor($this->company, fn () => (int) $this->merchant->refresh()->balance);

        try {
            $this->confirm($shipment, 60_000);
        } catch (ValidationException) {
            // متوقّع
        }

        Tenancy::runFor($this->company, function () use ($shipment, $balance) {
            $this->assertSame(45_000, (int) $shipment->refresh()->collected_amount);
            $this->assertSame($balance, (int) $this->merchant->refresh()->balance);
        });
    }

    public function test_an_undelivered_shipment_has_no_amount_to_confirm(): void
    {
        $shipment = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => $this->merchant->id,
            'recipient_name'  => 'علي',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => 50_000,
        ], $this->staff));

        $this->expectException(ValidationException::class);
        $this->confirm($shipment, 50_000);
    }

    public function test_a_shipment_on_a_draft_sheet_is_corrected_on_the_account_not_on_the_receipt(): void
    {
        $shipment = $this->delivered(50_000);

        // مسوّدة لا كشف مُقفَل: المبلغ مُجمّد في سطرها منذ الآن، فتصحيحه
        // هنا يعني مندوباً يوقّع لاحقاً على رقم لم يعد في النظام
        Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)
            ->handle($this->courier, $this->staff));

        $this->expectException(ValidationException::class);
        $this->confirm($shipment, 45_000);
    }

    public function test_the_draft_sheet_total_still_matches_the_shipment_it_froze(): void
    {
        $shipment = $this->delivered(50_000);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $sheet = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);

            try {
                app(ConfirmAmount::class)->handle($shipment->refresh(), 45_000, $this->staff);
            } catch (ValidationException) {
                // متوقّع
            }

            $this->assertSame(50_000, (int) $sheet->refresh()->cod_total);
            $this->assertSame(50_000, (int) $shipment->refresh()->collected_amount);
        });
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->confirm($this->delivered(50_000), -1);
    }

    // ── الشاشة ──────────────────────────────────────────────────────

    public function test_staff_confirm_the_amount_from_the_shipment_screen(): void
    {
        $shipment = $this->delivered(50_000);

        $this->actingAs($this->staff)
            ->post($this->host()."/shipments/{$shipment->id}/amount", [
                'collected_amount' => 45_000,
                'note'             => 'ردّ الزبون قطعة',
                'acknowledge'      => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () use ($shipment) {
            $fresh = $shipment->refresh();
            $this->assertTrue($fresh->amount_confirmed);
            $this->assertSame(45_000, (int) $fresh->collected_amount);
        });
    }

    public function test_the_confirmation_needs_an_explicit_acknowledgement(): void
    {
        $shipment = $this->delivered(50_000);

        $this->actingAs($this->staff)
            ->post($this->host()."/shipments/{$shipment->id}/amount", [
                'collected_amount' => 45_000,
            ])
            ->assertSessionHasErrors('acknowledge');

        Tenancy::runFor($this->company, fn () => $this->assertFalse($shipment->refresh()->amount_confirmed));
    }

    public function test_the_screen_offers_the_dialog_before_confirming_and_a_lock_after(): void
    {
        $shipment = $this->delivered(50_000);

        $this->actingAs($this->staff)
            ->get($this->host()."/shipments/{$shipment->id}")
            ->assertOk()
            ->assertSee('تأكيد مبلغ الوصل')
            ->assertSee('لا يُعدَّل بعد التأكيد');

        $this->confirm($shipment, 50_000);

        $this->actingAs($this->staff)
            ->get($this->host()."/shipments/{$shipment->id}")
            ->assertOk()
            ->assertSee('مؤكَّد')
            ->assertDontSee('تأكيد نهائي');
    }

    public function test_a_merchant_login_cannot_confirm_an_amount(): void
    {
        $shipment = $this->delivered(50_000);

        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)
            ->post($this->host()."/shipments/{$shipment->id}/amount", [
                'collected_amount' => 1, 'acknowledge' => 1,
            ])
            ->assertForbidden();

        Tenancy::runFor($this->company, fn () => $this->assertFalse($shipment->refresh()->amount_confirmed));
    }
}

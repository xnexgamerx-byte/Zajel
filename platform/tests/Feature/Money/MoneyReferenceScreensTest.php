<?php

namespace Tests\Feature\Money;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchRemittance;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialSnapshot;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Money\FinancialPosition;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المال كما في المعتاد: «صناديق الدفع» لكل موظّف و«حسابات المحاسب»،
 * و«ديون على الفروع» بتسديدها واستلامها الفعليّ، و«الموقف المالي» ولقطاته،
 * والمصروف برقم أمر صرفه وقسمه وأرشيفه.
 */
class MoneyReferenceScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private CashBox $safe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->safe] = Tenancy::runFor($this->company, fn () => [
            Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery',
                'status' => 'active', 'commission_per_delivery' => 1000]),
            CashBox::create(['code' => 'MAIN', 'name' => 'القاصة', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(int $cod = 50_000, ?Merchant $merchant = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod, $merchant) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => ($merchant ?? $this->merchant)->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    // ------------------------------------------------------ صندوق الموظّف

    public function test_what_an_employee_receives_lands_in_their_box_and_is_handed_to_the_safe(): void
    {
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($this->owner)->post($this->host().'/cash', [
            'name' => 'صندوق المحاسب', 'code' => 'EMP1', 'type' => 'employee', 'user_id' => $accountant->id,
        ])->assertSessionHasNoErrors();

        // صندوقٌ واحد لكل موظّف
        $this->actingAs($this->owner)->post($this->host().'/cash', [
            'name' => 'ثانٍ', 'code' => 'EMP2', 'type' => 'employee', 'user_id' => $accountant->id,
        ])->assertSessionHasErrors('user_id');

        $this->deliver(50_000);
        Tenancy::runFor($this->company, function () use ($accountant) {
            $sheet = app(BuildCourierSettlement::class)->handle($this->courier, $accountant);
            app(ConfirmCourierSettlement::class)->handle($sheet, $accountant);
        });

        $box = Tenancy::runFor($this->company, fn () => CashBox::where('user_id', $accountant->id)->firstOrFail());
        $this->assertSame(49_000, (int) $box->balance);            // ٥٠ ألفاً دخلت و١٠٠٠ عمولة خرجت
        $this->assertSame(0, (int) $this->safe->refresh()->balance);

        // وصندوقه لا يصير صندوق الفرع لغيره
        $this->assertSame($this->safe->id, Tenancy::runFor($this->company, fn () => CashBox::forActor($this->owner, null)?->id));

        $this->actingAs($accountant)->get($this->host().'/shipments')->assertSee('صندوقي')->assertSee('49,000');
        $this->actingAs($accountant)->get($this->host().'/cash/mine')->assertOk()->assertSee('سلّمت للقاصة');

        $this->actingAs($accountant)->post($this->host().'/cash/mine/handover', ['to_box_id' => $this->safe->id, 'amount' => 60_000])
            ->assertSessionHasErrors('amount');
        $this->actingAs($accountant)->post($this->host().'/cash/mine/handover', ['to_box_id' => $box->id, 'amount' => 1])
            ->assertSessionHasErrors('to_box_id');
        $this->actingAs($accountant)->post($this->host().'/cash/mine/handover', ['to_box_id' => $this->safe->id, 'amount' => 49_000])
            ->assertSessionHasNoErrors();

        $this->assertSame([0, 49_000], [(int) $box->refresh()->balance, (int) $this->safe->refresh()->balance]);
        Tenancy::runFor($this->company, function () use ($box) {
            $this->assertTrue(app(CashBook::class)->reconcile($box)['matches']);
            $this->assertTrue(app(CashBook::class)->reconcile($this->safe)['matches']);
        });

        // ومن لا صندوق له لا «صندوقي» له
        $this->actingAs($this->owner)->get($this->host().'/cash/mine')->assertForbidden();
        $this->actingAs($this->owner)->post($this->host().'/cash/mine/handover', ['to_box_id' => $this->safe->id, 'amount' => 1])->assertForbidden();

        // و«حسابات المحاسب» تقرؤه من الدفتر
        $this->actingAs($this->owner)->get($this->host().'/money/accountants?user_id='.$accountant->id)
            ->assertOk()->assertSee('تسليم نقد من مندوب')->assertSee('مناقلة صادرة')->assertSee('50,000');
    }

    // ------------------------------------------------------ ديون الفروع

    public function test_a_branch_pays_what_it_collected_and_the_other_receives_the_actual_amount(): void
    {
        [$main, $basra, $mainBox, $basraBox, $basraMerchant] = Tenancy::runFor($this->company, function () {
            $main = Branch::where('code', 'B1')->firstOrFail();
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']);
            $mainBox = CashBox::create(['code' => 'MB', 'name' => 'صندوق بغداد', 'type' => 'branch', 'branch_id' => $main->id, 'balance' => 0, 'is_active' => true]);
            $basraBox = CashBox::create(['code' => 'BB', 'name' => 'صندوق البصرة', 'type' => 'branch', 'branch_id' => $basra->id, 'balance' => 0, 'is_active' => true]);
            app(CashBook::class)->in($mainBox, 'opening', 500_000, null, $this->owner);

            $merchant = $this->makeMerchant($this->company, 'M0002');
            $merchant->update(['branch_id' => $basra->id]);

            return [$main, $basra, $mainBox, $basraBox, $merchant];
        });

        // فرع بغداد سلّم شحنة تاجرٍ من البصرة
        $shipment = $this->deliver(80_000, $basraMerchant);
        Tenancy::runFor($this->company, fn () => $shipment->forceFill(['branch_id' => $main->id])->save());

        $this->actingAs($this->owner)->get($this->host().'/branch-accounts/debts')
            ->assertOk()->assertSee('الفرع الرئيسي')->assertSee('فرع البصرة')->assertSee('80,000');

        $this->actingAs($this->owner)->post($this->host().'/branch-accounts/remittances', [
            'from_branch_id' => $main->id, 'to_branch_id' => $basra->id, 'from_box_id' => $basraBox->id, 'amount' => 80_000,
        ])->assertSessionHasErrors('from_box_id'); // صندوقٌ ليس من صناديق الفرع المسدِّد

        $this->actingAs($this->owner)->post($this->host().'/branch-accounts/remittances', [
            'from_branch_id' => $main->id, 'to_branch_id' => $basra->id, 'from_box_id' => $mainBox->id, 'amount' => 80_000,
        ])->assertSessionHasNoErrors();

        $remittance = Tenancy::runFor($this->company, fn () => BranchRemittance::sole());
        $this->assertSame(420_000, (int) $mainBox->refresh()->balance);

        // بالطريق: خرج من درجٍ ولم يدخل الآخر
        $this->assertSame(80_000, Tenancy::runFor($this->company, fn () => app(FinancialPosition::class)->now()['figures']['in_transit']));

        // وصل أقلّ: لا يُستلم بلا سبب
        $this->actingAs($this->owner)->post($this->host()."/branch-accounts/remittances/{$remittance->id}/receive", [
            'received_amount' => 75_000, 'to_box_id' => $basraBox->id,
        ])->assertSessionHasErrors('difference_note');

        // المقيَّد بفرع بغداد لا يستلم ما لفرع البصرة
        $manager = $this->makeUser($this->company, UserRole::BranchManager);
        Tenancy::runFor($this->company, fn () => $manager->update(['branch_id' => $main->id]));
        $this->actingAs($manager->refresh())->post($this->host()."/branch-accounts/remittances/{$remittance->id}/receive", [
            'received_amount' => 80_000, 'to_box_id' => $basraBox->id,
        ])->assertForbidden();

        $this->actingAs($this->owner)->post($this->host()."/branch-accounts/remittances/{$remittance->id}/receive", [
            'received_amount' => 75_000, 'to_box_id' => $basraBox->id, 'difference_note' => 'نقصت ورقة فئة ٥ آلاف',
        ])->assertSessionHasNoErrors();

        $this->assertSame(75_000, (int) $basraBox->refresh()->balance);
        $this->actingAs($this->owner)->post($this->host()."/branch-accounts/remittances/{$remittance->id}/receive", [
            'received_amount' => 75_000, 'to_box_id' => $basraBox->id, 'difference_note' => 'مرّة ثانية',
        ])->assertSessionHasErrors('remittance');
        $this->assertSame(75_000, (int) $basraBox->refresh()->balance);

        // الدَّين يُطفأ بما وصل: يبقى الفرق
        $pairs = $this->actingAs($this->owner)->get($this->host().'/branch-accounts/debts')->viewData('pairs');
        $this->assertSame([80_000, 75_000, 0, 5_000], [(int) $pairs[0]->amount, $pairs[0]->received, $pairs[0]->in_transit, $pairs[0]->remaining]);

        $this->actingAs($this->owner)->get($this->host().'/branch-accounts/remittances')
            ->assertOk()->assertSee('نقصت ورقة فئة ٥ آلاف')->assertSee('75,000');
    }

    // ------------------------------------------------------ الموقف المالي

    public function test_the_financial_position_adds_what_we_have_and_subtracts_what_we_owe(): void
    {
        $this->deliver(50_000); // بيد المندوب ٥٠ ألفاً، وللتاجر مستحقّه، وللمندوب عمولته
        Tenancy::runFor($this->company, fn () => app(CashBook::class)->in($this->safe, 'opening', 100_000, null, $this->owner));

        $position = Tenancy::runFor($this->company, fn () => app(FinancialPosition::class)->now());
        $merchantDue = (int) Tenancy::runFor($this->company, fn () => $this->merchant->refresh()->balance);

        $this->assertSame(100_000, $position['figures']['safe']);
        $this->assertSame(50_000, $position['figures']['with_couriers']);
        $this->assertSame($merchantDue, $position['figures']['merchant_payables']);
        $this->assertSame(1000, $position['figures']['courier_commissions']);
        $this->assertSame(100_000 + 50_000 - $merchantDue - 1000, $position['total']);
        $this->assertSame(['بلا مندوب استلام' => $merchantDue], $position['payables_by_pickup']);

        $this->actingAs($this->owner)->get($this->host().'/money/position')->assertOk()->assertSee('إجمالي الموقف')->assertSee(number_format($position['total']));

        $this->actingAs($this->owner)->post($this->host().'/money/position')->assertRedirect($this->host().'/money/position/history');
        $this->artisan('zajel:snapshot', ['--company' => 'zajel'])->assertSuccessful();

        Tenancy::runFor($this->company, function () use ($position) {
            $this->assertSame(2, FinancialSnapshot::count());
            $this->assertSame([$this->owner->id, null], FinancialSnapshot::orderBy('id')->pluck('taken_by_user_id')->all());
            $this->assertSame($position['total'], (int) FinancialSnapshot::first()->total);
        });

        // اللقطة لا تتغيّر حين يتغيّر ما بعدها
        Tenancy::runFor($this->company, fn () => app(CashBook::class)->in($this->safe, 'opening', 1_000, null, $this->owner));
        $this->actingAs($this->owner)->get($this->host().'/money/position/history')
            ->assertOk()->assertSee('تلقائياً')->assertSee(number_format($position['total']))->assertDontSee(number_format($position['total'] + 1_000));
    }

    // ------------------------------------------------------ المصروفات

    public function test_an_expense_carries_its_order_number_and_department_and_is_archived_by_selection(): void
    {
        $category = Tenancy::runFor($this->company, fn () => ExpenseCategory::availableFor($this->company->id)->first()
            ?? ExpenseCategory::create(['code' => 'FUEL', 'name_ar' => 'وقود', 'is_active' => true]));

        foreach (['وقود سيارة', 'قرطاسية'] as $i => $description) {
            $this->actingAs($this->owner)->post($this->host().'/expenses', [
                'expense_category_id' => $category->id, 'amount' => 10_000 * ($i + 1), 'spent_on' => today()->toDateString(),
                'description' => $description, 'order_number' => 'AMR-'.($i + 1), 'department' => 'التوزيع',
            ])->assertSessionHasNoErrors();
        }

        [$first, $second] = Tenancy::runFor($this->company, fn () => Expense::orderBy('id')->get()->all());
        $this->assertSame(['AMR-1', 'التوزيع'], [$first->order_number, $first->department]);

        $this->actingAs($this->owner)->get($this->host().'/expenses')
            ->assertOk()->assertSee('أمر صرف AMR-1')->assertSee('التوزيع')->assertSee('30,000');

        $this->actingAs($this->owner)->post($this->host().'/expenses/archive', ['ids' => [$first->id], 'mode' => 'archive'])
            ->assertSessionHasNoErrors();

        $listed = fn (string $tab = '') => $this->actingAs($this->owner)->get($this->host().'/expenses'.($tab ? '?tab='.$tab : ''))
            ->viewData('expenses')->getCollection()->pluck('id')->all();
        $this->assertSame([$second->id], $listed());
        $this->assertSame([$first->id], $listed('archive'));

        $this->actingAs($this->owner)->post($this->host().'/expenses/archive', ['ids' => [$first->id], 'mode' => 'restore']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $listed());
    }
}

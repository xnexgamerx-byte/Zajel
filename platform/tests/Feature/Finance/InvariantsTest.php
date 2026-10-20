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
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\ExpenseCategory;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Finance\JournalCheck;
use App\Services\Finance\ProfitDashboard;
use App\Services\Reports\ReportPeriod;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الخصائص الثابتة (docs/plan/68 §١٧-٣) على تسلسلاتٍ عشوائيةٍ من العمليات الحقيقية — تسليمٌ من
 * فرعه ومن فرعٍ آخر، ورجوع، وكشف مندوبٍ بوجبةٍ مطابقة أو بعجزٍ يُعالَج ذمّةً أو شطباً، ودفع
 * تاجر، ومصروف، وسحب أرباح — وبعد كل عملية:
 * - ميزان المراجعة صفر، والدفتر العام = الدفاتر الفرعية، والجاري بين الفروع متقاصّ.
 * - مجموع إيرادات الفروع = إيراد الشركة.
 * - المحصَّل من الأجور ≤ المعترف به.
 * - المتاح للسحب ≥ ٠، ولا يزيد على المحصَّل بعد المسحوب.
 * بذورٌ ثابتة: الفشل يتكرّر بالبذرة نفسها.
 */
class InvariantsTest extends TestCase
{
    use RefreshDatabase;

    private const STEPS = 30;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    /** @var list<Courier> */
    private array $couriers;

    private CashBox $box;

    private ExpenseCategory $category;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->couriers, $this->box, $this->category] = Tenancy::runFor($this->company, function () {
            $other = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_main' => false, 'commission_per_delivery' => 2000, 'is_active' => true]);

            return [
                [
                    Courier::create(['code' => 'C1', 'name' => 'مندوب بغداد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500]),
                    Courier::create(['code' => 'C2', 'name' => 'مندوب البصرة', 'phone' => '07720000002', 'type' => 'delivery', 'status' => 'active',
                        'commission_per_delivery' => 1000, 'branch_id' => $other->id]),
                ],
                CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
                ExpenseCategory::create(['code' => 'fuel', 'name_ar' => 'وقود', 'group' => 'operating', 'is_active' => true]),
            ];
        });
    }

    public static function seeds(): array
    {
        return [[7], [68], [2026]];
    }

    #[DataProvider('seeds')]
    public function test_random_operations_keep_every_invariant(int $seed): void
    {
        mt_srand($seed);

        for ($step = 1; $step <= self::STEPS; $step++) {
            $roll = mt_rand(1, 100);
            $op = match (true) {
                $roll <= 35 => 'deliver',
                $roll <= 45 => 'return',
                $roll <= 65 => 'settle',
                $roll <= 80 => 'pay',
                $roll <= 90 => 'expense',
                default     => 'withdraw',
            };

            try {
                Tenancy::runFor($this->company, fn () => $this->{$op}());
                $this->log[] = "{$step}: {$op}";
            } catch (ValidationException $e) {
                // عمليةٌ لا تجوز الآن (لا شيء يُسوّى، لا متاح): ترفضها قواعدها، والحسابات كما هي
                $this->log[] = "{$step}: {$op} — رُفضت: ".collect($e->errors())->flatten()->first();
            }

            $this->assertInvariants("البذرة {$seed}، الخطوة {$step}");
        }

        // حارسٌ لا يحرس إن رُفضت عملياته كلّها: أربعة أنواعٍ على الأقل جرت فعلاً
        $done = collect($this->log)->reject(fn ($line) => str_contains($line, 'رُفضت'))
            ->map(fn ($line) => explode(': ', $line)[1])->unique();
        $this->assertGreaterThanOrEqual(4, $done->count(), implode("\n", $this->log));
    }

    private function deliver(): void
    {
        $courier = $this->couriers[mt_rand(0, 1)];
        $this->walk($this->create(mt_rand(10, 200) * 1000), [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered], $courier);
    }

    private function return(): void
    {
        $shipment = $this->walk($this->create(mt_rand(10, 100) * 1000), [
            ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt, ShipmentStatus::Returning,
        ], $this->couriers[mt_rand(0, 1)]);
        app(ReceiveReturns::class)->handle([$shipment->id], $this->owner);
        $this->walk($shipment->refresh(), [ShipmentStatus::Returned]);
    }

    /** كشف مندوبٍ في وجبة: مطابقةٌ تُعتمد، أو بعجزٍ يُعالَج ذمّةً أو شطباً ثم تُعتمد */
    private function settle(): void
    {
        $courier = $this->couriers[mt_rand(0, 1)]->refresh();
        $batches = app(ManageCollectionBatch::class);

        if (mt_rand(0, 1) === 0) {
            $settlement = app(ConfirmCourierSettlement::class)->handle(app(BuildCourierSettlement::class)->handle($courier, $this->owner), $this->owner, box: $this->box);
            $batches->approve($batches->create([$settlement->id], null, 'week', now()->subDays(6), now(), null, $this->owner, $this->box->id), $this->owner);

            return;
        }

        $batch = $batches->createForCouriers([$courier->id], null, $this->box->id, null, $this->owner);
        $item = $batch->items()->first();
        $short = min((int) $item->expected_cash, mt_rand(0, 3) * 1000);
        $batch = $batches->receive($batch, $item, (int) $item->expected_cash - $short, $short ? 'نقصٌ عند العدّ' : null, $this->owner);

        if ($short > 0) {
            $batches->resolve($batch, $item->refresh(), mt_rand(0, 1) ? 'courier_debt' : 'written_off', 'معالجة العجز', $this->owner);
        }
        $batches->approve($batch->refresh(), $this->owner);
    }

    private function pay(): void
    {
        $pay = app(PayMerchantSettlement::class);
        $statement = $pay->confirm(app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner), $this->owner);
        $pay->pay($statement, $this->owner, 'cash', null, $this->box->refresh());
    }

    private function expense(): void
    {
        app(RecordExpense::class)->handle(['expense_category_id' => $this->category->id, 'amount' => mt_rand(1, 5) * 1000,
            'description' => 'وقود', 'pay_now' => true, 'cash_box_id' => $this->box->id], $this->owner);
    }

    private function withdraw(): void
    {
        $available = app(RecordProfitWithdrawal::class)->available();
        app(RecordProfitWithdrawal::class)->handle(max(1, intdiv($available, mt_rand(2, 4))), now(), $this->box->refresh(), 'الشريك', null, $this->owner);
    }

    private function create(int $cod): Shipment
    {
        return app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
        ], $this->owner);
    }

    private function walk(Shipment $shipment, array $path, ?Courier $courier = null): Shipment
    {
        foreach ($path as $status) {
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                'courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier?->id : null,
            ]);
        }

        return $shipment->refresh();
    }

    private function assertInvariants(string $where): void
    {
        $trace = $where."\n".implode("\n", $this->log);

        $this->assertSame(0, (int) DB::table('journal_lines')->where('company_id', $this->company->id)->sum(DB::raw('debit - credit')),
            "الميزان ليس صفراً — {$trace}");

        Tenancy::runFor($this->company, function () use ($trace) {
            $checks = collect(app(JournalCheck::class)->run())->keyBy('key');
            foreach (['balanced', 'boxes', 'custody', 'commissions', 'merchants', 'interbranch', 'transfers', 'unmapped', 'chain', 'audit_chain'] as $key) {
                $this->assertSame('ok', $checks[$key]['status'] ?? null, "فحص {$key}: ".($checks[$key]['detail'] ?? '').' — '.$trace);
            }

            $board = new ProfitDashboard;
            $all = ReportPeriod::fromRequest(new Request(['from' => now()->subYear()->toDateString(), 'to' => now()->toDateString()]));
            $revenue = $board->revenue($all)['total'];
            $this->assertSame($revenue, (int) $board->branches($all)->sum('revenue'), "مجموع الفروع ≠ الشركة — {$trace}");
            $this->assertLessThanOrEqual($revenue, $board->realized(null, null)['fees'], "المحصَّل فوق المعترف به — {$trace}");

            $available = $board->available();
            $this->assertGreaterThanOrEqual(0, $available['total'], "المتاح سالب — {$trace}");
            $this->assertLessThanOrEqual(max(0, $available['realized'] - $available['drawn']), $available['total'], "المتاح فوق المحصَّل بعد المسحوب — {$trace}");
        });
    }
}

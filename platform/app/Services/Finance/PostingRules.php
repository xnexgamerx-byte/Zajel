<?php

namespace App\Services\Finance;

use App\Enums\ShipmentStatus;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ProfitWithdrawal;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Support\Finance\Accounts as A;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * قواعد القيد (docs/plan/68 §٤): من كل حركةٍ قائمة قيدٌ متوازن.
 *
 * الحركات تُكتب اليوم في دفترين فرعيين: حسابات التجّار والمناديب (transactions) والصناديق
 * (cash_movements). كل صفٍّ منهما قيدٌ بنفسه، وطرفه الآخر حسابٌ وسيط:
 *  - «وسيط الشحنات» (1900): ما يُقيَّد على الشحنة في حساب تاجرها ومندوبها. ما بقي فيه بعد
 *    إغلاقها هو ما احتفظت به الشركة — أجورها — فيُرحَّل إلى الإيراد (recognizeShipment).
 *  - «وسيط النقد» (1950): تسليم المندوب نقده، ودفع التاجر والعمولة والسلفة، وسحب الأرباح —
 *    صفٌّ في حساب صاحبها وصفٌّ في الصندوق، فيتقاصّان. وما بقي نقدٌ تحرّك خارج الصناديق.
 *
 * فكل صفٍّ يُقيَّد وحده بمفتاحه، ويُعاد بناء التاريخ بالقواعد نفسها.
 */
class PostingRules
{
    /** @var array<string, array<int, ?int>> */
    protected array $branches = [];

    public function __construct(protected Journal $journal) {}

    public function transaction(Transaction $tx, bool $force = false): ?int
    {
        if (! in_array($tx->account_type, ['merchant', 'courier'], true)) {
            return null;
        }

        // مبلغٌ سالب (مستحقٌّ تحت الصفر حين تزيد الأجور على المحصَّل) هو الاتجاه المعاكس بقيمته
        $amount = abs((int) $tx->amount);
        $credit = ($tx->direction === 'credit') === ((int) $tx->amount >= 0);
        $shipmentBranch = $tx->shipment_id ? $this->branchOf('shipments', (int) $tx->shipment_id) : null;

        if ($tx->account_type === 'merchant') {
            $counter = match ($tx->category) {
                'shipment_due', 'return_fee', 'amount_correction', 'fee_correction' => A::SHIPMENT_CLEARING,
                'prepaid_fee'                                                       => A::PREPAID,
                'payout', 'advance', 'advance_repaid'                               => A::CASH_BRIDGE,
                default                                                             => A::UNMAPPED,
            };
            $dims = [
                'merchant_id' => (int) $tx->account_id,
                'shipment_id' => $tx->shipment_id,
                'branch_id'   => $shipmentBranch ?? $this->branchOf('merchants', (int) $tx->account_id),
            ];
            // له (credit): حساب التاجر دائن وطرفه مدين — وعليه بالعكس
            $lines = [
                ['account' => A::MERCHANTS, ($credit ? 'credit' : 'debit') => $amount] + $dims,
                ['account' => $counter, ($credit ? 'debit' : 'credit') => $amount] + $dims,
            ];
        } else {
            $commission = in_array($tx->category, ['commission', 'commission_paid'], true);
            $courierBranch = $this->branchOf('couriers', (int) $tx->account_id);

            if ($commission) {
                $account = A::COMMISSIONS;
                $counter = $tx->category === 'commission_paid'
                    ? A::CASH_BRIDGE
                    : ($tx->reference_type === 'pickup_share' ? A::PICKUP_SHARES : A::COURIER_COMMISSIONS);
                // العمولة له (credit): الالتزام دائن والتكلفة مدينة
                [$accountSide, $counterSide] = $credit ? ['credit', 'debit'] : ['debit', 'credit'];
            } else {
                // عجز الوجبة ذمّةٌ على المندوب في حسابٍ خاص، ورصيدها من نقده الذي بيده (cash_in_hand)
                $shortage = in_array($tx->category, ['shortage', 'shortage_recovered', 'shortage_written_off'], true);
                $account = $shortage ? A::SHORTAGES : A::CUSTODY;
                $counter = match ($tx->category) {
                    'cod_collected', 'amount_correction' => A::SHIPMENT_CLEARING,
                    'cash_handover', 'shortage', 'shortage_recovered' => A::CASH_BRIDGE,
                    'shortage_written_off'               => A::WRITE_OFFS,
                    'deduction'                          => A::OTHER_INCOME,
                    default                              => A::UNMAPPED,
                };
                // عليه (debit): العهدة مدينة — النقد بيده
                [$accountSide, $counterSide] = $credit ? ['credit', 'debit'] : ['debit', 'credit'];
            }

            $lines = [
                ['account' => $account, $accountSide => $amount, 'courier_id' => (int) $tx->account_id,
                    'shipment_id' => $tx->shipment_id, 'branch_id' => $courierBranch],
                ['account' => $counter, $counterSide => $amount, 'courier_id' => (int) $tx->account_id,
                    'shipment_id' => $tx->shipment_id,
                    'branch_id' => $counter === A::SHIPMENT_CLEARING ? ($shipmentBranch ?? $courierBranch) : $courierBranch],
            ];
        }

        return $this->journal->post(
            (int) $tx->company_id, 'tx:'.$tx->id, $tx->account_type.':'.$tx->category,
            $tx->created_at ?? now(), $lines, 'transaction', (int) $tx->id,
            $tx->description, $tx->created_by_user_id, $force,
        );
    }

    public function cashMovement(CashMovement $movement, bool $force = false): ?int
    {
        $counter = match ($movement->category) {
            'opening'                                                     => A::OPENING,
            'courier_handover', 'merchant_payout', 'commission_paid',
            'merchant_advance', 'advance_repaid', 'profit_withdrawal',
            'collection_shortage', 'shortage_recovered'                   => A::CASH_BRIDGE,
            'collection_excess'                                           => A::EXCESS_CASH,
            'merchant_deposit'                                            => A::DEPOSITS,
            'prepaid_fee'                                                 => A::PREPAID,
            'expense'                                                     => A::EXPENSES_DUE,
            'transfer_in', 'transfer_out'                                 => A::BOX_TRANSFERS,
            'adjustment'                                                  => A::COUNT_DIFFERENCES,
            'branch_remittance_out', 'branch_remittance_in'               => A::IN_TRANSIT,
            default                                                       => A::UNMAPPED,
        };

        $in = $movement->direction === 'in';
        $amount = (int) $movement->amount;
        $dims = ['cash_box_id' => (int) $movement->cash_box_id, 'branch_id' => $movement->branch_id];

        return $this->journal->post(
            (int) $movement->company_id, 'cm:'.$movement->id, 'cash:'.$movement->category,
            $movement->created_at ?? now(),
            [
                ['account' => A::CASH, ($in ? 'debit' : 'credit') => $amount] + $dims,
                ['account' => $counter, ($in ? 'credit' : 'debit') => $amount] + $dims,
            ],
            'cash_movement', (int) $movement->id, $movement->description, $movement->created_by_user_id, $force,
        );
    }

    /** المصروف يُعترف به بتاريخه، دُفع أم لم يُدفع؛ ودفعه حركة صندوقٍ تُطفئ استحقاقه */
    public function expenseRecorded(Expense $expense, bool $force = false): ?int
    {
        $dims = ['branch_id' => $expense->branch_id, 'expense_category_id' => $expense->expense_category_id];

        return $this->journal->post(
            (int) $expense->company_id, 'exp:'.$expense->id, 'expense_recorded',
            Carbon::parse($expense->spent_on), [
                ['account' => A::OPERATING_EXPENSES, 'debit' => (int) $expense->amount] + $dims,
                ['account' => A::EXPENSES_DUE, 'credit' => (int) $expense->amount] + $dims,
            ],
            'expense', (int) $expense->id, "{$expense->number} — {$expense->description}", $expense->created_by_user_id, $force,
        );
    }

    public function expenseCancelled(Expense $expense, bool $force = false): ?int
    {
        $dims = ['branch_id' => $expense->branch_id, 'expense_category_id' => $expense->expense_category_id];

        return $this->journal->post(
            (int) $expense->company_id, 'exp-cancel:'.$expense->id, 'expense_cancelled',
            $expense->cancelled_at ?? now(), [
                ['account' => A::EXPENSES_DUE, 'debit' => (int) $expense->amount] + $dims,
                ['account' => A::OPERATING_EXPENSES, 'credit' => (int) $expense->amount] + $dims,
            ],
            'expense', (int) $expense->id, "إلغاء {$expense->number} — {$expense->cancel_reason}", null, $force,
        );
    }

    /** سحب الأرباح حقوق ملكية لا مصروف؛ ونقده — إن خرج من صندوق — حركة الصندوق */
    public function withdrawal(ProfitWithdrawal $withdrawal, bool $force = false): ?int
    {
        return $this->journal->post(
            (int) $withdrawal->company_id, 'pw:'.$withdrawal->id, 'profit_withdrawal',
            $withdrawal->paid_at ?? $withdrawal->created_at ?? now(), [
                ['account' => A::DRAWINGS, 'debit' => (int) $withdrawal->amount],
                ['account' => A::CASH_BRIDGE, 'credit' => (int) $withdrawal->amount],
            ],
            'profit_withdrawal', (int) $withdrawal->id, $withdrawal->number, $withdrawal->created_by_user_id, $force,
        );
    }

    public function withdrawalCancelled(ProfitWithdrawal $withdrawal, bool $force = false): ?int
    {
        return $this->journal->post(
            (int) $withdrawal->company_id, 'pw-cancel:'.$withdrawal->id, 'profit_withdrawal_cancel',
            $withdrawal->cancelled_at ?? now(), [
                ['account' => A::CASH_BRIDGE, 'debit' => (int) $withdrawal->amount],
                ['account' => A::DRAWINGS, 'credit' => (int) $withdrawal->amount],
            ],
            'profit_withdrawal', (int) $withdrawal->id, "إلغاء {$withdrawal->number} — {$withdrawal->cancel_reason}",
            $withdrawal->cancelled_by_user_id, $force,
        );
    }

    /**
     * إثبات إيراد الشحنة: ما بقي في «وسيط الشحنات» بعد ما قُيِّد لتاجرها ومندوبها هو أجورها.
     *
     * يُستدعى في نهاية كل حدثٍ يمسّها (تسليم، رجوع، تصحيح مبلغ أو أجور)، فيُثبت الفرق وحده:
     * الإيراد أوّل مرّة، وتعديله بعدها. وشحنةٌ سُلِّمت بلا مندوبٍ مسجَّل يُقيَّد نقدها في
     * «تحصيلٌ بلا مندوب» (1290) — مالٌ قُبض ولا عهدة تحمله، يظهر في المطابقة.
     *
     * @param  int|null  $bound  للبناء من التاريخ: آخر صفٍّ من حركات الشحنة يدخل الحدث
     */
    public function recognizeShipment(Shipment $shipment, ?int $bound = null, ?CarbonInterface $at = null, bool $force = false): void
    {
        $companyId = (int) $shipment->company_id;
        $rows = DB::table('transactions')->where('company_id', $companyId)->where('shipment_id', $shipment->id);
        $bound ??= (int) (clone $rows)->max('id');

        if ($bound === 0) {
            return;
        }

        $within = (clone $rows)->where('id', '<=', $bound);
        $signed = fn ($q, string $positive) => (int) (clone $q)->sum(DB::raw("case when direction = '{$positive}' then amount else -amount end"));

        $merchantCorrections = $signed((clone $within)->where('account_type', 'merchant')->where('category', 'amount_correction'), 'credit');
        $courierCorrections = $signed((clone $within)->where('account_type', 'courier')->where('category', 'amount_correction'), 'debit');
        $delivered = $shipment->delivered_at !== null
            && (clone $within)->where('account_type', 'merchant')->where('category', 'shipment_due')->exists();
        $byCourier = (clone $within)->where('account_type', 'courier')->where('category', 'cod_collected')->exists();

        // ما حُصِّل يوم التسليم: المحصَّل الآن ناقص تصحيحاته كلّها
        $original = (int) $shipment->collected_amount
            - $signed((clone $rows)->where('account_type', 'merchant')->where('category', 'amount_correction'), 'credit');
        $outside = ($delivered && ! $byCourier ? $original : 0) + $merchantCorrections - $courierCorrections;

        $dims = ['shipment_id' => $shipment->id, 'merchant_id' => $shipment->merchant_id, 'branch_id' => $shipment->branch_id];
        $at ??= now();

        $diff = $outside - $this->balance($companyId, $shipment->id, A::OUTSIDE_COURIER);
        if ($diff !== 0) {
            $this->journal->post($companyId, "out:{$shipment->id}:{$bound}", 'outside_courier', $at, [
                ['account' => A::OUTSIDE_COURIER, ($diff > 0 ? 'debit' : 'credit') => abs($diff)] + $dims,
                ['account' => A::SHIPMENT_CLEARING, ($diff > 0 ? 'credit' : 'debit') => abs($diff)] + $dims,
            ], 'shipment', $shipment->id, "تحصيل الشحنة {$shipment->number} بلا مندوب مسجَّل", null, $force);
        }

        // رصيدٌ دائن في الوسيط = أجورٌ احتفظت بها الشركة
        $revenue = -$this->balance($companyId, $shipment->id, A::SHIPMENT_CLEARING);
        if ($revenue === 0) {
            return;
        }

        $account = $shipment->status === ShipmentStatus::Returned && $shipment->delivered_at === null ? A::RETURN_REVENUE : A::DELIVERY_REVENUE;

        $this->journal->post($companyId, "rev:{$shipment->id}:{$bound}", 'revenue', $at, [
            ['account' => A::SHIPMENT_CLEARING, ($revenue > 0 ? 'debit' : 'credit') => abs($revenue)] + $dims,
            ['account' => $account, ($revenue > 0 ? 'credit' : 'debit') => abs($revenue)] + $dims,
        ], 'shipment', $shipment->id, "أجور الشحنة {$shipment->number}", null, $force);
    }

    /** رصيد حسابٍ لشحنة: مدين − دائن */
    protected function balance(int $companyId, int $shipmentId, string $account): int
    {
        return (int) DB::table('journal_lines')->where('company_id', $companyId)
            ->where('shipment_id', $shipmentId)->where('account', $account)
            ->sum(DB::raw('debit - credit'));
    }

    protected function branchOf(string $table, int $id): ?int
    {
        if (! array_key_exists($id, $this->branches[$table] ?? [])) {
            $branch = DB::table($table)->where('id', $id)->value('branch_id');
            $this->branches[$table][$id] = $branch === null ? null : (int) $branch;
        }

        return $this->branches[$table][$id];
    }
}

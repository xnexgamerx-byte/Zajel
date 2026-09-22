<?php

namespace App\Actions\Cash;

use App\Models\CashBox;
use App\Models\Expense;
use App\Models\User;
use App\Services\CashBook;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسجيل مصروف، ودفعه.
 *
 * التسجيل والدفع خطوتان: فاتورة وقود وصلت اليوم وتُدفَع نهاية الأسبوع
 * التزامٌ على الشركة لم يغادر الدرج بعد. دمجُهما يجعل رصيد الصندوق
 * يكذب، وهو الرقم الوحيد الذي يُقارَن بعدّ اليد آخر اليوم.
 */
class RecordExpense
{
    public function __construct(
        protected SequenceGenerator $sequences,
        protected CashBook $cash,
    ) {}

    public function handle(array $data, ?User $actor = null): Expense
    {
        return DB::transaction(function () use ($data, $actor) {
            $expense = Expense::create([
                'branch_id'           => $data['branch_id'] ?? $actor?->branch_id,
                'expense_category_id' => $data['expense_category_id'],
                'number'              => $this->sequences->next('expense'),
                'amount'              => (int) $data['amount'],
                'spent_on'            => $data['spent_on'] ?? now()->toDateString(),
                'description'         => $data['description'],
                'payee'               => $data['payee'] ?? null,
                'reference'           => $data['reference'] ?? null,
                'status'              => 'recorded',
                'created_by_user_id'  => $actor?->id,
            ]);

            // الدفع فوراً حالة شائعة (نثريّة تُدفع نقداً)، لكنّه يبقى
            // خطوةً مستقلّة تُسجَّل حركتها في الصندوق
            if (! empty($data['pay_now'])) {
                return $this->pay($expense, $this->box($data, $expense), $actor);
            }

            return $expense;
        });
    }

    /** دفع مصروف مسجَّل من صندوق: هنا فقط يغادر النقد الدرج. */
    public function pay(Expense $expense, CashBox $box, ?User $actor = null): Expense
    {
        if ($expense->isLocked()) {
            throw ValidationException::withMessages([
                'status' => "المصروف {$expense->number} {$expense->statusLabel()} سلفاً.",
            ]);
        }

        return DB::transaction(function () use ($expense, $box, $actor) {
            $expense->forceFill([
                'status'          => 'paid',
                'cash_box_id'     => $box->id,
                'paid_at'         => now(),
                'paid_by_user_id' => $actor?->id,
            ])->save();

            $this->cash->out(
                box: $box,
                category: 'expense',
                amount: $expense->amount,
                description: "مصروف {$expense->number} — {$expense->description}",
                actor: $actor,
                referenceType: 'expense',
                referenceId: $expense->id,
            );

            return $expense->refresh();
        });
    }

    /**
     * إلغاء مصروف. المدفوع لا يُلغى بحذف حركته بل بحركة معاكسة تُعيد
     * المال إلى الصندوق، فيبقى سبب دخوله وخروجه مقروءاً في الجرد.
     */
    public function cancel(Expense $expense, string $reason, ?User $actor = null): Expense
    {
        if ($expense->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'هذا المصروف ملغى سلفاً.']);
        }

        return DB::transaction(function () use ($expense, $reason, $actor) {
            if ($expense->status === 'paid' && $expense->cashBox) {
                $this->cash->in(
                    box: $expense->cashBox,
                    category: 'expense',
                    amount: $expense->amount,
                    description: "إلغاء المصروف {$expense->number} — {$reason}",
                    actor: $actor,
                    referenceType: 'expense',
                    referenceId: $expense->id,
                );
            }

            $expense->forceFill([
                'status'        => 'cancelled',
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $expense->refresh();
        });
    }

    protected function box(array $data, Expense $expense): CashBox
    {
        $box = ! empty($data['cash_box_id'])
            ? CashBox::active()->find($data['cash_box_id'])
            : CashBox::forBranch($expense->branch_id);

        if (! $box) {
            throw ValidationException::withMessages([
                'cash_box_id' => 'لا يوجد صندوق مفعّل يُدفع منه. أنشئ صندوقاً أولاً.',
            ]);
        }

        return $box;
    }
}

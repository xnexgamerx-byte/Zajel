<?php

namespace App\Services;

use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

/**
 * دفتر القاصة — المكان الوحيد الذي يتغيّر فيه رصيد صندوق.
 *
 * دفتر الحركات (Ledger) يُجيب «مَن له ومَن عليه»، وهذا يُجيب «كم في
 * الدرج». سؤالان مختلفان: مندوب سلّم نقده فبرئت ذمّته وامتلأ الصندوق،
 * وتاجر قُيّد له مستحقّ ولم يُدفَع له بعد فلم يتغيّر الدرج بشيء.
 *
 * وقاعدتا الدفتر نفسهما هنا:
 *  1) لا يتغيّر رصيد صندوق إلّا بصفّ في cash_movements، في المعاملة نفسها.
 *  2) لا تعديل ولا حذف لصفّ. التصحيح بحركة معاكسة تحمل سببها.
 */
class CashBook
{
    public function in(
        CashBox $box,
        string $category,
        int $amount,
        ?string $description = null,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): ?CashMovement {
        return $this->post($box, 'in', $category, $amount, $description, $actor, $referenceType, $referenceId);
    }

    public function out(
        CashBox $box,
        string $category,
        int $amount,
        ?string $description = null,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): ?CashMovement {
        return $this->post($box, 'out', $category, $amount, $description, $actor, $referenceType, $referenceId);
    }

    /**
     * مناقلة بين صندوقين: حركتان مرتبطتان لا حركة واحدة، فالجرد يُقرأ
     * من طرفَي المناقلة معاً ولا يبدو المال وكأنه ظهر من العدم.
     */
    public function transfer(CashBox $from, CashBox $to, int $amount, ?string $description = null, ?User $actor = null): array
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_box_id' => 'المناقلة تكون بين صندوقين مختلفين.']);
        }

        return DB::transaction(function () use ($from, $to, $amount, $description, $actor) {
            $note = $description ?: "مناقلة {$from->name} ← {$to->name}";

            // الطرف الآخر يُكتب مع الحركة لا بعدها: الحركة لا تُعدَّل بعد كتابتها
            $sent = $this->post($from, 'out', 'transfer_out', $amount, $note, $actor, 'cash_box', $to->id, $to->id);
            $received = $this->post($to, 'in', 'transfer_in', $amount, $note, $actor, 'cash_box', $from->id, $from->id);

            return [$sent, $received];
        });
    }

    protected function post(
        CashBox $box,
        string $direction,
        string $category,
        int $amount,
        ?string $description,
        ?User $actor,
        ?string $referenceType,
        ?int $referenceId,
        ?int $counterpartBoxId = null,
    ): ?CashMovement {
        if ($amount === 0) {
            return null;
        }

        if ($amount < 0) {
            throw ValidationException::withMessages([
                'amount' => 'المبلغ موجب دائماً — الاتجاه يُحدَّد بنوع الحركة لا بإشارته.',
            ]);
        }

        return DB::transaction(function () use ($box, $direction, $category, $amount, $description, $actor, $referenceType, $referenceId, $counterpartBoxId) {
            // القفل يمنع حركتين متزامنتين من كتابة balance_after نفسه
            $fresh = CashBox::query()->lockForUpdate()->findOrFail($box->id);
            $balance = $fresh->balance + ($direction === 'in' ? $amount : -$amount);

            $fresh->forceFill(['balance' => $balance])->save();
            $box->setAttribute('balance', $balance);

            return CashMovement::create([
                'cash_box_id'        => $fresh->id,
                'branch_id'          => $fresh->branch_id,
                'direction'          => $direction,
                'category'           => $category,
                'amount'             => $amount,
                'balance_after'      => $balance,
                'reference_type'     => $referenceType,
                'reference_id'       => $referenceId,
                'counterpart_box_id' => $counterpartBoxId,
                'description'        => $description,
                'created_by_user_id' => $actor?->id,
            ]);
        });
    }

    /** هل رصيد الصندوق يطابق مجموع حركاته؟ */
    public function reconcile(CashBox $box): array
    {
        $ledger = (int) CashMovement::where('cash_box_id', $box->id)
            ->selectRaw("sum(case when direction = 'in' then amount else -amount end) as total")
            ->value('total');

        $stored = (int) CashBox::findOrFail($box->id)->balance;

        return [
            'matches' => $ledger === $stored,
            'ledger'  => $ledger,
            'stored'  => $stored,
            'drift'   => $stored - $ledger,
        ];
    }
}

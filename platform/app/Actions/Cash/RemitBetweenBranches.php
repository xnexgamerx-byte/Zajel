<?php

namespace App\Actions\Cash;

use App\Models\Branch;
use App\Models\BranchRemittance;
use App\Models\CashBox;
use App\Models\User;
use App\Services\CashBook;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «تسديد ديون الفروع» و«استلام المبالغ المسدّدة من الفروع».
 *
 * الإرسال يُخرج المبلغ من صندوق الفرع المسدِّد في الحال — النقد غادر الدرج
 * — والاستلام يُدخل ما وصل فعلاً إلى صندوق الفرع الآخر. بينهما «بالطريق»
 * لا في هذا الدرج ولا ذاك، والفرق بين المُرسَل والواصل لا يُسكَت عنه.
 */
class RemitBetweenBranches
{
    public function __construct(protected CashBook $cash, protected SequenceGenerator $sequences) {}

    public function send(Branch $from, Branch $to, CashBox $box, int $amount, ?User $actor = null, ?string $note = null): BranchRemittance
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_branch_id' => 'التسديد يكون لفرعٍ آخر.']);
        }

        if ((int) $box->branch_id !== (int) $from->id || $box->user_id !== null || ! $box->is_active) {
            throw ValidationException::withMessages(['from_box_id' => "اختر صندوقاً مفعّلاً من صناديق {$from->name}."]);
        }

        return DB::transaction(function () use ($from, $to, $box, $amount, $actor, $note) {
            $fresh = CashBox::query()->lockForUpdate()->findOrFail($box->id);

            if ($fresh->balance < $amount) {
                throw ValidationException::withMessages(['amount' => "في {$fresh->name} ".number_format($fresh->balance).' دينار فقط.']);
            }

            $remittance = BranchRemittance::create([
                'number'          => $this->sequences->next('branch_remittance'),
                'from_branch_id'  => $from->id,
                'to_branch_id'    => $to->id,
                'amount'          => $amount,
                'from_box_id'     => $fresh->id,
                'note'            => $note,
                'sent_at'         => now(),
                'sent_by_user_id' => $actor?->id,
                'status'          => 'sent',
            ]);

            $this->cash->out($fresh, 'branch_remittance_out', $amount,
                "تسديدٌ إلى {$to->name} — {$remittance->number}", $actor, 'branch_remittance', $remittance->id);

            return $remittance;
        });
    }

    public function receive(BranchRemittance $remittance, CashBox $box, int $receivedAmount, ?User $actor = null, ?string $differenceNote = null): BranchRemittance
    {
        return DB::transaction(function () use ($remittance, $box, $receivedAmount, $actor, $differenceNote) {
            // الحال بعد القفل: استلامان متزامنان كانا يُدخلانه الدرج مرّتين
            $remittance->setRawAttributes(BranchRemittance::query()->lockForUpdate()->findOrFail($remittance->id)->getAttributes(), true);

            if ($remittance->status !== 'sent') {
                throw ValidationException::withMessages(['remittance' => "{$remittance->number} مستلَمٌ سلفاً."]);
            }

            if ((int) $box->branch_id !== (int) $remittance->to_branch_id || $box->user_id !== null || ! $box->is_active) {
                throw ValidationException::withMessages(['to_box_id' => 'يُستلم في صندوقٍ مفعّل من صناديق الفرع المستلم.']);
            }

            if ($receivedAmount !== (int) $remittance->amount && blank($differenceNote)) {
                throw ValidationException::withMessages(['difference_note' => 'الواصل غير المُرسَل: اكتب سبب الفرق.']);
            }

            $remittance->forceFill([
                'status'              => 'received',
                'received_amount'     => $receivedAmount,
                'to_box_id'           => $box->id,
                'difference_note'     => $receivedAmount === (int) $remittance->amount ? null : $differenceNote,
                'received_at'         => now(),
                'received_by_user_id' => $actor?->id,
            ])->save();

            $this->cash->in($box, 'branch_remittance_in', $receivedAmount,
                "مسدَّدٌ من {$remittance->fromBranch?->name} — {$remittance->number}", $actor, 'branch_remittance', $remittance->id);

            return $remittance->refresh();
        });
    }
}

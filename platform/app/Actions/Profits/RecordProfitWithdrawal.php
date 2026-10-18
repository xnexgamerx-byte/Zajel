<?php

namespace App\Actions\Profits;

use App\Actions\Money\UndoWithinDay;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\ProfitWithdrawal;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Money\Profits;
use App\Services\SequenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * سحب الأرباح: عمليةٌ منفصلة عن تحصيل المناديب. لا يزيد على المتحقّق نقداً ممّا اعتُمد
 * ناقص ما سُحب قبله، ويخرج من صندوقٍ إن اختير فيظهر في جرده. ويُلغى في يومه وحده.
 */
class RecordProfitWithdrawal
{
    public function __construct(protected CashBook $cash, protected SequenceGenerator $sequences, protected Profits $profits) {}

    public function handle(int $amount, Carbon $on, ?CashBox $box, ?string $withdrawnBy, ?string $note, User $actor): ProfitWithdrawal
    {
        return DB::transaction(function () use ($amount, $on, $box, $withdrawnBy, $note, $actor) {
            // سحبان متزامنان لا يقرآن المتاح نفسه: القفل على سجلّ الشركة
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();

            $available = $this->profits->available();

            if ($amount < 1 || $amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => 'المتاح للسحب '.number_format(max(0, $available)).' د.ع — الربح المتحقّق نقداً من الوجبات المعتمدة ناقص ما سُحب قبله.',
                ]);
            }

            $withdrawal = ProfitWithdrawal::create([
                'number'             => $this->sequences->next('profit_withdrawal'),
                'amount'             => $amount,
                'withdrawn_on'       => $on->toDateString(),
                'withdrawn_by'       => $withdrawnBy,
                'cash_box_id'        => $box?->id,
                'note'               => $note,
                'created_by_user_id' => $actor->id,
            ]);

            if ($box) {
                $this->cash->out(
                    box: $box,
                    category: 'profit_withdrawal',
                    amount: $amount,
                    description: "سحب أرباح {$withdrawal->number}".($withdrawnBy ? " — استلمه {$withdrawnBy}" : ''),
                    actor: $actor,
                    referenceType: 'profit_withdrawal',
                    referenceId: $withdrawal->id,
                );
            }

            return $withdrawal;
        });
    }

    public function cancel(ProfitWithdrawal $withdrawal, string $reason, User $actor): ProfitWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $reason, $actor) {
            $withdrawal = ProfitWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($withdrawal->isCancelled()) {
                throw ValidationException::withMessages(['withdrawal' => "السحب {$withdrawal->number} ملغى سلفاً."]);
            }

            if (! UndoWithinDay::open($withdrawal->created_at)) {
                throw ValidationException::withMessages(['withdrawal' => 'مضت أربعٌ وعشرون ساعة على السحب: لا يُلغى، صحّحه بعمليةٍ جديدة.']);
            }

            $movement = CashMovement::where('reference_type', 'profit_withdrawal')->where('reference_id', $withdrawal->id)->first();

            if ($movement) {
                $this->cash->reverse($movement, $actor, $reason);
            }

            $withdrawal->forceFill([
                'status'               => 'cancelled',
                'cancelled_at'         => now(),
                'cancel_reason'        => $reason,
                'cancelled_by_user_id' => $actor->id,
            ])->save();

            return $withdrawal;
        });
    }
}

<?php

namespace App\Actions\Profits;

use App\Actions\Money\UndoWithinDay;
use App\Models\AuditLog;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\ProfitWithdrawal;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Finance\PostingRules;
use App\Services\Finance\ProfitDashboard;
use App\Services\SequenceGenerator;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * سحب الأرباح (docs/plan/68 §١٠): طلبٌ ← معتمد ← مدفوع، أو ملغى قبل الدفع، أو معكوسٌ في يومه.
 *
 * - المتاح: الأصغر من النقد الحرّ والمحصَّل نقداً المتراكم بعد المسحوب (البطاقة «ح» في لوحة
 *   الأرباح)، ناقصاً ما طُلب أو اعتُمد ولم يُدفع. ويُعاد حسابه داخل القفل لحظة الدفع.
 * - مفتاح الطلب فريدٌ للشركة: نموذجٌ أُرسل مرّتين سحبٌ واحد.
 * - فوق حدٍّ تحدّده الشركة (finance.withdrawal_approval_over) يعتمد غيرُ من طلب.
 * - حقوق ملكية (3300) لا مصروف، ويخرج من صندوقٍ أو حسابٍ مصرفيّ فيظهر في جرده.
 */
class RecordProfitWithdrawal
{
    public function __construct(protected CashBook $cash, protected SequenceGenerator $sequences) {}

    /** المتاح للسحب الآن للشركة كلّها */
    public function available(): int
    {
        return (new ProfitDashboard)->available()['total'];
    }

    /** طُلب أو اعتُمد ولم يُدفع: محجوزٌ من المتاح */
    public function committed(?int $except = null): int
    {
        return (int) ProfitWithdrawal::query()->whereIn('status', ['requested', 'approved'])
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))->sum('amount');
    }

    /** حدّ الاعتماد المزدوج: فوقه لا يعتمد الطالب سحبه — صفرٌ يعني بلا حدّ */
    public static function approvalOver(): int
    {
        return max(0, (int) Tenancy::company()?->setting('finance.withdrawal_approval_over', 0));
    }

    public function request(int $amount, Carbon $on, CashBox $box, ?string $beneficiary, ?string $reason, User $actor, ?string $key = null): ProfitWithdrawal
    {
        return DB::transaction(function () use ($amount, $on, $box, $beneficiary, $reason, $actor, $key) {
            $this->lockCompany($actor);

            if ($key && ($same = ProfitWithdrawal::where('idempotency_key', $key)->first())) {
                return $same;
            }

            $available = $this->available();
            $free = $available - $this->committed();

            if ($amount < 1 || $amount > $free) {
                throw ValidationException::withMessages(['amount' => $this->tooMuch($free)]);
            }

            if (! $box->is_active) {
                throw ValidationException::withMessages(['cash_box_id' => 'الصندوق موقوف: اختر صندوقاً أو حساباً مفعّلاً.']);
            }

            try {
                $withdrawal = DB::transaction(fn () => ProfitWithdrawal::create([
                    'number'             => $this->sequences->next('profit_withdrawal'),
                    'status'             => 'requested',
                    'amount'             => $amount,
                    'withdrawn_on'       => $on->toDateString(),
                    'withdrawn_by'       => $beneficiary,
                    'cash_box_id'        => $box->id,
                    'note'               => $reason,
                    'created_by_user_id' => $actor->id,
                    'available_before'   => $available,
                    'available_after'    => $available - $amount,
                    'idempotency_key'    => $key,
                ]));
            } catch (UniqueConstraintViolationException) {
                return ProfitWithdrawal::where('idempotency_key', $key)->firstOrFail();
            }

            $this->audit($withdrawal, $actor, 'profit_withdrawal_requested', $reason);

            return $withdrawal;
        });
    }

    public function approve(ProfitWithdrawal $withdrawal, User $actor): ProfitWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $actor) {
            $this->lockCompany($actor);
            $withdrawal = $this->lock($withdrawal, ['requested'], 'لا يُعتمد إلّا سحبٌ مطلوب.');

            $over = static::approvalOver();
            if ($over > 0 && (int) $withdrawal->amount > $over && (int) $withdrawal->created_by_user_id === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'السحب فوق '.number_format($over).' د.ع يعتمده غيرُ من طلبه (إعداد الشركة).',
                ]);
            }

            $free = $this->available() - $this->committed($withdrawal->id);
            if ((int) $withdrawal->amount > $free) {
                throw ValidationException::withMessages(['withdrawal' => $this->tooMuch($free)]);
            }

            $withdrawal->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()])->save();
            $this->audit($withdrawal, $actor, 'profit_withdrawal_approved');

            return $withdrawal;
        });
    }

    /** الدفع: المتاح يُعاد حسابه هنا تحت القفل، ثم يُكتب القيد ويخرج النقد */
    public function pay(ProfitWithdrawal $withdrawal, User $actor): ProfitWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $actor) {
            $this->lockCompany($actor);
            $withdrawal = $this->lock($withdrawal, ['approved'], 'لا يُدفع إلّا سحبٌ معتمد — وما دُفع لا يُدفع ثانيةً.');

            $available = $this->available();
            if ((int) $withdrawal->amount > $available) {
                throw ValidationException::withMessages(['withdrawal' => $this->tooMuch($available)]);
            }

            $box = CashBox::query()->lockForUpdate()->findOrFail($withdrawal->cash_box_id);

            $withdrawal->forceFill([
                'status' => 'paid', 'paid_by_user_id' => $actor->id, 'paid_at' => now(),
                'available_before' => $available, 'available_after' => $available - (int) $withdrawal->amount,
            ])->save();

            // حقوق ملكية لا مصروف؛ والصندوق يُطفئ طرفه في «وسيط النقد»
            app(PostingRules::class)->withdrawal($withdrawal);

            $this->cash->out(
                box: $box,
                category: 'profit_withdrawal',
                amount: (int) $withdrawal->amount,
                description: "سحب أرباح {$withdrawal->number}".($withdrawal->withdrawn_by ? " — استلمه {$withdrawal->withdrawn_by}" : ''),
                actor: $actor,
                referenceType: 'profit_withdrawal',
                referenceId: $withdrawal->id,
            );

            $this->audit($withdrawal, $actor, 'profit_withdrawal_paid');

            return $withdrawal;
        });
    }

    /**
     * طلبٌ واعتمادٌ ودفعٌ معاً: لمن يملك الطلب والاعتماد وسحبُه تحت حدّ الاعتماد المزدوج.
     */
    public function handle(int $amount, Carbon $on, ?CashBox $box, ?string $beneficiary, ?string $reason, User $actor, ?string $key = null): ProfitWithdrawal
    {
        if (! $box) {
            throw ValidationException::withMessages(['cash_box_id' => 'اختر الصندوق أو الحساب المصرفي الذي يخرج منه السحب.']);
        }

        return DB::transaction(function () use ($amount, $on, $box, $beneficiary, $reason, $actor, $key) {
            $withdrawal = $this->request($amount, $on, $box, $beneficiary, $reason, $actor, $key);

            if ($withdrawal->status === 'requested') {
                $withdrawal = $this->approve($withdrawal, $actor);
            }

            return $withdrawal->status === 'approved' ? $this->pay($withdrawal, $actor) : $withdrawal;
        });
    }

    /**
     * قبل الدفع: إلغاءٌ بلا أثرٍ في المال. وبعده: عكسٌ في يومه وحده بسببٍ مكتوب — يعود المبلغ
     * إلى الصندوق ويُكتب القيد العكسي. وما مضى يومه يُصحَّح بعمليةٍ جديدة.
     */
    public function cancel(ProfitWithdrawal $withdrawal, string $reason, User $actor): ProfitWithdrawal
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'اكتب السبب: يبقى في سجلّ السحب.']);
        }

        return DB::transaction(function () use ($withdrawal, $reason, $actor) {
            $withdrawal = $this->lock($withdrawal, ['requested', 'approved', 'paid'], "السحب {$withdrawal->number} ملغى أو معكوس سلفاً.");
            $paid = $withdrawal->status === 'paid';

            if ($paid && ! UndoWithinDay::open($withdrawal->paid_at ?? $withdrawal->created_at)) {
                throw ValidationException::withMessages(['withdrawal' => 'مضت أربعٌ وعشرون ساعة على الدفع: لا يُعكس، صحّحه بعمليةٍ جديدة.']);
            }

            if ($paid) {
                $movement = CashMovement::where('reference_type', 'profit_withdrawal')->where('reference_id', $withdrawal->id)->first();

                if ($movement) {
                    $this->cash->reverse($movement, $actor, $reason);
                }
            }

            $withdrawal->forceFill([
                'status'               => $paid ? 'reversed' : 'cancelled',
                'cancelled_at'         => now(),
                'cancel_reason'        => $reason,
                'cancelled_by_user_id' => $actor->id,
            ])->save();

            if ($paid) {
                app(PostingRules::class)->withdrawalCancelled($withdrawal);
            }

            $this->audit($withdrawal, $actor, $paid ? 'profit_withdrawal_reversed' : 'profit_withdrawal_cancelled', $reason);

            return $withdrawal;
        });
    }

    /** سحبان متزامنان لا يقرآن المتاح نفسه: القفل على سجلّ الشركة */
    protected function lockCompany(User $actor): void
    {
        DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
    }

    /** @param  list<string>  $statuses */
    protected function lock(ProfitWithdrawal $withdrawal, array $statuses, string $message): ProfitWithdrawal
    {
        $withdrawal = ProfitWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

        if (! in_array($withdrawal->status, $statuses, true)) {
            throw ValidationException::withMessages(['withdrawal' => $message]);
        }

        return $withdrawal;
    }

    protected function tooMuch(int $free): string
    {
        return 'المتاح للسحب '.number_format(max(0, $free)).' د.ع — الأصغر من النقد الحرّ والمحصَّل نقداً بعد المسحوب، ناقصاً ما طُلب ولم يُدفع.';
    }

    protected function audit(ProfitWithdrawal $withdrawal, User $actor, string $action, ?string $reason = null): void
    {
        AuditLog::create([
            'user_id'        => $actor->id,
            'user_name'      => $actor->name,
            'action'         => $action,
            'auditable_type' => ProfitWithdrawal::class,
            'auditable_id'   => $withdrawal->id,
            'new_values'     => array_filter([
                'number' => $withdrawal->number, 'amount' => (int) $withdrawal->amount, 'status' => $withdrawal->status,
                'reason' => $reason, 'available_before' => $withdrawal->available_before, 'available_after' => $withdrawal->available_after,
            ], fn ($v) => $v !== null),
            'ip'             => request()->ip(),
        ]);
    }
}

<?php

namespace App\Actions\Pickups;

use App\Models\CashBox;
use App\Models\Courier;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * دفع عمولة مندوب الاستلام.
 *
 * تسوية مندوب التوصيل تُبنى على شحنات بيده نقدُها، ومندوب الاستلام لا
 * نقد بيده: حسابه عمولة صافية. فمرَّ عليه عامٌ في النظام المرجعي وله
 * شاشة حساب مستقلّة، ولا يُقفَل حسابه بكشف تسوية نقد لا معنى له.
 */
class PayPickupCommission
{
    public function __construct(
        protected Ledger $ledger,
        protected CashBook $cash,
    ) {}

    public function handle(Courier $courier, ?User $actor = null, ?CashBox $box = null, ?string $note = null): int
    {
        $due = (int) $courier->commission_balance;

        if ($due <= 0) {
            throw ValidationException::withMessages([
                'courier_id' => "لا عمولة مستحقّة للمندوب {$courier->name}.",
            ]);
        }

        // اعتراض معلّق يعني أن الرقم لم يستقرّ بعد
        if ($courier->pickupShares()->objected()->exists()) {
            throw ValidationException::withMessages([
                'courier_id' => 'على هذا المندوب اعتراض لم يُبتّ فيه. احسمه قبل الدفع.',
            ]);
        }

        return DB::transaction(function () use ($courier, $actor, $box, $due, $note) {
            $this->ledger->payCommission($courier, $due, $actor, $note);

            $box ??= CashBox::forBranch($courier->branch_id);

            if ($box) {
                $this->cash->out(
                    box: $box,
                    category: 'commission_paid',
                    amount: $due,
                    description: "عمولة استلام — {$courier->name}".($note ? " ({$note})" : ''),
                    actor: $actor,
                    referenceType: 'courier',
                    referenceId: $courier->id,
                );
            }

            return $due;
        });
    }
}

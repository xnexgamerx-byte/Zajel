<?php

namespace App\Actions\Cash;

use App\Models\CashBox;
use App\Models\Merchant;
use App\Models\MerchantDeposit;
use App\Models\User;
use App\Services\CashBook;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تأمين التاجر: إيداعاً وردّاً وخصماً.
 *
 * التأمين مالُ التاجر محجوزاً لا مالُ الشركة، ولا يدخل رصيده في كشف
 * حسابه: كشفٌ يخلطهما يُظهر للتاجر مالاً يملكه وهو غير قابل للسحب.
 * والخصم منه يُقيَّد بسببه، فلا يُنقَص تأمين بلا أثر.
 */
class ManageDeposit
{
    public function __construct(protected CashBook $cash) {}

    public function deposit(Merchant $merchant, int $amount, ?User $actor = null, ?CashBox $box = null, ?string $reason = null): MerchantDeposit
    {
        return $this->post($merchant, 'in', 'deposit', $amount, $actor, $box, $reason);
    }

    public function refund(Merchant $merchant, int $amount, ?User $actor = null, ?CashBox $box = null, ?string $reason = null): MerchantDeposit
    {
        return $this->post($merchant, 'out', 'refund', $amount, $actor, $box, $reason);
    }

    /** الخصم لا يمرّ بلا سبب: تأمين يُنقَص بلا بيان هو خلاف مؤجَّل. */
    public function forfeit(Merchant $merchant, int $amount, string $reason, ?User $actor = null): MerchantDeposit
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'الخصم من التأمين لا يمرّ بلا سبب.']);
        }

        // الخصم لا يُخرج نقداً من الدرج: المال بقي عندنا وتحوّل إلى إيراد
        return $this->post($merchant, 'out', 'forfeit', $amount, $actor, null, $reason);
    }

    protected function post(
        Merchant $merchant,
        string $direction,
        string $kind,
        int $amount,
        ?User $actor,
        ?CashBox $box,
        ?string $reason,
    ): MerchantDeposit {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يكون موجباً.']);
        }

        return DB::transaction(function () use ($merchant, $direction, $kind, $amount, $actor, $box, $reason) {
            $fresh = Merchant::query()->lockForUpdate()->findOrFail($merchant->id);

            /*
            | التحقّق بعد القفل لا قبله.
            |
            | كان الغطاء يُفحَص على النسخة التي بيد المستدعي ثم يُقفَل
            | الصفّ داخل المعاملة — فطلبا ردٍّ متزامنان يقرآن الرصيد
            | نفسه، ويمرّان معاً، ويخرج تأمينٌ أكثر ممّا أُودع. القفل
            | أولاً ثم القراءة ثم الفحص: هذا ما يجعله شرطاً لا رجاءً.
            */
            if ($direction === 'out' && $amount > (int) $fresh->deposit_balance) {
                throw ValidationException::withMessages([
                    'amount' => 'تأمين '.$fresh->business_name.' '
                        .number_format($fresh->deposit_balance).' دينار فقط.',
                ]);
            }

            $balance = (int) $fresh->deposit_balance + ($direction === 'in' ? $amount : -$amount);

            $fresh->forceFill(['deposit_balance' => $balance])->save();
            $merchant->setAttribute('deposit_balance', $balance);

            $row = MerchantDeposit::create([
                'branch_id'          => $fresh->branch_id,
                'merchant_id'        => $fresh->id,
                'direction'          => $direction,
                'kind'               => $kind,
                'amount'             => $amount,
                'balance_after'      => $balance,
                'cash_box_id'        => $box?->id,
                'reason'             => $reason,
                'created_by_user_id' => $actor?->id,
            ]);

            // الإيداع والردّ يمرّان بالدرج؛ الخصم لا
            if ($box) {
                $method = $direction === 'in' ? 'in' : 'out';

                $this->cash->{$method}(
                    box: $box,
                    category: 'merchant_deposit',
                    amount: $amount,
                    description: $row->kindLabel()." — {$fresh->business_name}".($reason ? " ({$reason})" : ''),
                    actor: $actor,
                    referenceType: 'merchant_deposit',
                    referenceId: $row->id,
                );
            }

            return $row;
        });
    }
}

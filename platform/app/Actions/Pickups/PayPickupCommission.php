<?php

namespace App\Actions\Pickups;

use App\Models\CashBox;
use App\Models\Courier;
use App\Models\PickupPayout;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Services\SequenceGenerator;
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
        protected SequenceGenerator $sequences,
    ) {}

    /**
     * @param  bool  $withoutBox  «بلا صندوق»: قيدٌ محاسبيّ وحده، لا يخرج نقدٌ من درج
     */
    public function handle(Courier $courier, ?User $actor = null, ?CashBox $box = null, ?string $note = null, bool $withoutBox = false): int
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

        return DB::transaction(function () use ($courier, $actor, $box, $due, $note, $withoutBox) {
            /*
            | الشريك: يُقفل استحقاقه كلّه، ويُدفع له ما بعد حصّة المركز. حصّة
            | المركز لا تخرج من صندوق — تبقى للشركة — فالصندوق يُنقص بالمدفوع
            | وحده، والدفتر يُقفل المستحقّ كلّه بقيدٍ واحد يذكر القسمة.
            */
            $cut = $courier->centreCut($due);
            $paid = $due - $cut;

            $split = $cut > 0 ? 'للمركز '.number_format($cut).' وللشريك '.number_format($paid) : null;
            $this->ledger->payCommission($courier, $due, $actor, collect([$note, $split])->filter()->implode('، ') ?: null);

            $box = $withoutBox ? null : ($box ?? CashBox::forActor($actor, $courier->branch_id));

            if ($box && $paid > 0) {
                $this->cash->out(
                    box: $box,
                    category: 'commission_paid',
                    amount: $paid,
                    description: "عمولة استلام — {$courier->name}".($note ? " ({$note})" : ''),
                    actor: $actor,
                    referenceType: 'courier',
                    referenceId: $courier->id,
                );
            }

            PickupPayout::create([
                'courier_id'      => $courier->id,
                'number'          => $this->sequences->next('pickup_payout'),
                'earned'          => $due,
                'centre_type'     => $courier->partner_centre_type ?? 'none',
                'centre_value'    => (int) $courier->partner_centre_value,
                'centre_amount'   => $cut,
                'paid_amount'     => $paid,
                'cash_box_id'     => $box?->id,
                'note'            => $note,
                'paid_by_user_id' => $actor?->id,
            ]);

            return $paid;
        });
    }
}

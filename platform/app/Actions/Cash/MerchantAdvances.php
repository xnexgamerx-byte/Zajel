<?php

namespace App\Actions\Cash;

use App\Models\CashBox;
use App\Models\Merchant;
use App\Models\MerchantAdvance;
use App\Models\MerchantAdvanceRecovery;
use App\Models\MerchantSettlement;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * سلف التجّار (docs/plan/38): «الشركة تعطي التاجر سلفة» وتستردّها من طلباته الواصلة.
 *
 * - الإعطاء: يخرج المبلغ من صندوقٍ (ولا يُعطى أكثر ممّا فيه — CashBook)، ويُقيَّد على
 *   التاجر في دفتره (advance) فينقص رصيده بها.
 * - الاسترداد: كل كشفٍ يُقفَل للتاجر يُقتطع منه ما بقي من سلفه، الأقدم أوّلاً، بقدر
 *   صافيه لا أكثر — فيُدفع له الكشف ناقصاً بما اقتُطع، ويعود رصيده بمستحقّه.
 * - أو يسدّد نقداً: يدخل الصندوق، ويُقيَّد له (advance_repaid).
 *
 * والحساب بالدفتر في كل حال: سلفةٌ A، ومستحقّاتٌ D، وكشفٌ دُفع منه D−R، يبقى رصيده
 * R−A: صفراً إن استُردّت كلّها، وسالباً بما بقي عليه إن لم تُستردّ.
 */
class MerchantAdvances
{
    public function __construct(
        protected CashBook $cash,
        protected Ledger $ledger,
        protected SequenceGenerator $sequences,
    ) {}

    /** ما بقي على التاجر من سلفه المفتوحة */
    public static function outstanding(Merchant|int $merchant): int
    {
        return (int) MerchantAdvance::open()
            ->where('merchant_id', $merchant instanceof Merchant ? $merchant->id : $merchant)
            ->selectRaw('coalesce(sum(amount - recovered), 0) as remaining')
            ->value('remaining');
    }

    public function give(Merchant $merchant, int $amount, CashBox $box, User $actor, ?string $note = null): MerchantAdvance
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'مبلغ السلفة يكون موجباً.']);
        }

        return DB::transaction(function () use ($merchant, $amount, $box, $actor, $note) {
            $advance = MerchantAdvance::create([
                'branch_id'          => $merchant->branch_id,
                'merchant_id'        => $merchant->id,
                'number'             => $this->sequences->next('merchant_advance'),
                'amount'             => $amount,
                'cash_box_id'        => $box->id,
                'note'               => $note,
                'created_by_user_id' => $actor->id,
            ]);

            // الصندوق أوّلاً: ما ليس فيه المبلغ يمنع السلفة كلّها قبل أن يُقيَّد شيء
            $this->cash->out(
                box: $box,
                category: 'merchant_advance',
                amount: $amount,
                description: "سلفة {$advance->number} للتاجر {$merchant->business_name}",
                actor: $actor,
                referenceType: 'merchant_advance',
                referenceId: $advance->id,
            );

            $this->ledger->recordAdvance($advance->setRelation('merchant', $merchant), $actor);

            return $advance;
        });
    }

    /** سدادٌ نقديّ من التاجر: يُوزَّع على سلفه المفتوحة، الأقدم أوّلاً. */
    public function repay(Merchant $merchant, int $amount, CashBox $box, User $actor, ?string $note = null): int
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['repay_amount' => 'المبلغ يكون موجباً.']);
        }

        return DB::transaction(function () use ($merchant, $amount, $box, $actor, $note) {
            $open = $this->lockOpen($merchant->id);
            $remaining = (int) $open->sum(fn (MerchantAdvance $a) => $a->remaining());

            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'repay_amount' => "ما بقي على {$merchant->business_name} من السلف ".number_format($remaining).' د.ع فقط.',
                ]);
            }

            $this->cash->in(
                box: $box,
                category: 'advance_repaid',
                amount: $amount,
                description: "سداد سلفة من التاجر {$merchant->business_name}".($note ? " — {$note}" : ''),
                actor: $actor,
                referenceType: 'merchant',
                referenceId: $merchant->id,
            );

            $this->ledger->recordAdvanceRepaid($merchant, $amount, $actor, 'سداد سلفة نقداً'.($note ? " — {$note}" : ''));
            $this->allocate($open, $amount, $actor, ['kind' => 'cash', 'cash_box_id' => $box->id]);

            return $amount;
        });
    }

    /**
     * يقتطع من كشفٍ أُقفِل الآن ما بقي من سلف تاجره، بقدر صافيه لا أكثر.
     * يُستدعى داخل معاملة الإقفال (PayMerchantSettlement::confirm).
     */
    public function recoverFrom(MerchantSettlement $settlement, ?User $actor = null): int
    {
        $net = (int) $settlement->net_amount;

        if ($net <= 0 || (int) $settlement->advance_deduction > 0) {
            return 0;
        }

        $open = $this->lockOpen($settlement->merchant_id);
        $take = min($net, (int) $open->sum(fn (MerchantAdvance $a) => $a->remaining()));

        if ($take <= 0) {
            return 0;
        }

        $this->allocate($open, $take, $actor, ['kind' => 'settlement', 'merchant_settlement_id' => $settlement->id]);

        $settlement->forceFill([
            'advance_deduction' => $take,
            'net_amount'        => $net - $take,
        ])->save();

        return $take;
    }

    /** @return \Illuminate\Support\Collection<int, MerchantAdvance> */
    protected function lockOpen(int $merchantId)
    {
        return MerchantAdvance::open()->where('merchant_id', $merchantId)
            ->orderBy('id')->lockForUpdate()->get();
    }

    /** يوزّع المستردّ على السلف بترتيبها، ويُغلق ما سُدِّد كلّه. */
    protected function allocate($open, int $amount, ?User $actor, array $source): void
    {
        foreach ($open as $advance) {
            if ($amount <= 0) {
                break;
            }

            $part = min($amount, $advance->remaining());

            if ($part <= 0) {
                continue;
            }

            MerchantAdvanceRecovery::create($source + [
                'merchant_advance_id' => $advance->id,
                'amount'              => $part,
                'created_by_user_id'  => $actor?->id,
                'created_at'          => now(),
            ]);

            $recovered = (int) $advance->recovered + $part;

            $advance->forceFill([
                'recovered' => $recovered,
                'status'    => $recovered >= (int) $advance->amount ? 'repaid' : 'open',
                'repaid_at' => $recovered >= (int) $advance->amount ? now() : null,
            ])->save();

            $amount -= $part;
        }
    }
}

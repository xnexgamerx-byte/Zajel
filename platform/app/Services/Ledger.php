<?php

namespace App\Services;

use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\MerchantSettlement;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * دفتر الحركات — المكان الوحيد الذي يتحرّك فيه المال.
 *
 * قاعدتان لا تُكسران:
 *  1) كل تغيّر في رصيد يُرافقه صفّ في transactions، في المعاملة نفسها.
 *     الرصيد على merchants/couriers مجرّد تسريع قراءة؛ الحقيقة في الدفتر.
 *  2) لا تعديل ولا حذف لصفّ. التصحيح بحركة معاكسة، حتى يبقى الحساب
 *     قابلاً للتدقيق أمام تاجر يسأل "منين طلع هذا الرقم؟".
 *
 * الاتجاه من منظور صاحب الحساب:
 *   credit = له   (الشركة مدينة له)
 *   debit  = عليه (هو مدين للشركة)
 */
class Ledger
{
    /** الشحنة سُلِّمت: التاجر يستحقّ، والمندوب صار بيده نقد الشركة. */
    public function recordDelivery(Shipment $shipment, ?User $actor = null): void
    {
        DB::transaction(function () use ($shipment, $actor) {
            $merchant = $shipment->merchant;

            $this->post(
                merchant: $merchant,
                direction: 'credit',
                category: 'shipment_due',
                amount: $shipment->merchant_due,
                shipment: $shipment,
                description: "مستحقّ الشحنة {$shipment->number}",
                actor: $actor,
            );

            if ($courier = $shipment->deliveryCourier) {
                // النقد المحصَّل بيد المندوب وهو أمانة الشركة
                $this->post(
                    courier: $courier,
                    direction: 'debit',
                    category: 'cod_collected',
                    amount: $shipment->collected_amount,
                    shipment: $shipment,
                    description: "تحصيل الشحنة {$shipment->number}",
                    actor: $actor,
                );

                // العمولة تُستحقّ الآن وتُدفَع عند التسوية
                if ($shipment->courier_commission > 0) {
                    $this->post(
                        courier: $courier,
                        direction: 'credit',
                        category: 'commission',
                        amount: $shipment->courier_commission,
                        shipment: $shipment,
                        description: "عمولة توصيل الشحنة {$shipment->number}",
                        actor: $actor,
                    );
                }
            }
        });
    }

    /** الشحنة رجعت: لا تحصيل، وأجرة الراجع على التاجر. */
    public function recordReturn(Shipment $shipment, ?User $actor = null): void
    {
        DB::transaction(function () use ($shipment, $actor) {
            if ($shipment->return_fee > 0) {
                $this->post(
                    merchant: $shipment->merchant,
                    direction: 'debit',
                    category: 'return_fee',
                    amount: $shipment->return_fee,
                    shipment: $shipment,
                    description: "أجرة راجع الشحنة {$shipment->number}",
                    actor: $actor,
                );
            }

            if (($courier = $shipment->deliveryCourier) && $shipment->courier_commission > 0) {
                $this->post(
                    courier: $courier,
                    direction: 'credit',
                    category: 'commission',
                    amount: $shipment->courier_commission,
                    shipment: $shipment,
                    description: "عمولة إرجاع الشحنة {$shipment->number}",
                    actor: $actor,
                );
            }
        });
    }

    /**
     * المندوب سلّم النقد وقُبضت عمولته: يفرغ عمودَيه معاً.
     *
     * الخصومات (تلف، غرامة) تزيد ما عليه، لأنها مال الشركة لم يعد لديها.
     */
    public function recordCourierHandover(CourierSettlement $settlement, ?User $actor = null): void
    {
        DB::transaction(function () use ($settlement, $actor) {
            $courier = $settlement->courier;
            $code = $settlement->code;

            // الترتيب مقصود: يُحمَّل الخصم أولاً فيصير جزءاً ممّا عليه،
            // ثم يُسلَّم النقد كاملاً. بالعكس يبقى الخصم معلّقاً في رصيده
            // بعد تسوية يُفترض أنها أقفلت حسابه.
            $this->post(
                courier: $courier,
                direction: 'debit',
                category: 'deduction',
                amount: $settlement->deductions,
                description: "خصم (تلف أو غرامة) — كشف {$code}",
                actor: $actor,
                referenceType: 'courier_settlement',
                referenceId: $settlement->id,
            );

            $this->post(
                courier: $courier,
                direction: 'credit',
                category: 'cash_handover',
                amount: $settlement->cod_total + $settlement->deductions,
                description: "تسليم نقد — كشف {$code}",
                actor: $actor,
                referenceType: 'courier_settlement',
                referenceId: $settlement->id,
            );

            $this->post(
                courier: $courier,
                direction: 'debit',
                category: 'commission_paid',
                amount: $settlement->commission_total,
                description: "قبض عمولة — كشف {$code}",
                actor: $actor,
                referenceType: 'courier_settlement',
                referenceId: $settlement->id,
            );
        });
    }

    /** دُفع للتاجر مستحقّه: رصيده ينخفض بما قُبض. */
    public function recordMerchantPayout(MerchantSettlement $settlement, ?User $actor = null): void
    {
        $this->post(
            merchant: $settlement->merchant,
            direction: 'debit',
            category: 'payout',
            amount: $settlement->net_amount,
            description: "دفعة للتاجر — كشف {$settlement->code}",
            actor: $actor,
            referenceType: 'merchant_settlement',
            referenceId: $settlement->id,
        );
    }

    /**
     * يكتب الحركة ويُحدّث الرصيد المشتقّ معاً.
     * القفل على صفّ الحساب يمنع تضارب رصيدين عند تسليمين متزامنين.
     */
    protected function post(
        string $direction,
        string $category,
        int $amount,
        ?Merchant $merchant = null,
        ?Courier $courier = null,
        ?Shipment $shipment = null,
        ?string $description = null,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): ?Transaction {
        if ($amount === 0) {
            return null;
        }

        $account = $merchant ?? $courier;

        if (! $account) {
            return null;
        }

        $accountType = $merchant ? 'merchant' : 'courier';
        $signed = $direction === 'credit' ? $amount : -$amount;

        // كل فئة تُحدّث عمودها. المندوب له رقمان: النقد الذي بيده فعلاً،
        // والعمولة المستحقّة له. خلطهما يُنتج رقماً لا يُجيب أي سؤال.
        [$balanceColumn, $delta] = match (true) {
            $merchant !== null                 => ['balance', $signed],
            in_array($category, ['commission', 'commission_paid'], true)
                                               => ['commission_balance', $signed],
            default                            => ['cash_in_hand', -$signed],
        };

        $fresh = $account->newQuery()->lockForUpdate()->findOrFail($account->id);
        $newBalance = $fresh->{$balanceColumn} + $delta;

        $fresh->forceFill([$balanceColumn => $newBalance])->save();

        return Transaction::create([
            'account_type'       => $accountType,
            'account_id'         => $account->id,
            'direction'          => $direction,
            'category'           => $category,
            'amount'             => $amount,
            'balance_after'      => $newBalance,
            'shipment_id'        => $shipment?->id,
            'reference_type'     => $referenceType,
            'reference_id'       => $referenceId,
            'description'        => $description,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * مطابقة الرصيد المخزَّن مع مجموع الدفتر.
     *
     * هذا ليس ترفاً: الرصيد على الصفّ مجرّد تسريع قراءة، وأي انحراف بينه
     * وبين الدفتر يعني أن أحد الطرفين يُحاسَب برقم خاطئ. يُشغَّل دورياً.
     */
    public function reconcile(string $accountType, int $accountId): array
    {
        $sum = fn (array $categories = []) => (int) Transaction::forAccount($accountType, $accountId)
            ->when($categories, fn ($q) => $q->whereIn('category', $categories))
            ->when($categories === [], fn ($q) => $q->whereNotIn('category', ['commission', 'commission_paid']))
            ->selectRaw("sum(case when direction = 'credit' then amount else -amount end) as total")
            ->value('total');

        if ($accountType === 'merchant') {
            $ledger = (int) Transaction::forAccount('merchant', $accountId)
                ->selectRaw("sum(case when direction = 'credit' then amount else -amount end) as total")
                ->value('total');

            $stored = (int) Merchant::findOrFail($accountId)->balance;

            return $this->result(['balance' => [$ledger, $stored]]);
        }

        $courier = Courier::findOrFail($accountId);

        return $this->result([
            'cash_in_hand'       => [-$sum(), (int) $courier->cash_in_hand],
            'commission_balance' => [$sum(['commission', 'commission_paid']), (int) $courier->commission_balance],
        ]);
    }

    /** @param  array<string, array{int, int}>  $pairs  [مجموع الدفتر, المخزَّن] */
    protected function result(array $pairs): array
    {
        $columns = [];
        $matches = true;

        foreach ($pairs as $column => [$ledger, $stored]) {
            $columns[$column] = [
                'ledger'  => $ledger,
                'stored'  => $stored,
                'drift'   => $stored - $ledger,
                'matches' => $ledger === $stored,
            ];

            $matches = $matches && $ledger === $stored;
        }

        return ['matches' => $matches, 'columns' => $columns];
    }
}

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

    /**
     * حصّة مندوب الاستلام تُستحقّ عند إغلاق طلب الاستلام.
     *
     * لا نقد بيد مندوب الاستلام — هو يجمع طروداً لا أموالاً — فحركته
     * عمولةٌ وحدها، ولذلك تُقفَل بدفع العمولة لا بتسوية نقد.
     */
    public function recordPickupShare(\App\Models\PickupShare $share, Courier $courier, ?User $actor = null): void
    {
        $this->post(
            courier: $courier,
            direction: 'credit',
            category: 'commission',
            amount: (int) $share->amount,
            description: "حصّة استلام {$share->shipments_count} طرداً — طلب {$share->pickupRequest?->number}",
            actor: $actor,
            referenceType: 'pickup_share',
            referenceId: $share->id,
        );
    }

    /** قبض العمولة: يُفرّغ عمود العمولة وحده ولا يمسّ النقد. */
    public function payCommission(Courier $courier, int $amount, ?User $actor = null, ?string $note = null): void
    {
        $this->post(
            courier: $courier,
            direction: 'debit',
            category: 'commission_paid',
            amount: $amount,
            description: 'قبض عمولة'.($note ? " — {$note}" : ''),
            actor: $actor,
            referenceType: 'courier',
            referenceId: $courier->id,
        );
    }

    /** فرق الحصّة بعد قبول الاعتراض — حركة مستقلّة تحمل سببها. */
    public function recordShareAdjustment(\App\Models\PickupShare $share, int $delta, ?User $actor = null, ?string $reason = null): void
    {
        if ($delta === 0) {
            return;
        }

        $this->post(
            courier: $share->courier,
            direction: $delta > 0 ? 'credit' : 'debit',
            category: 'commission',
            amount: abs($delta),
            description: 'تعديل حصّة استلام'.($reason ? " — {$reason}" : ''),
            actor: $actor,
            referenceType: 'pickup_share',
            referenceId: $share->id,
        );
    }

    /**
     * تصحيح مبلغ محصَّل بعد تسجيل التسليم.
     *
     * لا يُمسّ الصفّ القديم: يُقيَّد الفرق بحركة معاكسة تحمل سببها، فيبقى
     * أمام التاجر سطران يشرحان كيف صار الرقم ما صار، لا رقم تغيّر وحده.
     * الفرق نفسه على الجهتين: نقد بيد المندوب، ومستحقّ للتاجر.
     */
    public function recordAmountCorrection(Shipment $shipment, int $delta, ?User $actor = null, ?string $reason = null): void
    {
        if ($delta === 0) {
            return;
        }

        DB::transaction(function () use ($shipment, $delta, $actor, $reason) {
            $why = $reason ? " — {$reason}" : '';

            $this->post(
                merchant: $shipment->merchant,
                direction: $delta > 0 ? 'credit' : 'debit',
                category: 'amount_correction',
                amount: abs($delta),
                shipment: $shipment,
                description: "تصحيح مبلغ الشحنة {$shipment->number}{$why}",
                actor: $actor,
            );

            if ($courier = $shipment->deliveryCourier) {
                $this->post(
                    courier: $courier,
                    direction: $delta > 0 ? 'debit' : 'credit',
                    category: 'amount_correction',
                    amount: abs($delta),
                    shipment: $shipment,
                    description: "تصحيح تحصيل الشحنة {$shipment->number}{$why}",
                    actor: $actor,
                );
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

    /**
     * مطابقة الدفتر بالشحنات — ما لا تراه reconcile().
     *
     * reconcile() يطابق الرصيد المخزَّن بمجموع القيود، فيقول «مطابق» ما
     * دام العمودان متّفقين — ولو كانت القيود نفسها لا تُطابق الشحنات.
     * رأيتُ تاجراً رصيده سالب ٧٣٣ مليوناً و reconcile() يقول مطابق: دُفع
     * له كشفٌ عن آلاف الشحنات المسلَّمة، ولم يُقيَّد لأيٍّ منها مستحقّ.
     *
     * والثابت هنا لكل شحنة: ما قُيِّد عليها في حساب تاجرها (مستحقّ،
     * أجرة رجوع، تصحيح مبلغ) يساوي merchant_due إن كانت في حالٍ يتحرّك
     * فيها المال (مسلَّمة، مسلَّمة جزئياً، راجعة)، وصفراً فيما عداها.
     *
     * @return \Illuminate\Support\Collection<int, object{id:int, number:string, merchant_id:int, status:string, expected:int, posted:int}>
     */
    public function shipmentsOffLedger(?int $merchantId = null, ?\DateTimeInterface $since = null, int $limit = 200)
    {
        [$query, $expected, $states] = $this->offLedgerQuery($merchantId, $since);

        return $query
            ->select('shipments.id', 'shipments.number', 'shipments.merchant_id', 'shipments.status')
            ->selectRaw("{$expected} as expected", $states)
            ->selectRaw('coalesce(posted.posted, 0) as posted')
            ->orderBy('shipments.id')
            ->limit($limit)
            ->toBase()
            ->get();
    }

    /** الشيء نفسه مجمَّعاً بالتاجر: كم شحنة، وكم كان يجب أن يُقيَّد، وكم قُيِّد. */
    public function offLedgerByMerchant(?\DateTimeInterface $since = null)
    {
        [$query, $expected, $states] = $this->offLedgerQuery(null, $since);

        return $query
            ->select('shipments.merchant_id')
            ->selectRaw('count(*) as shipments')
            ->selectRaw("sum({$expected}) as expected", $states)
            ->selectRaw('sum(coalesce(posted.posted, 0)) as posted')
            ->groupBy('shipments.merchant_id')
            ->toBase()
            ->get();
    }

    /** @return array{\Illuminate\Database\Eloquent\Builder, string, array<int, string>} */
    protected function offLedgerQuery(?int $merchantId, ?\DateTimeInterface $since): array
    {
        $states = [
            \App\Enums\ShipmentStatus::Delivered->value,
            \App\Enums\ShipmentStatus::PartiallyDelivered->value,
            \App\Enums\ShipmentStatus::Returned->value,
        ];

        $posted = Transaction::query()
            ->select('shipment_id')
            ->selectRaw("sum(case when direction = 'credit' then amount else -amount end) as posted")
            ->where('account_type', 'merchant')
            ->whereIn('category', ['shipment_due', 'return_fee', 'amount_correction'])
            ->whereNotNull('shipment_id')
            ->groupBy('shipment_id');

        // عناصر نائبة لا قيم: لا شيء من المُدخَل يدخل نصّ الاستعلام
        $expected = 'case when shipments.status in ('.implode(',', array_fill(0, count($states), '?')).') then shipments.merchant_due else 0 end';

        $query = Shipment::query()
            ->leftJoinSub($posted, 'posted', 'posted.shipment_id', '=', 'shipments.id')
            ->when($merchantId, fn ($q) => $q->where('shipments.merchant_id', $merchantId))
            ->when($since, fn ($q) => $q->where('shipments.status_changed_at', '>=', $since))
            ->whereRaw("coalesce(posted.posted, 0) <> {$expected}", $states);

        return [$query, $expected, $states];
    }

    /**
     * أرصدة مخزَّنة لا تساوي مجموع دفترها — للتجّار والمناديب كلّهم دفعةً.
     *
     * reconcile() لحسابٍ واحد؛ هذه للشركة كلّها باستعلامٍ لكل نوع لا
     * استعلامين لكل حساب: خمسمئة تاجر لا تصير ألف استعلام.
     *
     * @return array{merchants: \Illuminate\Support\Collection, couriers: \Illuminate\Support\Collection}
     */
    public function balancesOff(): array
    {
        $signed = "sum(case when direction = 'credit' then amount else -amount end)";

        $merchantLedger = Transaction::query()->where('account_type', 'merchant')
            ->selectRaw("account_id, {$signed} as ledger")->groupBy('account_id')
            ->toBase()->pluck('ledger', 'account_id');

        $merchants = Merchant::query()->get(['id', 'business_name', 'balance'])
            ->map(fn (Merchant $m) => (object) [
                'id' => $m->id, 'name' => $m->business_name,
                'stored' => (int) $m->balance, 'ledger' => (int) ($merchantLedger[$m->id] ?? 0),
            ])
            ->filter(fn ($row) => $row->stored !== $row->ledger)
            ->values();

        $courierLedger = Transaction::query()->where('account_type', 'courier')
            ->selectRaw("account_id,
                sum(case when category in ('commission', 'commission_paid') then 0 else (case when direction = 'credit' then amount else -amount end) end) as cash,
                sum(case when category in ('commission', 'commission_paid') then (case when direction = 'credit' then amount else -amount end) else 0 end) as commission")
            ->groupBy('account_id')
            ->toBase()->get()->keyBy('account_id');

        $couriers = Courier::withTrashed()->get(['id', 'name', 'cash_in_hand', 'commission_balance'])
            ->map(function (Courier $c) use ($courierLedger) {
                $row = $courierLedger[$c->id] ?? null;

                // النقد بيد المندوب سالبُ مجموع قيوده (ما حصّله مدينٌ به للشركة)
                return (object) [
                    'id' => $c->id, 'name' => $c->name,
                    'cash_stored' => (int) $c->cash_in_hand, 'cash_ledger' => -(int) ($row->cash ?? 0),
                    'commission_stored' => (int) $c->commission_balance, 'commission_ledger' => (int) ($row->commission ?? 0),
                ];
            })
            ->filter(fn ($row) => $row->cash_stored !== $row->cash_ledger || $row->commission_stored !== $row->commission_ledger)
            ->values();

        return ['merchants' => $merchants, 'couriers' => $couriers];
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

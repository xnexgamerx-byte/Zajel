<?php

namespace App\Services\Money;

use App\Models\BranchRemittance;
use App\Models\CashBox;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «الموقف المالي» كما في المعتاد: ما عندنا، وما لنا، وما علينا — الآن.
 *
 * كل رقمٍ من مصدره الذي يُحاسَب به: الصناديق من أرصدتها، والمناديب والتجّار
 * من أرصدة دفترهم. والإجماليّ ما لنا ناقص ما علينا. ديون الفروع فيما بينها
 * تُعرض ولا تُجمع: على مستوى الشركة تتقاصّ.
 */
final class FinancialPosition
{
    /** الأرقام بترتيب عرضها: [المفتاح => [العنوان، الاتجاه]] — والاتجاه asset | liability | info */
    public const FIGURES = [
        'safe'                => ['القاصة حالياً', 'asset'],
        'boxes'               => ['صناديق الفروع والنثريّة', 'asset'],
        'employee_boxes'      => ['صناديق الموظّفين', 'asset'],
        'with_couriers'       => ['مبالغ عند المندوبين', 'asset'],
        'in_transit'          => ['تسديداتٌ بين الفروع بالطريق', 'asset'],
        'merchant_debts'      => ['ديون على التجّار', 'asset'],
        'merchant_payables'   => ['دفوعات مستحقّة للتجّار', 'liability'],
        'courier_commissions' => ['عمولات مستحقّة للمناديب', 'liability'],
        'deposits_held'       => ['تأمينات التجّار عندنا', 'liability'],
    ];

    /**
     * والناظر المقيَّد بفرع يرى موقف فرعه: صناديقه وتجّاره ومناديبه وحوالاته.
     *
     * @return array{figures: array<string,int>, total: int, payables_by_pickup: array<string,int>}
     */
    public function now(?User $viewer = null): array
    {
        $boxes = CashBox::active()->visibleTo($viewer)->selectRaw("
                sum(case when user_id is null and type = 'main' then balance else 0 end) as safe,
                sum(case when user_id is null and type <> 'main' then balance else 0 end) as boxes,
                sum(case when user_id is not null then balance else 0 end) as employee_boxes")
            ->toBase()->first();

        $merchants = Merchant::query()->visibleTo($viewer)->selectRaw('
                sum(case when balance < 0 then -balance else 0 end) as debts,
                sum(case when balance > 0 then balance else 0 end) as payables,
                sum(deposit_balance) as deposits')
            ->toBase()->first();

        $couriers = Courier::query()->visibleTo($viewer)->selectRaw('
                sum(case when cash_in_hand > 0 then cash_in_hand else 0 end) as cash,
                sum(case when commission_balance > 0 then commission_balance else 0 end) as commissions')
            ->toBase()->first();

        $figures = [
            'safe'                => (int) ($boxes->safe ?? 0),
            'boxes'               => (int) ($boxes->boxes ?? 0),
            'employee_boxes'      => (int) ($boxes->employee_boxes ?? 0),
            'with_couriers'       => (int) ($couriers->cash ?? 0),
            'in_transit'          => (int) BranchRemittance::pending()
                ->when($viewer?->isBranchLimited(), fn ($q) => $q->where(fn ($w) => $w
                    ->where('from_branch_id', $viewer->branch_id)->orWhere('to_branch_id', $viewer->branch_id)))
                ->sum('amount'),
            'merchant_debts'      => (int) ($merchants->debts ?? 0),
            'merchant_payables'   => (int) ($merchants->payables ?? 0),
            'courier_commissions' => (int) ($couriers->commissions ?? 0),
            'deposits_held'       => (int) ($merchants->deposits ?? 0),
        ];

        $total = 0;
        foreach (self::FIGURES as $key => [, $side]) {
            $total += $side === 'asset' ? $figures[$key] : -$figures[$key];
        }

        return [
            'figures'            => $figures,
            'total'              => $total,
            // «ومقسّمة حسب مندوب الاستلام»: من يحمل المال لتجّاره
            'payables_by_pickup' => $this->payablesByPickupCourier($viewer),
        ];
    }

    /** @return array<string, int> */
    private function payablesByPickupCourier(?User $viewer): array
    {
        return Merchant::query()
            ->visibleTo($viewer)
            ->where('merchants.balance', '>', 0)
            ->leftJoin('couriers', 'couriers.id', '=', 'merchants.pickup_courier_id')
            ->selectRaw("coalesce(couriers.name, 'بلا مندوب استلام') as name, sum(merchants.balance) as total")
            ->groupBy('couriers.name')
            ->orderByDesc('total')
            ->toBase()
            ->pluck('total', 'name')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}

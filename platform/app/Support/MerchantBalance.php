<?php

namespace App\Support;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Models\Merchant;
use App\Models\MerchantAdvance;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * حساب التاجر مفصَّلاً حتى يتطابق كلّ رقمٍ مع ما وراءه (docs/plan/49، docs/plan/51):
 *
 *  - «إجمالي المستحقات»: رصيده في الدفتر — كلّ ما له عند الشركة.
 *  - «قيد المطابقة»: واصلٌ لم تحاسب الشركةُ مندوبه بعد — نقده ما زال بيد المندوب.
 *  - «المتاح للتسوية»: ما يحمله كشفٌ يُبنى الآن (أو المسودّة المفتوحة) بعد اقتطاع السلف —
 *    من شرط الكشف نفسه (BuildMerchantSettlement::eligibleScope)، فلا يَعِد بما لا يحمله الكشف.
 *  - «بانتظار الدفع»: كشوفٌ أُقفلت ولم يُسجَّل دفعها — مالها ما زال في رصيده.
 *  - «سلف عليه»: ما بقي من سلفه، يُقتطع من الكشف القادم.
 *
 * فالإجمالي = قيد المطابقة + المتاح للتسوية + بانتظار الدفع − السلف. وما لا يفسّره ذلك
 * (unexplained) فرقٌ في الدفتر يُعرض للموظّف ليراجعه، لا يُخفى في رقمٍ آخر.
 */
final class MerchantBalance
{
    private function __construct(
        public readonly int $total,
        public readonly int $pending,
        public readonly int $pendingCount,
        public readonly ?string $oldestPending,
        public readonly int $ready,
        public readonly int $readyCount,
        public readonly int $confirmed,
        public readonly int $confirmedCount,
        public readonly ?MerchantSettlement $confirmedFirst,
        public readonly ?MerchantSettlement $draft,
        public readonly int $advances,
    ) {}

    public static function of(Merchant $merchant): self
    {
        return self::many(collect([$merchant]))[$merchant->id];
    }

    /**
     * حسابات تجّارٍ كثيرين باستعلاماتٍ معدودة — لا استعلاماتٍ لكلّ تاجر.
     *
     * @param  Collection<int, Merchant>  $merchants
     * @return Collection<int, self> مفهرسة برقم التاجر
     */
    public static function many(Collection $merchants): Collection
    {
        $ids = $merchants->pluck('id')->all();

        if ($ids === []) {
            return collect();
        }

        $pending = self::awaitingCourier(Shipment::query()->whereIn('merchant_id', $ids))
            ->whereNull('merchant_settlement_id')
            ->groupBy('merchant_id')
            ->toBase()
            ->selectRaw('merchant_id, count(*) as n, coalesce(sum(merchant_due), 0) as due, min(delivered_at) as oldest')
            ->get()->keyBy('merchant_id');

        $ready = BuildMerchantSettlement::eligibleScope(Shipment::query()->whereIn('merchant_id', $ids))
            ->groupBy('merchant_id')
            ->toBase()
            ->selectRaw('merchant_id, count(*) as n, coalesce(sum(merchant_due), 0) as due')
            ->get()->keyBy('merchant_id');

        $statements = MerchantSettlement::query()
            ->whereIn('merchant_id', $ids)
            ->whereIn('status', ['draft', 'confirmed'])
            ->orderBy('id')
            ->get(['id', 'merchant_id', 'code', 'status', 'net_amount', 'shipments_count'])
            ->groupBy('merchant_id');

        $advances = MerchantAdvance::open()
            ->whereIn('merchant_id', $ids)
            ->groupBy('merchant_id')
            ->toBase()
            ->selectRaw('merchant_id, coalesce(sum(amount - recovered), 0) as left_over')
            ->pluck('left_over', 'merchant_id');

        return $merchants->mapWithKeys(function (Merchant $m) use ($pending, $ready, $statements, $advances) {
            $wait = $pending[$m->id] ?? null;
            $ok = $ready[$m->id] ?? null;
            $own = $statements[$m->id] ?? collect();
            $confirmed = $own->where('status', 'confirmed');

            return [$m->id => new self(
                total: (int) $m->balance,
                pending: (int) ($wait->due ?? 0),
                pendingCount: (int) ($wait->n ?? 0),
                oldestPending: $wait->oldest ?? null,
                ready: (int) ($ok->due ?? 0),
                readyCount: (int) ($ok->n ?? 0),
                confirmed: (int) $confirmed->sum('net_amount'),
                confirmedCount: $confirmed->count(),
                confirmedFirst: $confirmed->first(),
                draft: $own->firstWhere('status', 'draft'),
                advances: max(0, (int) ($advances[$m->id] ?? 0)),
            )];
        });
    }

    /**
     * واصلٌ مع مندوبٍ لم يُحاسَب عليه بعد. وما سلّمه المكتب بلا مندوب لا ينتظر أحداً.
     *
     * @param  Builder<Shipment>  $q
     * @return Builder<Shipment>
     */
    public static function awaitingCourier(Builder $q): Builder
    {
        return $q->whereNotNull('delivered_at')
            ->whereNotNull('delivery_courier_id')
            ->whereNull('courier_settled_at');
    }

    /**
     * عكسه: ما يجوز أن يدخل كشف التاجر — كلّ شيءٍ إلّا الواصل الذي ينتظر محاسبة مندوبه.
     *
     * @param  Builder<Shipment>  $q
     * @return Builder<Shipment>
     */
    public static function cleared(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('delivered_at')
            ->orWhereNull('delivery_courier_id')
            ->orWhereNotNull('courier_settled_at'));
    }

    /**
     * «المتاح للتسوية»: صافي الكشف الذي يُبنى الآن — شحناته ناقصاً ما يُقتطع للسلف عند إقفاله
     * (MerchantAdvances::recoverFrom يأخذ الأقلّ من الصافي والسلف). هو رقم الكشف نفسه.
     */
    public function toSettle(): int
    {
        return max(0, $this->ready - $this->advances);
    }

    /** ما يقتطعه الكشف القادم من السلف */
    public function advanceDeduction(): int
    {
        return min(max(0, $this->ready), $this->advances);
    }

    /** «المتاح للسحب» عند التاجر: ما يُدفع له دون انتظار أحد — كشفٌ يُبنى، وكشوفٌ أُقفلت ولم تُدفع */
    public function available(): int
    {
        return $this->toSettle() + max(0, $this->confirmed);
    }

    /**
     * ما في الرصيد ولا يفسّره قيدُ المطابقة ولا الكشوف ولا السلف — يجب أن يكون صفراً.
     * غيرُ الصفر أثرُ قيدٍ في الدفتر لا يقابله كشف: يُعرض للموظّف ليراجعه في «مطابقة الدفتر».
     */
    public function unexplained(): int
    {
        return $this->total - ($this->pending + $this->ready + $this->confirmed - $this->advances);
    }

    /** لك عند الشركة، أو عليك لها */
    public function owed(): bool
    {
        return $this->total >= 0;
    }

    /** سبب «قيد المطابقة» بكلام التاجر */
    public function reason(): ?string
    {
        if ($this->pendingCount === 0) {
            return null;
        }

        return 'عن '.Arabic::shipments($this->pendingCount).' واصلة، نقدها ما زال مع المندوب — يصير متاحاً حين تحاسبه الشركة.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total'            => abs($this->total),
            'owed'             => $this->owed(),
            'available'        => $this->available(),
            'pending'          => $this->pending,
            'pending_count'    => $this->pendingCount,
            'reason'           => $this->reason(),
            'awaiting_payment' => max(0, $this->confirmed),
            'advances'         => $this->advances,
        ];
    }
}

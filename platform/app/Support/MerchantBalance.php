<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * حساب التاجر بثلاثة أرقام (docs/plan/49):
 *
 *  - «إجمالي المستحقات»: كلّ ما للتاجر عند الشركة (رصيده في الدفتر).
 *  - «قيد المطابقة»: ما وصل زبونه ولم تحاسب الشركةُ مندوبه عليه بعد — نقده ما زال
 *    بيد المندوب، فلا يُدفع للتاجر ما لم يصل الشركة.
 *  - «المتاح للسحب»: الإجمالي ناقصاً ما قيد المطابقة. هذا وحده يُطلب ويُدفع.
 */
final class MerchantBalance
{
    private function __construct(
        public readonly int $total,
        public readonly int $pending,
        public readonly int $pendingCount,
        public readonly ?string $oldestPending,
    ) {}

    public static function of(Merchant $merchant): self
    {
        $row = self::awaitingCourier(Shipment::where('merchant_id', $merchant->id))
            ->whereNull('merchant_settlement_id')
            ->toBase()
            ->selectRaw('count(*) as n, coalesce(sum(merchant_due), 0) as due, min(delivered_at) as oldest')
            ->first();

        return new self(
            total: (int) $merchant->balance,
            // ما قيد المطابقة جزءٌ ممّا له، لا يزيد عليه: سلفةٌ أو أجرة راجعٍ تُنقص الإجمالي قبله
            pending: max(0, min((int) $row->due, (int) $merchant->balance)),
            pendingCount: (int) $row->n,
            oldestPending: $row->oldest,
        );
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

    /** المتاح للسحب: لا يقلّ عن صفر، ولا يزيد على الإجمالي */
    public function available(): int
    {
        return max(0, min($this->total, $this->total - $this->pending));
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
            'total'         => abs($this->total),
            'owed'          => $this->owed(),
            'available'     => $this->available(),
            'pending'       => $this->pending,
            'pending_count' => $this->pendingCount,
            'reason'        => $this->reason(),
        ];
    }
}

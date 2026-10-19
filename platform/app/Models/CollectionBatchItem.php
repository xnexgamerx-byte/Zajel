<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سطرٌ في الوجبة: كشف مندوبٍ مُقفَل بأرقامه، أو تحصيلٌ لعجزٍ سابق يُشير إلى سطره.
 */
class CollectionBatchItem extends Model
{
    use BelongsToCompany;

    public const STATUSES = [
        'pending_cash' => 'بانتظار النقد',
        'received'     => 'مطابق',
        'short'        => 'عجز',
        'over'         => 'زيادة',
    ];

    public const RESOLUTIONS = [
        'courier_debt' => 'ذمّة على المندوب',
        'written_off'  => 'مشطوب',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CollectionBatch::class, 'collection_batch_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }

    /** سطر العجز الذي يُسدّده هذا التحصيل */
    public function remainderOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remainder_of_item_id');
    }

    /** ما سُدِّد من عجز هذا السطر في وجباتٍ لاحقة */
    public function remainders(): HasMany
    {
        return $this->hasMany(self::class, 'remainder_of_item_id');
    }

    public function isRemainder(): bool
    {
        return $this->kind === 'remainder';
    }

    /** الفرق: ما عُدَّ ناقص المتوقَّع — سالبه عجز. وما لم يُستلم نقده بعد لا فرق له */
    public function discrepancy(): int
    {
        return $this->isRemainder() || $this->status === 'pending_cash' ? 0 : (int) $this->received_cash - (int) $this->expected_cash;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_cash';
    }

    /** ما بقي من عجز السطر لم يُسدَّد بعد */
    public function outstanding(): int
    {
        if ($this->resolution === 'written_off') {
            return 0;
        }

        $short = -min(0, $this->discrepancy());

        return max(0, $short - (int) $this->remainders()->sum('received_cash'));
    }
}

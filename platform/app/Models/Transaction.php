<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفتر الحركات — لا يُعدَّل ولا يُحذف صفّ منه.
 * التصحيح يكون بحركة معاكسة، وهذا ما يجعل الحساب قابلاً للتدقيق.
 */
class Transaction extends Model
{
    use AppendOnly, BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** الشحنة التي نتجت عنها الحركة — كشف الحساب يربط كل سطر بسببه. */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function scopeForAccount(Builder $q, string $type, int $id): Builder
    {
        return $q->where('account_type', $type)->where('account_id', $id);
    }

    /** المبلغ بإشارته من منظور صاحب الحساب. */
    public function signedAmount(): int
    {
        return $this->direction === 'credit' ? $this->amount : -$this->amount;
    }
}

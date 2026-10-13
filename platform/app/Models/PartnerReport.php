<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تقرير شريكٍ بأنّ الطرد سُلِّم (docs/plan/64): لا يُحسب مالياً حتى نراجعه ونوافق.
 */
class PartnerReport extends Model
{
    use BelongsToCompany;

    public const DELIVERED = 'delivered';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'decided_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function key(): BelongsTo
    {
        return $this->belongsTo(PartnerKey::class, 'partner_key_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereNull('decided_at');
    }
}

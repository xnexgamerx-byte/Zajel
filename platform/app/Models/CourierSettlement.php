<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierSettlement extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_date'    => 'date',
            'to_date'      => 'date',
            'confirmed_at' => 'datetime',
            'paid_at'      => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CourierSettlementShipment::class);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['confirmed', 'paid'], true);
    }
}

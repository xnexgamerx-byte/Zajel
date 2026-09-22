<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Courier extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(CourierZone::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Shipment::class, 'delivery_courier_id');
    }

    public function pickups(): HasMany
    {
        return $this->hasMany(Shipment::class, 'pickup_courier_id');
    }

    /** يوصّل الطلبات. */
    public function scopeDelivering(Builder $q): Builder
    {
        return $q->whereIn('type', ['delivery', 'both']);
    }

    /** يستلم من التجّار. */
    public function scopePicking(Builder $q): Builder
    {
        return $q->whereIn('type', ['pickup', 'both']);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function hasReachedCashLimit(): bool
    {
        return $this->cash_limit > 0 && $this->cash_in_hand >= $this->cash_limit;
    }

    /** ما يجب أن يسلّمه للشركة الآن: النقد الذي بيده ناقص عمولته. */
    public function netDue(): int
    {
        return $this->cash_in_hand - $this->commission_balance;
    }
}

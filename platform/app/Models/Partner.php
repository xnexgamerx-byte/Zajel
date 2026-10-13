<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * شريكٌ تُسلَّم له الشحنات ليوزّعها (docs/plan/64): وسيطٌ له فروعٌ وموظفوه ونظامه.
 * شحنةٌ عنده = partner_id عليها، وتعود إلينا فيُصفَّر.
 */
class Partner extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function keys(): HasMany
    {
        return $this->hasMany(PartnerKey::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

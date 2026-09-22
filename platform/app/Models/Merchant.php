<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** قائمة التسعير الفعّالة: الخاصة بالتاجر، وإلّا افتراضية الشركة. */
    public function effectivePriceList(): ?PriceList
    {
        return $this->priceList
            ?? PriceList::where('is_default', true)->where('is_active', true)->first();
    }
}

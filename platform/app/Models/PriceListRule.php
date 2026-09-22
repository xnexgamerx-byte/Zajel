<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListRule extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'cod_fee_percent' => 'float'];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function toGovernorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class, 'to_governorate_id');
    }

    public function fromGovernorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class, 'from_governorate_id');
    }

    public function toCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'to_city_id');
    }

    /** كلّما كانت القاعدة أكثر تحديداً كانت أولى بالتطبيق. */
    public function specificity(): int
    {
        return ($this->to_city_id ? 4 : 0)
            + ($this->to_governorate_id ? 2 : 0)
            + ($this->from_governorate_id ? 1 : 0);
    }
}

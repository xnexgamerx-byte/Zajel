<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'size_fees' => 'array'];
    }

    /** زيادة أجرة التوصيل لحجمٍ غير العادي في هذه التسعيرة (docs/plan/38) */
    public function sizeFee(?string $size): int
    {
        return $size === null || $size === 'normal' ? 0 : max(0, (int) ($this->size_fees[$size] ?? 0));
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PriceListRule::class);
    }
}

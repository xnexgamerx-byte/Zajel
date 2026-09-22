<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hub extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * مركز الفرع — حيث يُسلَّم راجعُ تجّاره ويُستلَم ما يُرسَل إليه.
     *
     * الرئيسيّ أوّلاً ثم الأقدم، فالنتيجة ثابتة لا تتبدّل بترتيب الإدخال.
     */
    public static function forBranch(?int $branchId): ?self
    {
        if (! $branchId) {
            return null;
        }

        return static::active()
            ->where('branch_id', $branchId)
            ->orderByRaw("type = 'main' desc")
            ->orderBy('id')
            ->first();
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}

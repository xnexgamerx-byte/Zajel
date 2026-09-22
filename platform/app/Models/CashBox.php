<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashBox extends Model
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

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->latest('id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'main'   => 'القاصة الرئيسية',
            'branch' => 'صندوق فرع',
            'petty'  => 'صندوق نثريّة',
            default  => $this->type,
        };
    }

    /**
     * صندوق الفرع، وإلّا القاصة الرئيسية.
     *
     * الشركة ذات الفرع الواحد لا تُجبَر على اختيار صندوق في كل عملية،
     * وذات الفروع تُصيب صندوقها الصحيح بلا إعداد إضافي.
     */
    public static function forBranch(?int $branchId): ?self
    {
        return static::active()
            ->when($branchId, fn (Builder $q) => $q->orderByRaw('case when branch_id = ? then 0 else 1 end', [$branchId]))
            ->orderByRaw("case when type = 'main' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }
}

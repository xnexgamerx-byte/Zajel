<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قرار المنصّة في ميزةٍ لشركة، بمدّته: مفتوحةٌ أو مغلقة، برسمها الشهريّ (docs/plan/35).
 * الساري ends_at فيه فارغ، وما قبله تاريخٌ تُحسب منه الفواتير.
 */
class CompanyFeature extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled'       => 'boolean',
            'monthly_price' => 'integer',
            'starts_at'     => 'datetime',
            'ends_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** null لمفتاحٍ أُزيل من القائمة */
    public function kind(): ?Feature
    {
        return Feature::tryFrom($this->feature);
    }

    /** @param Builder<self> $query */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ends_at');
    }
}

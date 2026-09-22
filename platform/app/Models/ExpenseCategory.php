<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * لا يستخدم BelongsToCompany: الأبواب الافتراضية company_id = null
 * ويجب أن تظهر لكل الشركات إلى جانب أبوابها الخاصة — كأسباب الفشل.
 */
class ExpenseCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeAvailableFor(Builder $q, ?int $companyId): Builder
    {
        return $q->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderBy('sort_order');
    }

    public function groupLabel(): string
    {
        return match ($this->group) {
            'operating' => 'تشغيل',
            'staff'     => 'رواتب وأجور',
            'vehicles'  => 'مركبات',
            'overhead'  => 'إداري',
            default     => $this->group,
        };
    }
}

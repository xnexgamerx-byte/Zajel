<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * لا يستخدم BelongsToCompany: الأسباب الافتراضية company_id = null
 * ويجب أن تظهر لكل الشركات إلى جانب أسبابها الخاصة.
 */
class FailureReason extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requires_note'     => 'boolean',
            'requires_photo'    => 'boolean',
            'counts_as_attempt' => 'boolean',
            'allows_reschedule' => 'boolean',
            'is_active'         => 'boolean',
        ];
    }

    /** الأسباب المتاحة لشركة: الافتراضية + الخاصة بها. */
    public function scopeAvailableFor(Builder $q, ?int $companyId): Builder
    {
        return $q->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderBy('sort_order');
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'customer'    => 'الزبون',
            'address'     => 'العنوان',
            'merchandise' => 'البضاعة',
            'courier'     => 'المندوب',
            'merchant'    => 'التاجر',
            'external'    => 'ظرف خارجي',
            default       => $this->category,
        };
    }
}

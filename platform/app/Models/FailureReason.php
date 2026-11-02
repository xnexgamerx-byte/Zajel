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
            'requires_note' => 'boolean',
            'requires_photo' => 'boolean',
            'counts_as_attempt' => 'boolean',
            'allows_reschedule' => 'boolean',
            'is_active' => 'boolean',
            'from_partner' => 'boolean',
        ];
    }

    /**
     * الأسباب المتاحة لشركة: الافتراضية + الخاصة بها. وأسباب الشركة الوسيطة (نصّ حالتهم، docs/plan/107) لا تُختار
     * من قائمة، فلا تظهر إلّا لمن يقرأها أو يتحقّق منها ($partner).
     */
    public function scopeAvailableFor(Builder $q, ?int $companyId, bool $partner = false): Builder
    {
        return $q->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('company_id')->orWhere('company_id', $companyId))
            ->when(! $partner, fn (Builder $w) => $w->where('from_partner', false))
            ->orderBy('sort_order');
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'customer' => 'الزبون',
            'address' => 'العنوان',
            'merchandise' => 'البضاعة',
            'courier' => 'المندوب',
            'merchant' => 'التاجر',
            'external' => 'ظرف خارجي',
            default => $this->category,
        };
    }
}

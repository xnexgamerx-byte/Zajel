<?php

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rules\Exists;

/**
 * منطقةٌ في محافظة: عامّةٌ يراها الجميع (company_id فارغ)، أو أضافتها شركةٌ
 * لنفسها حين نقصت القائمة (AreaController::store) فتراها وحدها.
 *
 * لا يستخدم BelongsToCompany: ذاك يُخفي العامّة. والحدّ هنا نطاقٌ عامّ يقرأ
 * سياق الشركة؛ وبلا سياق (المنصّة، الترحيل) العامّة وحدها.
 */
class City extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $companyId = app(TenantContext::class)->id();

            $query->where(fn (Builder $where) => $where
                ->whereNull($query->qualifyColumn('company_id'))
                ->when($companyId, fn (Builder $own) => $own->orWhere($query->qualifyColumn('company_id'), $companyId)));
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** «موجودة» في التحقّق: عامّةً أو للشركة الحالية — لا منطقة شركةٍ أخرى برقمها */
    public static function existsRule(): Exists
    {
        $companyId = app(TenantContext::class)->id();

        return (new Exists('cities', 'id'))->where(fn ($query) => $query
            ->whereNull('company_id')
            ->when($companyId, fn ($own) => $own->orWhere('company_id', $companyId)));
    }

    /** أضافتها الشركة لنفسها — تُحذف منها ما لم تحملها شحنة */
    public function isCompanyOwn(): bool
    {
        return $this->company_id !== null;
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function getNameAttribute(): string
    {
        return $this->name_ar;
    }
}

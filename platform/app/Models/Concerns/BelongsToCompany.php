<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * يُطبَّق على كل نموذج يحمل company_id.
 * يفلتر القراءة، ويملأ company_id عند الإنشاء، ويمنع نقل صف بين شركتين.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if (! $model->getAttribute('company_id')) {
                $model->setAttribute('company_id', app(TenantContext::class)->id());
            }
        });

        static::updating(function ($model) {
            // company_id لا يتغيّر بعد الإنشاء — لا سهواً ولا عمداً.
            if ($model->isDirty('company_id')) {
                $model->setAttribute('company_id', $model->getOriginal('company_id'));
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** تجاوز صريح ومقروء في المراجعة — للنواة فقط. */
    public function scopeAcrossCompanies(Builder $query): Builder
    {
        return $query->withoutGlobalScope(CompanyScope::class);
    }
}

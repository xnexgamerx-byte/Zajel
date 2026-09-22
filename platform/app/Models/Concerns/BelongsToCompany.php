<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * يُطبَّق على كل نموذج يحمل company_id.
 * يفلتر القراءة، ويملأ company_id عند الإنشاء، ويمنع نقل صف بين شركتين.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(static::companyScope());

        static::creating(function ($model) {
            $current = app(TenantContext::class)->id();

            /*
            | داخل سياق شركة: هويّتها تُفرَض ولا تُؤخَذ من المُدخَل.
            |
            | كان الملء مشروطاً بخلوّ الحقل، فقيمةٌ مُمرَّرة تُحترم. وكل
            | النماذج $guarded = ['id']، أي أن company_id قابل للإسناد
            | الجَماعيّ: استدعاءٌ واحد بـ $request->all() ينشئ صفّاً في
            | شركة أخرى من داخل شركتك. جُرّب فوقع.
            */
            if ($current !== null) {
                $model->setAttribute('company_id', $current);
            }

            // وضع النواة يترك القيمة كما هي: صفوف النواة نفسها
            // (مدير منصّة مثلاً) تحمل company_id فارغاً عن قصد.
        });

        static::updating(function ($model) {
            // company_id لا يتغيّر بعد الإنشاء — لا سهواً ولا عمداً.
            if ($model->isDirty('company_id')) {
                $model->setAttribute('company_id', $model->getOriginal('company_id'));
            }
        });
    }

    /**
     * النطاق المطبَّق على هذا النموذج.
     *
     * الافتراضي يرمي عند غياب السياق. يُستبدَل في نموذج واحد فقط —
     * User — لسبب موثّق في UserScope.
     */
    protected static function companyScope(): Scope
    {
        return new CompanyScope;
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

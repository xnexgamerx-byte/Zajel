<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * جدول companies ليس فيه company_id — فهو الجذر — فلا يحميه CompanyScope.
 * وبلا حماية، سطرٌ واحد مثل Company::all() في متحكّم شركة يكشف قائمة
 * كل شركات التوصيل المشتركة على المنصّة، أي قائمة منافسيها.
 *
 * ثلاث حالات:
 *   داخل شركة  -> ترى نفسها فقط
 *   وضع النواة -> ترى الجميع
 *   بلا سياق   -> بلا قيد، لأن تحديد الشركة من النطاق يسبق وجود السياق
 */
class CurrentCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isPlatform() || ! $tenant->has()) {
            return;
        }

        $builder->where($model->qualifyColumn('id'), $tenant->id());
    }
}

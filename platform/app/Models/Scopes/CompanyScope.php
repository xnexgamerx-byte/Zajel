<?php

namespace App\Models\Scopes;

use App\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * يُضيف شرط company_id إلى كل استعلام على الجداول المقيّدة — تلقائياً،
 * بلا أن يتذكّره كاتب الشيفرة. ولو لم يوجد سياق، يرمي بدل أن يُرجع كل شيء.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isPlatform()) {
            return;
        }

        if (! $tenant->has()) {
            throw MissingTenantContextException::for($model::class);
        }

        $builder->where($model->qualifyColumn('company_id'), $tenant->id());
    }
}

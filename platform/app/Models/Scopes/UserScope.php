<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * users وحده يُمنَع بلا ضجيج بدل أن يرمي.
 *
 * السبب عملي: حارس المصادقة يستعلم عن المستخدم في مواضع خارج نافذة
 * الوسيط — منها حفظ الجلسة في قاعدة البيانات بعد توليد الاستجابة.
 * فلو طُلب نطاق فرعي مجهول بينما في المتصفّح جلسة مفتوحة، يرمي الحارس
 * أثناء حفظ الجلسة فيتحوّل 404 إلى 500.
 *
 * والدلالة صحيحة لا حيلة: بلا شركة لا يوجد مستخدم. فالنتيجة صفر صفوف
 * — مغلق افتراضياً — لا كل الصفوف. أما بقية الجداول فتبقى ترمي، لأن
 * استعلاماً عليها بلا سياق خطأ برمجي يجب أن يُكتشف لا أن يُبتلع.
 */
class UserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isPlatform()) {
            return;
        }

        if (! $tenant->has()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $tenant->id());
    }
}

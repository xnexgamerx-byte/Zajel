<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Scopes\CurrentCompanyScope;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يحدّد الشركة الحالية من النطاق الفرعي: zajel.zajel.iq -> الزاجل.
 *
 * وفي التطوير المحلي حيث لا نطاقات فرعية، يُقبل ?company=slug مرّة واحدة
 * ثم يُحفَظ في الجلسة — هذا مسار تطوير فقط ولا يعمل في الإنتاج.
 *
 * وبلا نطاقٍ أصلاً (عنوان Railway المجاني): الشركة الافتراضية
 * (zajel.default_company) على كل عنوانٍ ليس نطاقاً فرعياً للنطاق الأساسي.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // يبدأ كل طلب من سياق نظيف. في عامل يعيش طويلاً (Octane، طابور)
        // يبقى سياق الطلب السابق في الحاوية، فيُحدَّد المستأجر الخطأ.
        Tenancy::forget();

        $company = $this->fromSubdomain($request)
            ?? $this->fromSession($request)
            ?? $this->fromDefault($request);

        if (! $company) {
            abort(404, 'لم يُحدَّد النظام المطلوب. تأكّد من العنوان.');
        }

        if (! $company->isOperational()) {
            abort(403, 'اشتراك هذه الشركة موقوف حالياً. راجع إدارة المنصّة.');
        }

        Tenancy::set($company);
        $request->attributes->set('company', $company);

        view()->share('company', $company);

        return $next($request);
    }

    protected function fromSubdomain(Request $request): ?Company
    {
        $host = $request->getHost();
        $base = (string) config('zajel.tenant_domain');

        if ($base === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $slug = substr($host, 0, -(strlen($base) + 1));

        if ($slug === '' || in_array($slug, ['www', 'admin', 'api'], true)) {
            return null;
        }

        return $this->lookup($slug);
    }

    protected function fromSession(Request $request): ?Company
    {
        if (! app()->environment('local', 'testing')) {
            return null;
        }

        if ($slug = $request->query('company')) {
            $request->session()->put('dev_company', $slug);
        }

        $slug = $request->session()->get('dev_company');

        return $slug ? $this->lookup($slug) : null;
    }

    /**
     * الشركة الافتراضية لعنوانٍ ليس من نطاقاتنا الفرعية — عنوان Railway
     * المجاني مثلاً. ونطاقٌ فرعيّ لشركةٍ غير موجودة (barqq.zajel.iq
     * مكتوباً خطأً) لا يقع عليها: يبقى ٤٠٤، لا نظامَ شركةٍ أخرى.
     */
    protected function fromDefault(Request $request): ?Company
    {
        $slug = (string) config('zajel.default_company');
        $base = (string) config('zajel.tenant_domain');

        if ($slug === '' || ($base !== '' && str_ends_with($request->getHost(), '.'.$base))) {
            return null;
        }

        return $this->lookup($slug);
    }

    /**
     * تحديد المستأجر يسبق وجود سياق، فهو الاستثناء الوحيد المسموح
     * لـ CurrentCompanyScope — ولذلك هو صريح ومحصور في هذه الدالة.
     */
    protected function lookup(string $slug): ?Company
    {
        return Company::withoutGlobalScope(CurrentCompanyScope::class)
            ->where('slug', $slug)
            ->first();
    }
}

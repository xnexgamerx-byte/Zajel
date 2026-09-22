<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يحدّد الشركة الحالية من النطاق الفرعي: zajel.zajel.iq -> الزاجل.
 *
 * وفي التطوير المحلي حيث لا نطاقات فرعية، يُقبل ?company=slug مرّة واحدة
 * ثم يُحفَظ في الجلسة — هذا مسار تطوير فقط ولا يعمل في الإنتاج.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->fromSubdomain($request) ?? $this->fromSession($request);

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

        return Company::where('slug', $slug)->first();
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

        return $slug ? Company::where('slug', $slug)->first() : null;
    }
}

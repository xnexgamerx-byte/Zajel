<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * لوحة النواة تعمل بلا شركة: تقرأ عبر كل المستأجرين.
 *
 * هذا هو التجاوز الصريح الوحيد لـ CompanyScope في مسارات الويب،
 * ولذلك هو محصور في هذه المجموعة وحدها ومحروس بـ EnsurePlatformUser.
 */
class IdentifyPlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        Tenancy::usePlatform();

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\MerchantPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * صلاحية موظّف التاجر على المسار (docs/plan/87): merchant.can:finance. صاحب المتجر يمرّ دائماً.
 * بعد EnsureMerchant، فالحساب حساب تاجرٍ قائم.
 */
class EnsureMerchantPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(
            MerchantPermissions::allows($request->user(), $permission),
            403,
            $permission === MerchantPermissions::OWNER ? 'هذا لصاحب المتجر وحده.' : 'لا تملك هذه الصلاحية. اطلبها من صاحب المتجر.',
        );

        return $next($request);
    }
}

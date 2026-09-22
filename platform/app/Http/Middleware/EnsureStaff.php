<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * لوحة العمليات لموظّفي الشركة فقط.
 *
 * حساب التاجر وحساب المندوب موجودان لتطبيقيهما، لا لهذه اللوحة.
 * بلا هذا الحاجز، تاجر واحد بحساب يرى قائمة التجّار وأسعارهم
 * وأرقام مندوبيك — وهذا انكشاف لا يُصلَح بعد وقوعه.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()?->role;

        if (in_array($role, [UserRole::Merchant, UserRole::Courier], true)) {
            abort(403, 'هذه اللوحة لموظّفي الشركة. حسابك يُستخدم من التطبيق.');
        }

        return $next($request);
    }
}

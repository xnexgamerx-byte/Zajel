<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * شاشات الشركة كلّها لا فرعٍ منها: تاريخ الموقف المالي ومطابقة الدفتر وإعلانات
 * التطبيق. لصاحب الشركة ومديرها وموظّفي الفرع الرئيسي؛ وموظّف فرعٍ آخر لا يراها
 * ولو حمل صلاحيتها — ورابطها يغيب من شريطه (StaffNavigation يقرأ هذا الحاجز).
 */
class EnsureMainBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isBranchLimited(), 403, 'هذه الشاشة للشركة كلّها: يراها الفرع الرئيسي.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * الحزام الثاني بعد CompanyScope: لو بقيت جلسة مستخدم من شركة أخرى
 * (تبديل نطاق، جلسة قديمة) تُنهى فوراً بدل أن تُقرأ بيانات ليست له.
 */
class EnsureUserBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->company_id !== Tenancy::id()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['phone' => 'انتهت جلستك. سجّل الدخول من جديد.']);
        }

        if ($user && ! $user->is_active) {
            Auth::logout();

            return redirect()->route('login')
                ->withErrors(['phone' => 'هذا الحساب موقوف.']);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نظير EnsureUserBelongsToTenant لرموز التطبيقات: حسابٌ أُوقف بعد دخوله يُبطَل رمزه
 * ويعود إلى شاشة الدخول. والشركة يحرسها CompanyScope أصلاً — رمز حسابٍ من شركةٍ
 * أخرى لا يجد صاحبه هنا — وهذا حزامٌ ثانٍ صريح.
 */
class EnsureApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->company_id !== Tenancy::id() || ! $user->is_active) {
            $user?->currentAccessToken()?->delete();

            return response()->json(['message' => 'انتهى دخولك. سجّل الدخول من جديد.'], 401);
        }

        return $next($request);
    }
}

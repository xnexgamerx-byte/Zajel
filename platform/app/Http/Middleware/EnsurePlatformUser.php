<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * لا يدخل لوحة النواة إلا من company_id = null ودوره دور منصّة.
 * مستخدم شركة وصل إلى هنا بجلسة قديمة يُرفض، لا يُعاد توجيهه بهدوء.
 */
class EnsurePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isPlatformUser()) {
            abort(403, 'هذه اللوحة لإدارة المنصّة.');
        }

        return $next($request);
    }
}

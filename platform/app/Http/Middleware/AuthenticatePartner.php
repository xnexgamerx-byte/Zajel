<?php

namespace App\Http\Middleware;

use App\Models\CourierApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * مفتاح ربط المندوب الوسيط (docs/plan/64): يُقرأ من «Authorization: Bearer»، ويُتحقّق منه في شركة الطلب.
 *
 * لا جلسة ولا كعكة ولا كلمة مرور: كل طلبٍ يحمل المفتاح كاملاً. وكل محاولة فاشلة أو منتهية
 * ترجع جواباً واحداً بلا تفاصيل تكشف شيئاً عن المفاتيح الأخرى، والمحاولات الفاشلة من عنوانٍ
 * واحد محدودة فلا يُجرَّب عليها.
 */
class AuthenticatePartner
{
    /** الحدّ لكل مفتاح، لا لكل عنوان */
    private const PER_MINUTE = 120;

    /** المحاولات الفاشلة من عنوانٍ واحد في الدقيقة */
    private const FAILURES_PER_MINUTE = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $failures = 'partner-auth-fail:'.$request->ip();
        if (RateLimiter::tooManyAttempts($failures, self::FAILURES_PER_MINUTE)) {
            return response()->json(['message' => 'محاولاتٌ فاشلة كثيرة. انتظر دقيقة.'], 429);
        }

        $token = (string) $request->bearerToken();
        $key = $token !== '' ? CourierApiKey::findByToken($token) : null;

        if ($key === null || ! $key->isUsable()) {
            RateLimiter::hit($failures, 60);

            return response()->json(['message' => 'المفتاح غير صالح أو منتهٍ.'], 401);
        }

        if (! $key->allowsIp($request->ip())) {
            return response()->json(['message' => 'هذا العنوان غير مسموح لهذا المفتاح.'], 403);
        }

        $limiter = 'partner-key:'.$key->id;
        if (RateLimiter::tooManyAttempts($limiter, self::PER_MINUTE)) {
            return response()->json(['message' => 'طلباتٌ كثيرة في الدقيقة. انتظر قليلاً.'], 429);
        }
        RateLimiter::hit($limiter, 60);

        $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();

        $request->attributes->set('partner_key', $key);
        $request->attributes->set('partner_courier', $key->courier);

        return $next($request);
    }
}

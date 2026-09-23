<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * رؤوس الأمان على كل ردّ.
 *
 * الصفحات تحمل أسماء زبائن وهواتفهم ومبالغ نقدٍ في يد مندوبين، فلا
 * تُؤطَّر داخل موقعٍ آخر (نقرٌ مخادع على «سلِّم» أو «ادفع»)، ولا يُخمَّن
 * نوع ملفٍ مرفوع، ولا يخرج رابطُ شحنةٍ في Referer إلى واتساب أو خرائط
 * Google.
 *
 * سياسة المحتوى تحصر ما تحمّله الصفحة في النظام نفسه وخطوط Google:
 * سكربتٌ محقون من موقعٍ آخر لا يُحمَّل، وبيانات لا تُرسَل إلى خارج
 * النطاق بطلبٍ أو بصورة. تسمح بالسكربت المضمَّن لأن في الصفحات مقابضَ
 * onclick صغيرة — والحماية من حقنه أن Blade يهرّب كل مُخرَج (ولا {!!
 * في أي صفحة). نقلُ تلك المقابض إلى app.js يسمح بحذف 'unsafe-inline'.
 */
class SecurityHeaders
{
    /** كل مصدرٍ خارجي تحمّله الصفحات — وحارسه SecurityHeadersTest. */
    public const EXTERNAL = [
        'style-src' => ['https://fonts.googleapis.com'],
        'font-src'  => ['https://fonts.gstatic.com'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        // الكاميرا والموقع للنظام نفسه (مسح الباركود وموقع التسليم)، ولا غير
        $headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');

        // على https وحده: الأوّل على http المحلّي يقفل المتصفّح عليه،
        // والثاني يتجاهله المتصفّح هناك ويكتب خطأً في كل صفحة
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        }

        // خادم Vite في التطوير يخدم من منفذٍ آخر ويحقن سكربته
        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', static::policy());
        }

        return $response;
    }

    public static function policy(): string
    {
        $assets = rtrim((string) config('app.asset_url'), '/');
        $self = trim("'self' {$assets}");

        /*
        | النماذج تُرسَل إلى النظام وحده — وهو نطاقاتٌ فرعية لا نطاقٌ واحد.
        |
        | المتصفّح يطبّق form-action على التحويل الذي يلي الإرسال أيضاً: دخول
        | مدير المنصّة إلى شركةٍ يُرسَل على admin.{النطاق} ويُحوَّل إلى نطاق
        | الشركة، والخروج بالعكس. بـ 'self' وحدها رفضهما Chrome («Refused to
        | send form data») — جُرِّب في المتصفّح، ولا يراه اختبار HTTP.
        */
        $domain = (string) config('zajel.tenant_domain');
        $ours = $domain !== '' ? ["*.{$domain}:*"] : [];

        $directives = [
            'default-src'     => [$self],
            'script-src'      => [$self, "'unsafe-inline'"],
            'style-src'       => [$self, "'unsafe-inline'", ...self::EXTERNAL['style-src']],
            'font-src'        => [$self, ...self::EXTERNAL['font-src']],
            'img-src'         => [$self, 'data:', 'blob:'],
            'connect-src'     => ["'self'"],
            'object-src'      => ["'none'"],
            'base-uri'        => ["'self'"],
            'form-action'     => ["'self'", ...$ours],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', $sources))
            ->implode('; ');
    }
}

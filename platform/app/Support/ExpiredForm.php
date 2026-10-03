<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * نموذجٌ أُرسل بعد أن انتهت الجلسة (docs/plan/29).
 *
 * الهاتف يُبقي الصفحة مفتوحةً ساعات، والجلسة تنتهي بعد ساعتين بلا حركة. كان «حفظ»
 * عندها يضيّع ما كُتب: «انتهت صلاحية الصفحة» وزرُّها الكبير «الصفحة الرئيسية»، أو
 * صفحة الدخول ثم نموذجٌ فارغ (على HTTPS يمرّ رأس Sec-Fetch-Site من فحص الرمز فيصل
 * الطلب إلى الدخول). الآن يعود إلى الصفحة نفسها وما كُتب فيها باقٍ؛ وإن خرج من حسابه،
 * فبعد أن يدخل.
 */
final class ExpiredForm
{
    private const KEY = 'expired_form';

    /** يعود ما كُتب لمن دخل بعده بقليل — صاحبه — لا لمن يدخل من الجهاز نفسه بعد ساعات */
    private const KEEP_SECONDS = 600;

    /** لا يُحفظ ولا يُعاد إلى النموذج: رمز الجلسة وكلمات المرور */
    private const SECRET = ['_token', 'password', 'password_confirmation', 'current_password', 'account_password'];

    /** «انتهت صلاحية الصفحة» (419) لنموذجٍ من صفحاتنا: يعود إليها بما كُتب فيها */
    public static function back(Request $request): ?RedirectResponse
    {
        $from = self::page($request);

        if ($from === null) {
            return null;
        }

        // صفحة الدخول نفسها بقيت مفتوحة: تُعاد بالاسم، ويُضغط «دخول» مرّةً أخرى
        if (self::isLogin($request)) {
            return redirect()->to($from)->withInput(self::input($request))
                ->with('relogin', 'مرّ وقتٌ طويل على صفحة الدخول — اضغط «دخول» مرّةً أخرى.');
        }

        if (self::signedIn($request)) {
            return redirect()->to($from)->withInput(self::input($request))->withErrors([
                'session' => 'انتهت الجلسة والصفحة مفتوحة، فلم يُحفظ شيء. ما كتبته باقٍ — اضغط «حفظ» مرّةً أخرى.',
            ]);
        }

        self::stash($request);
        $request->session()->put('url.intended', $from);

        return redirect()->to($request->is('admin', 'admin/*') ? route('admin.login') : route('login'));
    }

    /**
     * زائرٌ أرسل نموذجاً (جلسته انتهت والصفحة مفتوحة): ما كتبه يُحفظ ليعود إليه بعد
     * الدخول. والعودة نفسها إلى صفحته يكتبها redirect()->guest() من الإحالة.
     */
    public static function stash(Request $request): void
    {
        $from = self::page($request);

        if ($from === null || $request->is('logout', 'admin/logout') || self::isLogin($request)) {
            return;
        }

        $request->session()->put(self::KEY, ['url' => $from, 'input' => self::input($request), 'at' => now()->getTimestamp()]);
        $request->session()->flash('relogin', 'انتهت جلستك والصفحة مفتوحة. ادخل، وتعود إليها بما كتبته فيها.');
    }

    /** بعد الدخول إلى $to: ما كُتب يعود إلى الصفحة إن كانت هي وجهته */
    public static function restore(Request $request, RedirectResponse $to): RedirectResponse
    {
        $saved = $request->session()->pull(self::KEY);

        if (! is_array($saved) || ($saved['url'] ?? null) !== $to->getTargetUrl()
            || now()->getTimestamp() - (int) ($saved['at'] ?? 0) > self::KEEP_SECONDS) {
            return $to;
        }

        return $to->withInput($saved['input'] ?? [])->withErrors([
            'session' => 'ما كتبته قبل انتهاء الجلسة عاد إلى الصفحة ولم يُحفظ بعد — اضغط «حفظ».',
        ]);
    }

    /**
     * الصفحة التي أُرسل منها النموذج، إن كانت من الموقع نفسه. إحالةٌ من موقعٍ آخر
     * لا يُعاد إليها، ولا يُملأ بها نموذج.
     */
    private static function page(Request $request): ?string
    {
        if ($request->isMethod('GET') || $request->expectsJson()) {
            return null;
        }

        $origin = $request->getSchemeAndHttpHost();
        $from = (string) $request->headers->get('referer');

        return $from === $origin || str_starts_with($from, $origin.'/') ? $from : null;
    }

    /** ما كُتب: input() بلا الملفّات — ملفٌّ اختير يُختار من جديد — وبلا الأسرار */
    private static function input(Request $request): array
    {
        return Arr::except($request->input(), self::SECRET);
    }

    private static function isLogin(Request $request): bool
    {
        return $request->is('login', 'admin/login');
    }

    /** ما زال داخلاً: بجلسته، أو بـ«تذكّرني» حين انتهت الجلسة */
    private static function signedIn(Request $request): bool
    {
        try {
            return $request->user() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}

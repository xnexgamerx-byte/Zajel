<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RedirectsWithinArea;
use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * تسجيل الدخول باسم المستخدم — ولحسابٍ بلا اسمٍ مختار رقمُ هاتفه اسماً
 * (User::booted)، فالمندوب والتاجر يدخلان برقمهما إن لم يُختر لهما اسم.
 *
 * الاستعلام عن المستخدم مفلتر أصلاً بـ company_id عبر CompanyScope،
 * فلا يستطيع مستخدم شركة الدخول إلى نظام شركة أخرى باسمه نفسه.
 */
class LoginController extends Controller
{
    use RedirectsWithinArea;

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ], [], ['username' => 'اسم المستخدم', 'password' => 'كلمة المرور']);

        /*
        | الاسم يُوحَّد قبل كل شيء: قبل مفتاح المحاولات وقبل الاستعلام.
        |
        | MySQL (utf8mb4_unicode_ci) ترى «ALI» و«ａｌｉ» و«٠٧٧٠…» اسماً واحداً
        | مع «ali» و«0770…»: لو كان مفتاح المحاولات النصَّ كما كُتب لكان لكل
        | صيغةٍ خمسُ محاولاتٍ جديدة على الحساب نفسه. الآن للحساب مفتاحٌ واحد،
        | والاستعلام لا يرى إلا الصيغة المحفوظة — وما لا يُوحَّد لا يصل القاعدة.
        */
        $username = Username::normalise($data['username']);
        $key = 'login:'.$request->ip().':'.($username ?? '?');

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => 'محاولات كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        $credentials = ['username' => $username, 'password' => $data['password']];

        if ($username === null || ! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'username' => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
            ]);
        }

        // «يُسمح له بالدخول للنظام»: يُقال له عند الباب لا بعد أن يدخل
        $user = Auth::user();
        if ($user->role === UserRole::Merchant
            && ($shop = \App\Models\Merchant::find($user->merchant_id)) && ! $shop->portal_access) {
            Auth::logout();

            throw ValidationException::withMessages([
                'username' => 'لم يُفتح لمتجرك الدخول إلى البوابة. راجع شركة التوصيل.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        Auth::user()->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return $this->intendedWithin($request, platform: false, home: $this->homeFor(Auth::user()));
    }

    /** لكل دور بيته: التاجر بوابته، والموظّف لوحة العمليات. */
    protected function homeFor($user): string
    {
        return match ($user->role) {
            UserRole::Merchant => route('portal.dashboard'),
            UserRole::Courier  => route('courier.tasks'),
            default            => route('dashboard'),
        };
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

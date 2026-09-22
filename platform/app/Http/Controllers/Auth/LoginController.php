<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * تسجيل الدخول بالهاتف — لا بالبريد. هذا عُرف السوق العراقي،
 * وكثير من المندوبين والتجّار بلا بريد إلكتروني أصلاً.
 *
 * الاستعلام عن المستخدم مفلتر أصلاً بـ company_id عبر CompanyScope،
 * فلا يستطيع مستخدم شركة الدخول إلى نظام شركة أخرى برقمه نفسه.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone'    => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ], [], ['phone' => 'رقم الهاتف', 'password' => 'كلمة المرور']);

        $key = 'login:'.$request->ip().':'.$data['phone'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'phone' => 'محاولات كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'phone' => 'رقم الهاتف أو كلمة المرور غير صحيحة.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        Auth::user()->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->intended($this->homeFor(Auth::user()));
    }

    /** لكل دور بيته: التاجر بوابته، والموظّف لوحة العمليات. */
    protected function homeFor($user): string
    {
        return match ($user->role) {
            UserRole::Merchant => route('portal.dashboard'),
            UserRole::Courier  => route('courier.tasks'),
            default            => route('shipments.index'),
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

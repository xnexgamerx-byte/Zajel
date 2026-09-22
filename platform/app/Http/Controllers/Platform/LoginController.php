<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('platform.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone'    => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ], [], ['phone' => 'رقم الهاتف', 'password' => 'كلمة المرور']);

        $key = 'admin-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'phone' => 'محاولات كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        // السياق هنا سياق نواة، فالاستعلام يرى مستخدمي company_id = null
        if (! Auth::attempt($data + ['company_id' => null], $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['phone' => 'بيانات الدخول غير صحيحة.']);
        }

        if (! Auth::user()->isPlatformUser()) {
            Auth::logout();

            throw ValidationException::withMessages(['phone' => 'هذا الحساب ليس حساب منصّة.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        Auth::user()->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}

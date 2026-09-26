<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\RedirectsWithinArea;
use App\Http\Controllers\Controller;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    use RedirectsWithinArea;

    public function show(): View
    {
        return view('platform.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ], [], ['username' => 'اسم المستخدم', 'password' => 'كلمة المرور']);

        $key = 'admin-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => 'محاولات كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        // الاسم يُوحَّد كما في دخول الشركات (Auth\LoginController)، والسياق
        // هنا سياق نواة، فالاستعلام يرى مستخدمي company_id = null
        $username = Username::normalise($data['username']);
        $credentials = ['username' => $username, 'password' => $data['password'], 'company_id' => null];

        if ($username === null || ! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['username' => 'بيانات الدخول غير صحيحة.']);
        }

        if (! Auth::user()->isPlatformUser()) {
            Auth::logout();

            throw ValidationException::withMessages(['username' => 'هذا الحساب ليس حساب منصّة.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        Auth::user()->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return $this->intendedWithin($request, platform: true, home: route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}

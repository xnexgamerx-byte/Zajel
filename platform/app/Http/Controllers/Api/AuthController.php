<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Username;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * دخول التطبيقات (docs/plan/48): اسم المستخدم وكلمة المرور كالموقع، ويعود رمزٌ يحمله
 * التطبيق في كل طلب، ومعه اسم الشركة ولونها وشعارها — فالتطبيق لا يحمل اسم شركةٍ في
 * كوده، والفرق بين الزاجل والبرق عنوانُ نظامها في ملف إعداده فقط (docs/plan/13 §٦).
 *
 * الاستعلام عن المستخدم مفلترٌ بالشركة (CompanyScope) كالموقع: تطبيق الزاجل لا يدخل
 * بحساب البرق ولو تطابق الاسم.
 */
class AuthController extends Controller
{
    /** أيّ الأدوار يدخل كلّ تطبيق */
    private const APPS = [
        'merchant' => [UserRole::Merchant],
        'courier'  => [UserRole::Courier],
    ];

    /** هوية الشركة لشاشة الدخول قبل أن يدخل أحد */
    public function company(Request $request): JsonResponse
    {
        return response()->json(['company' => $this->brand($request->attributes->get('company'))]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
            'app'      => ['required', 'in:'.implode(',', array_keys(self::APPS))],
            'device'   => ['nullable', 'string', 'max:60'],
        ], [], ['username' => 'اسم المستخدم', 'password' => 'كلمة المرور']);

        // مفتاح المحاولات على الصيغة الموحّدة كالموقع (LoginController)
        $username = Username::normalise($data['username']);
        $key = 'login:'.$request->ip().':'.($username ?? '?');

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => 'محاولات كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية.',
            ]);
        }

        $user = $username === null ? null : User::where('username', $username)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['username' => 'اسم المستخدم أو كلمة المرور غير صحيحة.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['username' => 'هذا الحساب موقوف.']);
        }

        if (! in_array($user->role, self::APPS[$data['app']], true)) {
            throw ValidationException::withMessages(['username' => $data['app'] === 'merchant'
                ? 'هذا التطبيق للتجّار. ادخل من تطبيقك أو من الموقع.'
                : 'هذا التطبيق للمندوبين. ادخل من تطبيقك أو من الموقع.']);
        }

        if ($user->role === UserRole::Merchant) {
            $shop = Merchant::find($user->merchant_id);

            if (! $shop || ! $shop->portal_access || $shop->status === 'suspended') {
                throw ValidationException::withMessages([
                    'username' => 'لم يُفتح لمتجرك الدخول إلى البوابة. راجع شركة التوصيل.',
                ]);
            }
        }

        RateLimiter::clear($key);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        $token = $user->createToken(
            $data['app'].':'.($data['device'] ?? 'app'),
            [$data['app']],
            now()->addYear(),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            ...$this->profile($user, $request->attributes->get('company')),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user(), $request->attributes->get('company')));
    }

    /** الخروج يُبطل رمز هذا الجهاز وحده */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    private function profile(User $user, Company $company): array
    {
        $merchant = $user->role === UserRole::Merchant ? Merchant::find($user->merchant_id) : null;

        return [
            'user' => [
                'id'   => $user->id,
                'name' => $merchant ? ($merchant->owner_name ?: $merchant->business_name) : $user->name,
                'role' => $user->role->value,
            ],
            'merchant' => $merchant ? [
                'id'          => $merchant->id,
                'store'       => $merchant->business_name,
                'owner'       => $merchant->owner_name,
                'can_process' => (bool) $merchant->can_process,
            ] : null,
            'company' => $this->brand($company),
        ];
    }

    private function brand(Company $company): array
    {
        return [
            'name'    => $company->name,
            'initial' => $company->initial(),
            'color'   => $company->primary_color,
            'logo'    => $company->logoUrl(),
        ];
    }
}

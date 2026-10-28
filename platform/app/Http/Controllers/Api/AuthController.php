<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use App\Support\MerchantPermissions;
use App\Support\Username;
use App\Support\Push\StaffPush;
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
    public const APPS = [
        'merchant' => [UserRole::Merchant],
        'courier'  => [UserRole::Courier],
        // تطبيق الكول سنتر (docs/plan/73): موظّفو الشركة ممّن يعالجون التذاكر أو الشحنات أو المحادثات
        'callcenter' => [
            UserRole::CompanyOwner, UserRole::CompanyAdmin, UserRole::BranchOwner, UserRole::BranchManager,
            UserRole::Operations, UserRole::CustomerService, UserRole::Accountant,
        ],
    ];

    /** ما يُعمل في تطبيق الكول سنتر: من لا يملك شيئاً منها لا يدخله */
    public const CALL_CENTRE_ABILITIES = ['tickets.handle', 'shipments.status', 'support.reply'];

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
            throw ValidationException::withMessages(['username' => match ($data['app']) {
                'merchant' => 'هذا التطبيق للتجّار. ادخل من تطبيقك أو من الموقع.',
                'courier'  => 'هذا التطبيق للمندوبين. ادخل من تطبيقك أو من الموقع.',
                default    => 'هذا التطبيق لموظّفي الكول سنتر. ادخل من تطبيقك أو من الموقع.',
            }]);
        }

        if ($data['app'] === 'callcenter' && ! collect(self::CALL_CENTRE_ABILITIES)->contains(fn ($a) => $user->can($a))) {
            throw ValidationException::withMessages([
                'username' => 'حسابك لا يعالج التذاكر ولا الشحنات ولا المحادثات. اطلب الصلاحية من مدير الشركة.',
            ]);
        }

        if ($user->role === UserRole::Merchant) {
            $shop = Merchant::find($user->merchant_id);

            if (! $shop || ! $shop->portal_access || $shop->status === 'suspended') {
                throw ValidationException::withMessages([
                    'username' => 'لم يُفتح لمتجرك الدخول إلى البوابة. راجع شركة التوصيل.',
                ]);
            }
            if (! MerchantPermissions::active($user)) {
                throw ValidationException::withMessages(['username' => 'حسابات موظّفي المتاجر غير مفعّلة. راجع صاحب المتجر.']);
            }
        }

        // مندوبٌ موقوف، أو شركةٌ وسيطة تعمل بمفتاح الربط (docs/plan/64): لا يدخل تطبيق المندوب
        if ($user->role === UserRole::Courier) {
            $courier = Courier::find($user->courier_id);

            if (! $courier || $courier->status !== 'active') {
                throw ValidationException::withMessages(['username' => 'حسابك موقوف أو غير مرتبط بمندوب. راجع الشركة.']);
            }
            if ($courier->is_intermediary) {
                throw ValidationException::withMessages(['username' => 'حساب الشركة الوسيطة يعمل بمفتاح الربط، لا بتطبيق المندوب.']);
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
        // موظّف التاجر باسمه هو، وصاحب المتجر باسمه في سجلّ المتجر
        $owner = $merchant && MerchantPermissions::isOwner($user);

        return [
            'user' => [
                'id'   => $user->id,
                'name' => $owner ? ($merchant->owner_name ?: $merchant->business_name) : $user->name,
                'role' => $user->role->value,
            ],
            'merchant' => $merchant ? [
                'id'          => $merchant->id,
                'store'       => $merchant->business_name,
                'owner'       => $merchant->owner_name,
                'can_process' => (bool) $merchant->can_process,
                // ما يفتحه هذا الحساب (docs/plan/87): صاحب المتجر كلّها ومعها owner
                'permissions' => MerchantPermissions::of($user),
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

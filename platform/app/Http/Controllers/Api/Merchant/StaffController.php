<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\User;
use App\Support\MerchantPermissions;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * موظّفو المتجر (docs/plan/87): يضيفهم صاحب المتجر من تطبيقه، لكلٍّ اسمٌ وهاتفٌ يدخل به وكلمة مرور، وما يفتحه
 * له — دورٌ جاهز (إدخال، محاسب، متابعة) أو ما يختاره. والموظّف لا يدير موظّفين.
 */
class StaffController extends Controller
{
    /** حدٌّ لكلّ متجر */
    public const MAX = 20;

    public function index(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        return response()->json([
            'data' => $this->staff($merchant)->map(fn (User $u) => $this->row($u))->values(),
            'permissions' => collect(MerchantPermissions::ALL)
                ->map(fn (array $p, string $key) => ['key' => $key, 'label' => $p[0], 'hint' => $p[1]])->values(),
            'roles' => collect(MerchantPermissions::ROLES)
                ->map(fn (array $r, string $key) => ['key' => $key, 'label' => $r[0], 'permissions' => $r[1]])->values(),
            'max' => self::MAX,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        abort_if($this->staff($merchant)->count() >= self::MAX, 422, 'وصلت حدّ الموظّفين: '.self::MAX.' موظّفاً.');

        $data = $this->validated($request, null);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role' => UserRole::Merchant,
            'merchant_id' => $merchant->id,
            'branch_id' => $merchant->branch_id,
            'is_active' => true,
            'merchant_permissions' => $data['permissions'],
        ]);

        return response()->json(['staff' => $this->row($user)], 201);
    }

    public function update(Request $request, int $staff): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $user = $this->find($merchant, $staff);
        $data = $this->validated($request, $user);

        $user->fill([
            'name' => $data['name'],
            'username' => $data['username'],
            'phone' => $data['phone'],
            'merchant_permissions' => $data['permissions'],
            'is_active' => $data['active'] ?? $user->is_active,
        ]);
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }
        $user->save();

        // موقوفٌ أو بكلمة مرورٍ جديدة: يخرج من أجهزته كلّها
        if (! $user->is_active || filled($data['password'] ?? null)) {
            $user->tokens()->delete();
        }

        return response()->json(['staff' => $this->row($user->refresh())]);
    }

    /** الحذف يُخرجه من أجهزته، ويُحرّر هاتفه واسمه لحسابٍ آخر */
    public function destroy(Request $request, int $staff): JsonResponse
    {
        $user = $this->find($request->attributes->get('merchant'), $staff);

        $user->tokens()->delete();
        $user->forceFill([
            'is_active' => false,
            'phone' => 'x'.$user->id,
            'username' => 'removed-'.$user->id,
        ])->save();
        $user->delete();

        return response()->json(['ok' => true]);
    }

    /** موظّفو المتجر: حساباته بصلاحيات، لا صاحبه */
    private function staff(Merchant $merchant)
    {
        return User::where('merchant_id', $merchant->id)->where('role', UserRole::Merchant->value)
            ->whereNotNull('merchant_permissions')->orderBy('id')->get();
    }

    private function find(Merchant $merchant, int $id): User
    {
        return User::where('merchant_id', $merchant->id)->where('role', UserRole::Merchant->value)
            ->whereNotNull('merchant_permissions')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user): array
    {
        $request->merge([
            'phone' => Phone::normalise($request->input('phone')) ?? $request->input('phone'),
            'username' => filled($request->input('username')) ? Username::canonical($request->input('username')) : null,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'username' => ['nullable', 'string', 'regex:'.Username::PATTERN],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:6', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(array_keys(MerchantPermissions::ALL))],
            'active' => ['sometimes', 'boolean'],
        ], [
            'phone.regex' => 'رقم الهاتف: 07 وتسعة أرقام.',
            'username.regex' => Username::RULE_MESSAGE,
            'permissions.required' => 'اختر صلاحيةً واحدةً على الأقلّ.',
            'permissions.min' => 'اختر صلاحيةً واحدةً على الأقلّ.',
        ], [
            'name' => 'الاسم', 'phone' => 'رقم الهاتف', 'username' => 'اسم المستخدم', 'password' => 'كلمة المرور',
            'permissions' => 'الصلاحيات', 'permissions.*' => 'الصلاحية',
        ]);

        $data['username'] ??= $data['phone'];
        $data['permissions'] = array_values(array_intersect(array_keys(MerchantPermissions::ALL), $data['permissions']));

        // في الشركة كلّها، والمحذوف أيضاً: القاعدة لا تقبل التكرار
        $taken = fn (string $column, string $value) => User::withTrashed()->where($column, $value)
            ->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists();
        if ($taken('phone', $data['phone'])) {
            throw ValidationException::withMessages(['phone' => 'هذا الهاتف لحسابٍ آخر.']);
        }
        if ($taken('username', $data['username'])) {
            throw ValidationException::withMessages(['username' => 'اسم المستخدم هذا مأخوذ. اختر غيره.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        $permissions = MerchantPermissions::of($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'username' => $user->username,
            'permissions' => $permissions,
            'role' => MerchantPermissions::role($permissions),
            'active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Rank;
use App\Models\User;
use App\Support\Permissions\PermissionChange;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * موظّفو الشركة. شركة بحساب واحد لا تعمل: تحتاج عمليات وخدمة عملاء ومحاسباً.
 *
 * حسابات المندوبين والتجّار لا تُدار هنا — لها شاشاتها، لأن لكلٍّ سجلّاً
 * تشغيلياً مرتبطاً به لا مجرّد حساب دخول.
 */
class UserController extends Controller
{
    /** الأدوار التي تُدار من هذه الشاشة. */
    public const ROLES = [
        UserRole::CompanyOwner->value    => 'صاحب الشركة',
        UserRole::CompanyAdmin->value    => 'مدير',
        UserRole::BranchManager->value   => 'مدير فرع',
        UserRole::Operations->value      => 'عمليات',
        UserRole::CustomerService->value => 'خدمة العملاء',
        UserRole::Accountant->value      => 'محاسب',
    ];

    public function index(Request $request): View
    {
        return view('tenant.users.index', [
            'users' => User::query()
                ->whereIn('role', array_keys(self::ROLES))
                ->with(['branch:id,name', 'rank:id,name'])
                ->when($request->query('q'), fn ($q, $term) => $q->where(
                    fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', $term)
                        ->orWhere('username', Username::canonical($term))
                ))
                ->orderBy('name')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
            'roles' => self::ROLES,
        ]);
    }

    public function create(): View
    {
        return view('tenant.users.form', $this->formData() + ['staff' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        User::create($data + ['is_active' => $request->boolean('is_active', true)]);

        return redirect()->route('users.index')->with('success', "أُضيف {$data['name']}.");
    }

    public function edit(Request $request, User $user): View
    {
        $this->guardManageable($user);

        return view('tenant.users.form', $this->formData() + ['staff' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardManageable($user);

        $data = $this->validated($request, $user);

        // لا يُترك النظام بلا صاحب: آخر صاحب مفعّل لا يُنزَّل ولا يُوقَف
        if ($this->wouldRemoveLastOwner($user, $data, $request)) {
            return back()->withErrors([
                'role' => 'هذا آخر حساب صاحب شركة مفعّل — لا يمكن تغيير دوره أو إيقافه.',
            ])->withInput();
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        // مرتبته أو إيقافه قد يُخرج آخر من يدير الصلاحيات
        PermissionChange::apply(fn () => $user->update($data + ['is_active' => $request->boolean('is_active')]), 'rank_id');

        return redirect()->route('users.index')->with('success', 'حُفظت البيانات.');
    }

    protected function validated(Request $request, ?User $user = null): array
    {
        // اسمٌ فارغ: الحالي عند التعديل، ورقم الهاتف عند الإنشاء — ويُتحقَّق من
        // تفرّده كأيّ اسم، فلا يصطدم برقمٍ اختاره موظّفٌ آخر اسماً له
        $typed = Username::canonical($request->input('username'));
        $request->merge([
            'username' => $typed !== '' ? $typed : ($user?->username ?? Phone::normalise($request->input('phone'))),
        ]);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:160'],
            'username'  => ['nullable', 'string', 'regex:'.Username::PATTERN,
                            Rule::unique('users', 'username')
                                ->where('company_id', $request->user()->company_id)
                                ->ignore($user?->id)],
            'phone'     => ['required', 'string', 'regex:/^07[0-9]{9}$/',
                            Rule::unique('users', 'phone')
                                ->where('company_id', $request->user()->company_id)
                                ->ignore($user?->id)],
            'email'     => ['nullable', 'email', 'max:160'],
            'role'      => ['required', Rule::in(array_keys(self::ROLES))],
            'rank_id'   => ['nullable', 'integer', Rule::exists('ranks', 'id')
                                ->where('company_id', $request->user()->company_id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')
                                ->where('company_id', $request->user()->company_id)],
            'password'  => [$user ? 'nullable' : 'required', 'string', 'min:6', 'max:72'],
        ], [
            'username.regex'  => Username::RULE_MESSAGE,
            'username.unique' => 'اسم المستخدم هذا لحسابٍ آخر في شركتك.',
        ], [
            'name' => 'الاسم', 'username' => 'اسم المستخدم', 'phone' => 'الهاتف', 'role' => 'الدور',
            'rank_id' => 'المرتبة', 'branch_id' => 'الفرع', 'password' => 'كلمة المرور',
        ]);

        // صاحب الشركة يملك كل شيء: لا مرتبة تقيّده
        if ($data['role'] === UserRole::CompanyOwner->value) {
            $data['rank_id'] = null;
        }

        return $data;
    }

    /** لا تُدار حسابات المندوبين والتجّار من هنا. */
    protected function guardManageable(User $user): void
    {
        abort_unless(array_key_exists($user->role->value, self::ROLES), 404);
    }

    protected function wouldRemoveLastOwner(User $user, array $data, Request $request): bool
    {
        if ($user->role !== UserRole::CompanyOwner) {
            return false;
        }

        $staysOwner = $data['role'] === UserRole::CompanyOwner->value && $request->boolean('is_active');

        if ($staysOwner) {
            return false;
        }

        return User::where('role', UserRole::CompanyOwner->value)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->doesntExist();
    }

    protected function formData(): array
    {
        return [
            'roles'    => self::ROLES,
            'ranks'    => Rank::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\Rank;
use App\Models\User;
use App\Support\Permissions\PermissionChange;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        UserRole::BranchOwner->value     => 'صاحب فرع',
        UserRole::BranchManager->value   => 'مدير فرع',
        UserRole::Operations->value      => 'عمليات',
        UserRole::CustomerService->value => 'خدمة العملاء',
        UserRole::Accountant->value      => 'محاسب',
    ];

    /**
     * ما يُديره موظّف فرعٍ غير الرئيسي: موظّفو فرعه بأدوارٍ لا تعلو دوره. لا يُنشئ
     * صاحب شركةٍ ولا مديرها ولا صاحب فرعٍ آخر — فيخرج بها من فرعه أو يعلو صاحبه.
     */
    public const BRANCH_ROLES = [
        UserRole::BranchManager->value, UserRole::Operations->value,
        UserRole::CustomerService->value, UserRole::Accountant->value,
    ];

    public function index(Request $request): View
    {
        return view('tenant.users.index', [
            'users' => User::query()
                ->visibleTo($request->user())
                ->whereIn('role', array_keys(self::ROLES))
                ->with(['branch:id,name', 'rank:id,name', 'governorates:id,name_ar'])
                ->when($request->query('q'), fn ($q, $term) => $q->where(
                    fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', $term)
                        ->orWhere('username', Username::canonical($term))
                ))
                ->orderBy('name')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
            'roles' => self::ROLES,
            'uncovered' => $this->uncoveredGovernorates(),
        ]);
    }

    /**
     * محافظاتٌ لم تُحدَّد لأحدٍ حين وُزِّعت المحافظات على الموظّفات: معالجتها يراها من
     * لا محافظات له وحده (وصاحب الشركة) — يُنبَّه إليها كي لا تبقى بلا صاحب.
     *
     * @return Collection<int, string>
     */
    protected function uncoveredGovernorates(): Collection
    {
        $covered = DB::table('user_governorates')
            ->join('users', 'users.id', '=', 'user_governorates.user_id')
            ->where('users.company_id', auth()->user()->company_id)
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->distinct()
            ->pluck('user_governorates.governorate_id');

        return $covered->isEmpty() ? collect()
            : Governorate::query()->offered()->whereNotIn('governorates.id', $covered)->pluck('governorates.name_ar');
    }

    public function create(): View
    {
        return view('tenant.users.form', $this->formData() + ['staff' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $governorates = $this->pullGovernorates($data);

        $user = User::create($data + ['is_active' => $request->boolean('is_active', true), 'is_sales' => $request->boolean('is_sales')]);
        $user->governorates()->sync($governorates);

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

        $governorates = $this->pullGovernorates($data);

        // مرتبته أو إيقافه قد يُخرج آخر من يدير الصلاحيات
        PermissionChange::apply(fn () => $user->update($data + [
            'is_active' => $request->boolean('is_active'),
            'is_sales'  => $request->boolean('is_sales'),
        ]), 'rank_id');

        $user->governorates()->sync($governorates);

        return redirect()->route('users.index')->with('success', 'حُفظت البيانات.');
    }

    protected function validated(Request $request, ?User $user = null): array
    {
        // موظّف الفرع يُضيف لفرعه وحده
        if ($request->user()->isBranchLimited()) {
            $request->merge(['branch_id' => $request->user()->branch_id]);
        }

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
            'role'      => ['required', Rule::in(array_keys($this->roles($request->user())))],
            'rank_id'   => ['nullable', 'integer', Rule::exists('ranks', 'id')
                                ->where('company_id', $request->user()->company_id)],
            // صاحب الفرع بلا فرعٍ يصير صاحب الشركة كلّها: فرعه شرطٌ لا اختيار
            'branch_id' => [Rule::requiredIf($request->input('role') === UserRole::BranchOwner->value),
                            'nullable', 'integer', Rule::exists('branches', 'id')
                                ->where('company_id', $request->user()->company_id)],
            'password'  => [$user ? 'nullable' : 'required', 'string', 'min:6', 'max:72'],
            // محافظات الاختصاص: معالجة شحناتها وتذاكرها ومحادثاتها له وحده (docs/plan/30)
            'governorates'   => ['nullable', 'array'],
            'governorates.*' => ['integer', Rule::exists('governorates', 'id')],
        ], [
            'username.regex'  => Username::RULE_MESSAGE,
            'username.unique' => 'اسم المستخدم هذا لحسابٍ آخر في شركتك.',
            'branch_id.required' => 'اختر فرع صاحب الفرع.',
        ], [
            'name' => 'الاسم', 'username' => 'اسم المستخدم', 'phone' => 'الهاتف', 'role' => 'الدور',
            'rank_id' => 'المرتبة', 'branch_id' => 'الفرع', 'password' => 'كلمة المرور',
            'governorates' => 'المحافظات', 'governorates.*' => 'المحافظة',
        ]);

        // صاحب الشركة وصاحب الفرع يملكان كل شيء: لا مرتبة تقيّدهما
        if (in_array($data['role'], [UserRole::CompanyOwner->value, UserRole::BranchOwner->value], true)) {
            $data['rank_id'] = null;
        }

        return $data;
    }

    /**
     * لا تُدار حسابات المندوبين والتجّار من هنا. وموظّف الفرع لا يفتح حساب صاحب
     * فرعه ولا صاحب الشركة: يُغيّر كلمة مروره فيملكه.
     */
    protected function guardManageable(User $user): void
    {
        abort_unless(array_key_exists($user->role->value, $this->roles(auth()->user())), 404);
    }

    /** @return array<string, string> ما يُسنده هذا الموظّف من أدوار */
    protected function roles(User $actor): array
    {
        return $actor->isBranchLimited()
            ? array_intersect_key(self::ROLES, array_flip(self::BRANCH_ROLES))
            : self::ROLES;
    }

    /**
     * المحافظات من البيانات إلى جدولها. صاحب الشركة وصاحب الفرع يريان كلّ شيء:
     * لا اختصاص يُحفظ لهما فيبقى بعد أن يتغيّر دورهما.
     *
     * @return list<int>
     */
    protected function pullGovernorates(array &$data): array
    {
        $ids = array_values(array_unique(array_map('intval', $data['governorates'] ?? [])));
        unset($data['governorates']);

        return in_array($data['role'], [UserRole::CompanyOwner->value, UserRole::BranchOwner->value], true) ? [] : $ids;
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
        $user = auth()->user();

        return [
            'roles'    => $this->roles($user),
            'ranks'    => Rank::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('is_active', true)
                ->when($user->isBranchLimited(), fn ($q) => $q->whereKey($user->branch_id))
                ->orderBy('name')->get(['id', 'name']),
            'governorates' => Governorate::query()->offered()->get(['governorates.id', 'governorates.name_ar']),
        ];
    }
}

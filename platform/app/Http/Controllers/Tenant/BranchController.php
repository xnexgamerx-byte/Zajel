<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\City;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Hub;
use App\Models\PriceList;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('tenant.branches.index', [
            'branches' => Branch::with(['governorate:id,name_ar', 'priceList:id,name',
                    'users' => fn ($q) => $q->where('role', UserRole::BranchOwner->value)->select('id', 'branch_id', 'username')])
                ->withCount(['users' => fn ($q) => $q->whereNull('deleted_at')])
                ->orderByDesc('is_main')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('tenant.branches.form', $this->formData() + ['branch' => new Branch]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $account = $this->account($request);

        [$branch, $owner] = DB::transaction(function () use ($data, $account, $request) {
            $branch = Branch::create($data + ['is_active' => $request->boolean('is_active', true)]);
            $this->syncMain($branch, $request);
            // ومعه مركز فرزه: إليه تُرسَل الأكياس وفيه يستلم موظّفوه
            Hub::ensureFor($branch);

            return [$branch, $account ? $this->createOwner($branch, $account) : null];
        });

        return redirect()->route('branches.index')->with('success', "أُضيف فرع {$branch->name}."
            .($owner ? " ويدخل صاحبه باسم المستخدم «{$owner->username}»." : ''));
    }

    public function edit(Branch $branch): View
    {
        return view('tenant.branches.form', [
            'branch' => $branch,
            'owners' => $branch->users()->where('role', UserRole::BranchOwner->value)->orderBy('name')->get(['id', 'name', 'username', 'is_active']),
        ] + $this->formData());
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        // الفرع الرئيسي لا يُلغى إلّا بتعيين بديل عنه
        if ($branch->is_main && ! $request->boolean('is_main')) {
            return back()->withErrors([
                'is_main' => 'عيّن فرعاً رئيسياً آخر بدل إلغاء هذا.',
            ])->withInput();
        }

        $data = $this->validated($request, $branch);
        $account = $this->account($request);

        $owner = DB::transaction(function () use ($branch, $data, $account, $request) {
            $branch->update($data + ['is_active' => $request->boolean('is_active')]);
            $this->syncMain($branch, $request);
            Hub::ensureFor($branch);

            return $account ? $this->createOwner($branch, $account) : null;
        });

        $past = $this->applyToPast($branch->refresh(), $request);

        return redirect()->route('branches.index')->with('success', 'حُفظ الفرع.'
            .($owner ? " وأُضيف حساب صاحبه «{$owner->username}»." : '')
            .($past ? ' وحُسبت عمولته على '.\App\Support\Arabic::shipments($past).' وصّلها مناديبه.' : ''));
    }

    /**
     * حساب دخول الفرع — «صاحب الفرع»: اسم مستخدمٍ وكلمة مرورٍ يضعهما صاحب الشركة،
     * فيعمل الفرع بنظامه كلّه في فرعه وحده (UserRole::BranchOwner).
     *
     * @return array{name: ?string, phone: string, username: string, password: string}|null
     */
    protected function account(Request $request): ?array
    {
        if (blank($request->input('account_password'))) {
            return null;
        }

        // اسمٌ فارغ: رقم الهاتف، كأيّ حساب — ويُتحقَّق من تفرّده كأيّ اسم
        $typed = Username::canonical($request->input('account_username'));
        $request->merge(['account_username' => $typed !== '' ? $typed : Phone::normalise($request->input('account_phone'))]);

        $company = $request->user()->company_id;

        $data = $request->validate([
            'account_name'     => ['nullable', 'string', 'max:160'],
            'account_phone'    => ['required', 'string', 'regex:/^07[0-9]{9}$/',
                                   Rule::unique('users', 'phone')->where('company_id', $company)],
            'account_username' => ['required', 'string', 'regex:'.Username::PATTERN,
                                   Rule::unique('users', 'username')->where('company_id', $company)],
            'account_password' => ['required', 'string', 'min:6', 'max:72'],
        ], [
            'account_username.regex'  => Username::RULE_MESSAGE,
            'account_username.unique' => 'اسم المستخدم هذا لحسابٍ آخر في شركتك.',
            'account_phone.unique'    => 'لهذا الرقم حسابٌ في شركتك.',
        ], [
            'account_name' => 'اسم صاحب الفرع', 'account_phone' => 'هاتفه',
            'account_username' => 'اسم المستخدم', 'account_password' => 'كلمة المرور',
        ]);

        return [
            'name'     => $data['account_name'] ?? null,
            'phone'    => $data['account_phone'],
            'username' => $data['account_username'],
            'password' => $data['account_password'],
        ];
    }

    protected function createOwner(Branch $branch, array $account): User
    {
        return User::create([
            'name'      => $account['name'] ?: 'صاحب '.$branch->name,
            'phone'     => $account['phone'],
            'username'  => $account['username'],
            'password'  => $account['password'],
            'role'      => UserRole::BranchOwner,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    /** فرع رئيسي واحد لا أكثر. */
    protected function syncMain(Branch $branch, Request $request): void
    {
        if (! $request->boolean('is_main')) {
            return;
        }

        Branch::whereKeyNot($branch->id)->update(['is_main' => false]);
        $branch->forceFill(['is_main' => true])->save();
    }

    protected function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'code'           => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/',
                                 Rule::unique('branches', 'code')
                                     ->where('company_id', $request->user()->company_id)
                                     ->ignore($branch?->id)],
            'name'           => ['required', 'string', 'max:160'],
            'governorate_id' => ['nullable', 'integer', Rule::exists('governorates', 'id')],
            // منطقةٌ موجودة يراها (لا رقمٌ يسقط به الحفظ على مفتاح القاعدة)
            'city_id'        => ['nullable', 'integer', City::existsRule()],
            'address'        => ['nullable', 'string', 'max:255'],
            'phone'          => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
            // تسعيرة الفرع يختارها الفرع الرئيسي وتسري على تجّاره؛ فارغةً: افتراضية الشركة
            'price_list_id'  => ['nullable', 'integer', Rule::exists('price_lists', 'id')
                                     ->where('company_id', $request->user()->company_id)],
            // عمولة الفرع عن كلّ طلبٍ يوصّله مناديبه (docs/plan/51)؛ فارغةً: لا عمولة
            'commission_per_delivery' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'apply_from'     => ['nullable', 'date', 'before_or_equal:today'],
        ], [], ['apply_from' => 'تاريخ البداية', 'code' => 'الرمز', 'name' => 'الاسم', 'phone' => 'الهاتف', 'price_list_id' => 'تسعيرة الفرع',
            'commission_per_delivery' => 'عمولة الفرع']);

        $data['commission_per_delivery'] = (int) ($data['commission_per_delivery'] ?? 0);
        unset($data['apply_from']);

        return $data;
    }

    /**
     * «احسبها على ما وصّله منذ…»: العمولة تُجمَّد على الشحنة عند تسليمها، فما سُلِّم قبل
     * كتابتها بلا عمولة. يختار صاحب الشركة تاريخاً فتُكتب على ما وصّله مناديب الفرع منذه.
     *
     * @return int عدد الشحنات
     */
    protected function applyToPast(Branch $branch, Request $request): int
    {
        // تحقّق منه validated() قبل الحفظ
        $from = $request->input('apply_from');

        if (! $from) {
            return 0;
        }

        return Shipment::query()
            ->whereNotNull('delivered_at')
            ->whereFromDate('delivered_at', $from)
            ->whereIn('delivery_courier_id', Courier::withTrashed()->where('branch_id', $branch->id)->select('id'))
            ->update([
                'delivery_branch_id' => $branch->id,
                'branch_commission'  => $branch->is_main ? 0 : (int) $branch->commission_per_delivery,
            ]);
    }

    protected function formData(): array
    {
        return [
            'governorates' => Governorate::where('is_active', true)->orderedForCompany()->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
            'priceLists'   => PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'owners'       => collect(),
        ];
    }
}

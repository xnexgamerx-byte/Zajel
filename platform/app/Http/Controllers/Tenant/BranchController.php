<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\City;
use App\Models\Governorate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('tenant.branches.index', [
            'branches' => Branch::with('governorate:id,name_ar')
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
        $branch = Branch::create($this->validated($request) + ['is_active' => $request->boolean('is_active', true)]);

        $this->syncMain($branch, $request);

        return redirect()->route('branches.index')->with('success', "أُضيف فرع {$branch->name}.");
    }

    public function edit(Branch $branch): View
    {
        return view('tenant.branches.form', $this->formData() + ['branch' => $branch]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        // الفرع الرئيسي لا يُلغى إلّا بتعيين بديل عنه
        if ($branch->is_main && ! $request->boolean('is_main')) {
            return back()->withErrors([
                'is_main' => 'عيّن فرعاً رئيسياً آخر بدل إلغاء هذا.',
            ])->withInput();
        }

        $branch->update($this->validated($request, $branch) + ['is_active' => $request->boolean('is_active')]);

        $this->syncMain($branch, $request);

        return redirect()->route('branches.index')->with('success', 'حُفظ الفرع.');
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
            'city_id'        => ['nullable', 'integer'],
            'address'        => ['nullable', 'string', 'max:255'],
            'phone'          => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
        ], [], ['code' => 'الرمز', 'name' => 'الاسم', 'phone' => 'الهاتف']);
    }

    protected function formData(): array
    {
        return [
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
        ];
    }
}

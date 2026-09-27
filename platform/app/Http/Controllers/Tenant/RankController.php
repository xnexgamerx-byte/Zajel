<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Rank;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Permissions\PermissionChange;
use App\Support\Permissions\RankTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * المراتب: «محاسب رئيسي»، «موظّف رواجع»… بما تفتحه كلٌّ منها، قائمةً قائمة.
 *
 * كما في «إعدادات صلاحيات المستخدم» في المعتاد: المرتبة × القائمة ← الشاشات.
 * وتعديل المرتبة يسري على كل من يحملها فوراً — لذلك يمرّ بـ PermissionChange:
 * مرتبةٌ يحملها آخر من يدير الصلاحيات لا تُنزَع منها هذه.
 */
class RankController extends Controller
{
    public function index(): View
    {
        $ranks = Rank::withCount('users')->orderBy('name')->get();

        // من لا مرتبة له ولا تخصيص يتبع افتراضي دوره
        $byRole = User::query()
            ->whereIn('role', array_keys(UserController::ROLES))
            ->whereNull('rank_id')->whereNull('permissions')
            ->where('role', '!=', UserRole::CompanyOwner->value)
            ->toBase()
            ->selectRaw('role, count(*) as total')->groupBy('role')
            ->pluck('total', 'role');

        return view('tenant.permissions.ranks.index', [
            'ranks'     => $ranks,
            'roles'     => collect(UserController::ROLES)->except(UserRole::CompanyOwner->value),
            'byRole'    => $byRole,
            'groups'    => Ability::groups(),
            'templates' => RankTemplates::all(),
        ]);
    }

    public function create(Request $request): View
    {
        $template = RankTemplates::find($request->query('template'));

        return view('tenant.permissions.ranks.form', $this->formData() + [
            'rank'     => new Rank([
                'name'      => $template['name'] ?? '',
                'abilities' => $template['abilities'] ?? [],
                'template'  => $template ? $request->query('template') : null,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $rank = Rank::create($data + ['created_by_user_id' => $request->user()->id]);

        PermissionChange::audit('rank_created', $rank, null, ['name' => $rank->name, 'abilities' => $rank->abilities]);

        return redirect()->route('permissions.ranks.index')
            ->with('success', "أُنشئت مرتبة «{$rank->name}». أسنِدها للموظّفين من «الصلاحيات والمراتب» أو من صفحة المستخدم.");
    }

    public function edit(Rank $rank): View
    {
        return view('tenant.permissions.ranks.form', [
            'rank'    => $rank,
            'holders' => $rank->users()->orderBy('name')->get(['id', 'name', 'is_active']),
        ] + $this->formData());
    }

    public function update(Request $request, Rank $rank): RedirectResponse
    {
        $data = $this->validated($request, $rank);
        $old = ['name' => $rank->name, 'abilities' => $rank->abilityList()];

        PermissionChange::apply(function () use ($rank, $data, $old) {
            $rank->update($data);
            PermissionChange::audit('rank_updated', $rank, $old, ['name' => $rank->name, 'abilities' => $rank->abilities]);
        });

        return redirect()->route('permissions.ranks.index')->with('success', "حُفظت مرتبة «{$rank->name}».");
    }

    /** مرتبةٌ يحملها أحد لا تُحذف: ينتقل حاملوها أولاً، فلا يفقد أحدٌ صلاحياته سهواً. */
    public function destroy(Rank $rank): RedirectResponse
    {
        $holders = $rank->users()->count();

        if ($holders > 0) {
            return back()->withErrors([
                'rank' => "«{$rank->name}» يحملها ".\App\Support\Arabic::count($holders, ['موظّفٌ واحد', 'موظّفان', 'موظّفين', 'موظّفاً'])
                    .' — انقلهم إلى مرتبةٍ أخرى أولاً.',
            ]);
        }

        PermissionChange::audit('rank_deleted', $rank, ['name' => $rank->name, 'abilities' => $rank->abilityList()], null);
        $rank->delete();

        return redirect()->route('permissions.ranks.index')->with('success', "حُذفت مرتبة «{$rank->name}».");
    }

    protected function validated(Request $request, ?Rank $rank = null): array
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:80',
                              Rule::unique('ranks', 'name')->where('company_id', $request->user()->company_id)->ignore($rank?->id)],
            'abilities'   => ['nullable', 'array'],
            'abilities.*' => ['string', Rule::in(Ability::all())],
            'template'    => ['nullable', 'string', Rule::in(array_keys(RankTemplates::all()))],
        ], [
            'name.unique' => 'مرتبةٌ بهذا الاسم موجودة.',
        ], ['name' => 'اسم المرتبة', 'abilities' => 'الصلاحيات']);

        $fields = [
            'name'      => trim($data['name']),
            'abilities' => Ability::ordered($data['abilities'] ?? []),
        ];

        // القالب يُحفظ عند الإنشاء وحده: هو من أين بدأت، لا ما صارت إليه
        if (! $rank) {
            $fields['template'] = $data['template'] ?? null;
        }

        return $fields;
    }

    protected function formData(): array
    {
        return [
            'groups'    => Ability::groups(),
            'screens'   => Ability::screens(),
            'templates' => RankTemplates::all(),
            'holders'   => collect(),
        ];
    }
}

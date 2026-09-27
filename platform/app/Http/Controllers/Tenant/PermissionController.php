<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Rank;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Permissions\PermissionChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * الصلاحيات: من يحمل أيّ مرتبة، وما يستطيعه فعلاً.
 *
 * الشاشة تعرض ما يستطيعه كل مستخدم فعلاً — لا ما يُفترض بدوره. والفرق
 * بينهما هو ما يجعل صاحب الشركة يكتشف أن موظّف الاستقبال يستطيع دفع
 * كشوف التجّار.
 *
 * والتخصيص صار كما في المعتاد: مرتبةٌ تُسمّى وتُسند (RankController)،
 * واستثنائيةٌ لموظّفٍ بعينه فوقها (UserGrantController). وما خُصِّص لموظّفٍ
 * قبل المراتب يبقى يعمل حتى يُحفظ مرتبةً أو يُزال.
 */
class PermissionController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->whereNotIn('role', [UserRole::Merchant, UserRole::Courier])
            ->with(['rank', 'grants', 'branch:id,name'])
            ->orderBy('role')->orderBy('name')
            ->get();

        return view('tenant.permissions.index', [
            'users'  => $users,
            'ranks'  => Rank::orderBy('name')->get(['id', 'name']),
            'total'  => count(Ability::all()),
            'groups' => Ability::groups(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if (! $user->isStaff()) {
            return back()->withErrors(['abilities' => 'حسابات التجّار والمندوبين تُدار من أدوارها لا من هنا.']);
        }

        $data = $request->validate([
            'mode'    => ['required', 'in:rank,reset,save_as_rank'],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('company_id', $user->company_id)],
            'name'    => ['nullable', 'required_if:mode,save_as_rank', 'string', 'max:80',
                          Rule::unique('ranks', 'name')->where('company_id', $user->company_id)],
        ], [
            'name.required_if' => 'سمِّ المرتبة.',
            'name.unique'      => 'مرتبةٌ بهذا الاسم موجودة.',
        ], ['rank_id' => 'المرتبة', 'name' => 'اسم المرتبة']);

        return match ($data['mode']) {
            'rank'         => $this->assignRank($user, $data['rank_id'] ?? null),
            'reset'        => $this->reset($user),
            'save_as_rank' => $this->saveAsRank($user, $data['name']),
        };
    }

    /** المرتبة تحلّ محلّ افتراضي الدور؛ والفارغ يعيده إليه. */
    protected function assignRank(User $user, ?int $rankId): RedirectResponse
    {
        if ($user->role === UserRole::CompanyOwner) {
            return back()->withErrors(['rank_id' => 'صاحب الشركة يملك كل شيء: لا مرتبة تقيّده.']);
        }

        $before = $user->rank?->name;

        if ((int) $user->rank_id === (int) $rankId) {
            return back()->with('success', 'لم يتغيّر شيء.');
        }

        PermissionChange::apply(function () use ($user, $rankId, $before) {
            $user->forceFill(['rank_id' => $rankId])->save();
            $user->unsetRelation('rank');

            PermissionChange::audit('user_rank_changed', $user,
                ['rank' => $before], ['rank' => $user->rank?->name]);
        }, 'rank_id');

        return back()->with('success', $rankId
            ? "صار {$user->name} «{$user->rank->name}»."
            : "عاد {$user->name} إلى افتراضي «{$user->role->label()}».");
    }

    /** يُزيل التخصيص القديم، فيعود إلى مرتبته أو افتراضي دوره. */
    protected function reset(User $user): RedirectResponse
    {
        $old = $user->permissions;

        PermissionChange::apply(function () use ($user, $old) {
            $user->forceFill(['permissions' => null])->save();
            PermissionChange::audit('permissions_reset', $user, ['abilities' => $old], null);
        });

        return back()->with('success', "عاد {$user->name} إلى {$user->refresh()->abilitySource()}.");
    }

    /**
     * التخصيص القديم مرتبةً باسم: تبقى صلاحياته كما هي، وتصير قابلةً للإسناد
     * لغيره وللتعديل في مكانٍ واحد.
     */
    protected function saveAsRank(User $user, string $name): RedirectResponse
    {
        if (! $user->hasCustomPermissions()) {
            return back()->withErrors(['name' => 'ليس له تخصيصٌ يُحفظ — أسنِد له مرتبةً مباشرة.']);
        }

        if ($user->role === UserRole::CompanyOwner) {
            return back()->withErrors(['name' => 'صاحب الشركة لا يحمل مرتبة. أزل تخصيصه فيعود إليه كل شيء.']);
        }

        PermissionChange::apply(function () use ($user, $name) {
            $rank = Rank::create([
                'name'               => $name,
                'abilities'          => Ability::ordered($user->permissions),
                'created_by_user_id' => request()->user()->id,
            ]);

            $user->forceFill(['rank_id' => $rank->id, 'permissions' => null])->save();

            PermissionChange::audit('rank_created', $rank, null, ['name' => $rank->name, 'abilities' => $rank->abilities]);
            PermissionChange::audit('user_rank_changed', $user, ['rank' => null], ['rank' => $rank->name]);
        }, 'name');

        return back()->with('success', "حُفظت صلاحيات {$user->name} مرتبةً باسم «{$name}».");
    }
}

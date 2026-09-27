<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserGrant;
use App\Support\Permissions\Ability;
use App\Support\Permissions\PermissionChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * صلاحيات استثنائية: لموظّفٍ بعينه فوق مرتبته.
 *
 * كـ«صلاحية تعديل وحذف الشحنات» في المعتاد: موظّف إدخالٍ واحد يصحّح
 * الشحنات، لا مرتبته كلّها. وتُرى بمن منحها ومتى، وتُسحب بزرّ.
 */
class UserGrantController extends Controller
{
    public function index(Request $request): View
    {
        $grants = UserGrant::query()
            ->with('user.rank')
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('ability'), fn ($q, $ability) => $q->where('ability', $ability))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.permissions.grants.index', [
            'grants' => $grants,
            // صاحب الشركة يملك كل شيء: لا استثناء فوق الكلّ
            'users'  => User::query()
                ->whereIn('role', array_keys(UserController::ROLES))
                ->where('role', '!=', UserRole::CompanyOwner->value)
                ->with('rank:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'role', 'rank_id', 'is_active']),
            'groups' => Ability::groups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')
                ->where('company_id', $request->user()->company_id)
                ->whereNull('deleted_at')
                ->whereIn('role', array_keys(UserController::ROLES))
                ->whereNot('role', UserRole::CompanyOwner->value)],
            'ability' => ['required', 'string', Rule::in(Ability::all())],
            'note'    => ['nullable', 'string', 'max:255'],
        ], [
            'user_id.exists' => 'اختر موظّفاً من موظّفي الشركة — صاحبها يملك كل شيء أصلاً.',
        ], ['user_id' => 'الموظّف', 'ability' => 'الصلاحية', 'note' => 'السبب']);

        $user = User::with(['rank', 'grants'])->findOrFail($data['user_id']);
        $label = Ability::label($data['ability']);

        if ($user->hasAbility($data['ability'])) {
            $from = $user->grants->contains('ability', $data['ability']) ? 'استثناءً سابقاً' : 'من '.$user->abilitySource();

            return back()->withInput()->withErrors(['ability' => "{$user->name} يملك «{$label}» {$from}."]);
        }

        $grant = UserGrant::create([
            'user_id'            => $user->id,
            'ability'            => $data['ability'],
            'note'               => $data['note'] ?? null,
            'granted_by_user_id' => $request->user()->id,
            'granted_by_name'    => $request->user()->name,
        ]);

        PermissionChange::audit('permission_granted', $user, null, ['ability' => $grant->ability, 'note' => $grant->note]);

        return back()->with('success', "مُنح {$user->name} «{$label}» فوق مرتبته.");
    }

    public function destroy(UserGrant $grant): RedirectResponse
    {
        $user = $grant->user;
        $label = Ability::label($grant->ability);

        PermissionChange::apply(function () use ($grant, $user) {
            PermissionChange::audit('permission_revoked', $user, ['ability' => $grant->ability, 'note' => $grant->note], null);
            $grant->delete();
        });

        return back()->with('success', "سُحبت «{$label}» من {$user?->name}.");
    }
}

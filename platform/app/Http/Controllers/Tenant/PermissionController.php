<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions\Ability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الصلاحيات.
 *
 * الشاشة تعرض ما يستطيعه كل مستخدم فعلاً — لا ما يُفترض بدوره. والفرق
 * بينهما هو ما يجعل صاحب الشركة يكتشف أن موظّف الاستقبال يستطيع دفع
 * كشوف التجّار.
 */
class PermissionController extends Controller
{
    public function index(): View
    {
        return view('tenant.permissions.index', [
            'users'  => User::query()
                ->whereNotIn('role', [UserRole::Merchant, UserRole::Courier])
                ->orderBy('role')->orderBy('name')
                ->get(),
            'groups' => Ability::groups(),
            'roles'  => collect(UserRole::cases())
                ->reject(fn (UserRole $r) => $r->isPlatform()
                    || in_array($r, [UserRole::Merchant, UserRole::Courier], true)),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if (in_array($user->role, [UserRole::Merchant, UserRole::Courier], true)) {
            return back()->withErrors(['abilities' => 'حسابات التجّار والمندوبين تُدار من أدوارها لا من هنا.']);
        }

        $data = $request->validate([
            'mode'        => ['required', 'in:role,custom'],
            'abilities'   => ['nullable', 'array'],
            'abilities.*' => ['string'],
        ], [], ['abilities' => 'الصلاحيات']);

        // العودة إلى الافتراضي تعني محو التجاوز لا نسخ القائمة الحالية
        if ($data['mode'] === 'role') {
            $user->forceFill(['permissions' => null])->save();

            return back()->with('success', "عادت صلاحيات {$user->name} إلى افتراضي دوره.");
        }

        $chosen = array_values(array_intersect($data['abilities'] ?? [], Ability::all()));

        /*
        | آخر من يملك «الصلاحيات» لا يسحبها من نفسه: الشركة تُقفَل عن
        | إدارتها بلا طريق للعودة إلّا من قاعدة البيانات.
        */
        if (! in_array(Ability::SETTINGS_PERMISSIONS, $chosen, true)
            && ! $this->someoneElseKeepsPermissions($user)) {
            return back()->withErrors([
                'abilities' => 'لا أحد غيره يملك إدارة الصلاحيات. امنحها لمستخدم آخر أولاً.',
            ]);
        }

        $user->forceFill(['permissions' => $chosen])->save();

        return back()->with('success', "حُدّثت صلاحيات {$user->name}.");
    }

    protected function someoneElseKeepsPermissions(User $except): bool
    {
        return User::query()
            ->whereKeyNot($except->id)
            ->where('is_active', true)
            ->whereNotIn('role', [UserRole::Merchant, UserRole::Courier])
            ->get()
            ->contains(fn (User $user) => $user->hasAbility(Ability::SETTINGS_PERMISSIONS));
    }
}

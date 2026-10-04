<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Rank;
use App\Models\User;
use App\Support\HomeLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «خصّص الرئيسية»: كل موظّفٍ يختار ما يظهر له في «لوحة اليوم» — اختصاراتٍ إلى شاشاته،
 * والأقسام، وقوائم التنبيهات. ومن يدير المراتب يضع لكل مرتبةٍ رئيسيّتها الافتراضية،
 * فيراها أصحابها ما لم يخصّصوا.
 */
class HomeLayoutController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();
        $rank = $this->rankTarget($request);

        return view('tenant.home-layout', [
            'rank'      => $rank,
            'ranks'     => $this->managesRanks($user) ? Rank::orderBy('name')->get(['id', 'name', 'home_layout']) : collect(),
            'layout'    => HomeLayout::stored($rank ?? $user),
            'shortcuts' => collect(HomeLayout::shortcuts($rank ?? $user))->groupBy('group'),
            // من أين لوحته اليوم: تخصيصه، أو مرتبته، أو الافتراضيّ
            'source'    => $rank ? (is_array($rank->home_layout) ? 'rank' : 'default') : HomeLayout::for($user)['source'],
            'userRank'  => $user->rank,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $rank = $this->rankTarget($request);
        $owner = $rank ?? $user;

        // «رجوع للافتراضي»: يُمحى التخصيص فتعود لوحة المرتبة، أو اللوحة كاملةً
        if ($request->boolean('reset')) {
            $owner->forceFill(['home_layout' => null])->save();

            return redirect()->route('home.customize', array_filter(['rank' => $rank?->id]))
                ->with('success', $rank ? "عادت رئيسية مرتبة «{$rank->name}» إلى الافتراضي." : 'عادت رئيسيتك إلى الافتراضي.');
        }

        $data = $request->validate([
            'sections'    => ['nullable', 'array'],
            'sections.*'  => ['string', Rule::in(array_keys(HomeLayout::SECTIONS))],
            'alerts'      => ['nullable', 'array'],
            'alerts.*'    => ['string', Rule::in(array_keys(HomeLayout::ALERTS))],
            'shortcuts'   => ['nullable', 'array', 'max:24'],
            'shortcuts.*' => ['string', 'max:120'],
        ], ['shortcuts.max' => 'أربعة وعشرون اختصاراً على الأكثر.']);

        $owner->forceFill(['home_layout' => HomeLayout::normalise([
            'sections'  => $data['sections'] ?? [],
            'alerts'    => $data['alerts'] ?? [],
            'shortcuts' => $data['shortcuts'] ?? [],
        ])])->save();

        return $rank
            ? redirect()->route('home.customize', ['rank' => $rank->id])
                ->with('success', "حُفظت رئيسية مرتبة «{$rank->name}»: يراها أصحابها ما لم يخصّصوا رئيسيّتهم.")
            : redirect()->route('dashboard')->with('success', 'حُفظت رئيسيتك.');
    }

    /** مرتبةٌ تُخصَّص رئيسيّتها: لمن يدير المراتب وحده */
    protected function rankTarget(Request $request): ?Rank
    {
        if (! $request->filled('rank')) {
            return null;
        }

        abort_unless($this->managesRanks($request->user()), 403);

        return Rank::findOrFail($request->integer('rank'));
    }

    protected function managesRanks(User $user): bool
    {
        return $user->can('settings.permissions') && ! $user->isBranchLimited();
    }
}

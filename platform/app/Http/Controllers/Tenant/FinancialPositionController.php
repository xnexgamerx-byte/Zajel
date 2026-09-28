<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FinancialSnapshot;
use App\Services\Money\FinancialPosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «الموقف المالي» و«تاريخ لقطات الموقف المالي»: الأرقام الآن، و«حفظ نسخة»
 * منها، واللقطات كما كانت — والجدول الليليّ يلتقط واحدةً كل يوم.
 */
class FinancialPositionController extends Controller
{
    public function index(Request $request, FinancialPosition $position): View
    {
        return view('tenant.money.position', [
            // موظّف الفرع يرى موقف فرعه، واللقطات المحفوظة للشركة كلّها (main-branch)
            'position' => $position->now($request->user()),
            'last'     => $request->user()->isBranchLimited() ? null : FinancialSnapshot::latest('taken_at')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $snapshot = FinancialSnapshot::take($request->user());

        return redirect()->route('money.position.history')
            ->with('success', 'حُفظت نسخة الموقف المالي الساعة '.$snapshot->taken_at->format('H:i').'.');
    }

    public function history(): View
    {
        return view('tenant.money.history', [
            'snapshots' => FinancialSnapshot::with('takenBy:id,name')->latest('taken_at')
                ->paginate(config('zajel.per_page'))->withQueryString(),
        ]);
    }
}

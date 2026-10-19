<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FinancialAlert;
use App\Services\Finance\FinancialAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ما يفعله الموظّف بتنبيهٍ مالي (docs/plan/68 §١٢): يتولّاه، أو يعالجه بملاحظة، أو يتجاهله بسببٍ
 * مكتوب. وكلٌّ في سجلّ التنبيه وسجلّ التدقيق. و«افحص الآن» لا ينتظر موعد الساعة.
 */
class FinancialAlertController extends Controller
{
    public function __construct(protected FinancialAlerts $alerts) {}

    public function scan(): RedirectResponse
    {
        $done = $this->alerts->scan();

        return back()->with('success', "فُحصت الحسابات: فُتح {$done['opened']}، وزال سبب {$done['cleared']}.");
    }

    public function take(Request $request, FinancialAlert $alert): RedirectResponse
    {
        $this->alerts->take($alert, $request->user());

        return back()->with('success', 'تولّيت التنبيه: '.$alert->title);
    }

    public function resolve(Request $request, FinancialAlert $alert): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']], [], ['note' => 'ما فُعل']);
        $this->alerts->resolve($alert, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'عولج التنبيه. إن بقي سببه أُعيد فتحه في الفحص التالي.');
    }

    public function ignore(Request $request, FinancialAlert $alert): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'سبب التجاهل']);
        $this->alerts->ignore($alert, $request->user(), $data['reason']);

        return back()->with('success', 'تُجوهل التنبيه بسببه، ولا يُفتح ثانيةً إلّا إن زال سببه ثم عاد.');
    }
}

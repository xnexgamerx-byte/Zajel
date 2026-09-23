<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ImpersonateCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /** على نطاق المنصّة: يكتب التذكرة ويحوّل إلى نطاق الشركة. */
    public function start(Request $request, Company $company, ImpersonateCompany $action): RedirectResponse
    {
        return redirect()->away($action->start($request, $company));
    }

    /** على نطاق الشركة: يصرف التذكرة ويفتح جلسة الشركة. */
    public function enter(Request $request, string $token, ImpersonateCompany $action): RedirectResponse
    {
        abort_unless(
            $action->enter($request, $token),
            404,
            'رابط الدخول انتهى أو استُعمل. ادخل من لوحة المنصّة من جديد.',
        );

        $company = $request->attributes->get('company');

        return redirect()
            ->route('shipments.index')
            ->with('success', "أنت الآن داخل نظام {$company->name}. الدخول مسجَّل في سجلّ الشركة.");
    }

    public function stop(Request $request, ImpersonateCompany $action): RedirectResponse
    {
        $platform = $action->stop($request);

        // ليس دخولاً من المنصّة: مستخدم الشركة يعود إلى لوحته
        return $platform ? redirect()->away($platform) : redirect()->route('dashboard');
    }
}

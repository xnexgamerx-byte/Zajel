<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ImpersonateCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function start(Request $request, Company $company, ImpersonateCompany $action): RedirectResponse
    {
        $action->start($request, $company);

        return redirect()
            ->route('shipments.index')
            ->with('success', "أنت الآن داخل نظام {$company->name}. الدخول مسجَّل في سجلّ الشركة.");
    }

    public function stop(Request $request, ImpersonateCompany $action): RedirectResponse
    {
        $actor = $action->stop($request);

        if (! $actor) {
            return redirect()->route('admin.login');
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'عُدت إلى لوحة المنصّة.');
    }
}

<?php

namespace App\Http\Controllers\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Platform\CompanyReport;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «تقرير الشركة» في لوحة النواة (docs/plan/111): ربحها وشحناتها وتقويمها في مدّة، وما حُذف من شحناتها
 * وما تغيّر سعره. لمدير المنصّة وحده — مالُ الشركة لا يراه دعم المنصّة.
 */
class CompanyReportController extends Controller
{
    public function __invoke(Request $request, Company $company): View
    {
        abort_unless($request->user()?->role === UserRole::PlatformAdmin, 403);

        $period = ReportPeriod::fromRequest($request);

        return view('platform.companies.report', [
            'company' => $company,
            'period' => $period,
            'report' => (new CompanyReport($company, $period))->build(),
            'presets' => [
                'اليوم' => [today(), today()],
                'هذا الأسبوع' => [today()->startOfWeek(), today()],
                'هذا الشهر' => [today()->startOfMonth(), today()],
                'الشهر الماضي' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
            ],
        ]);
    }
}

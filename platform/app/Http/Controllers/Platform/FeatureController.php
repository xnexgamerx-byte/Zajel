<?php

namespace App\Http\Controllers\Platform;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyFeature;
use Illuminate\View\View;

/**
 * الميزات من جهة الميزة (docs/plan/35): في كم شركةٍ تعمل كلٌّ منها وكم تُدخل شهرياً، وأيّ
 * الشركات فُتحت لها — فميزةٌ جديدة تُفتح لشركةٍ بعد شركةٍ من صفحتها.
 */
class FeatureController extends Controller
{
    public function index(): View
    {
        $companies = Company::orderBy('name')->get(['id']);
        $decisions = CompanyFeature::acrossCompanies()->current()->get()->groupBy('feature');

        $rows = array_map(function (Feature $feature) use ($companies, $decisions) {
            $decided = ($decisions[$feature->value] ?? collect())->keyBy('company_id');
            $paying = $decided->where('enabled', true)->where('monthly_price', '>', 0);

            return [
                'feature' => $feature,
                'enabled' => $companies->filter(fn (Company $company) => isset($decided[$company->id])
                    ? $decided[$company->id]->enabled
                    : $feature->included())->count(),
                'paying'  => $paying->count(),
                'monthly' => (int) $paying->sum('monthly_price'),
            ];
        }, Feature::cases());

        return view('platform.features.index', ['rows' => $rows, 'companies' => $companies->count()]);
    }

    public function show(Feature $feature): View
    {
        $decided = CompanyFeature::acrossCompanies()->current()->where('feature', $feature->value)->get()->keyBy('company_id');

        $companies = Company::orderBy('name')->get(['id', 'name', 'slug', 'status', 'primary_color'])
            ->map(fn (Company $company) => [
                'company' => $company,
                'enabled' => isset($decided[$company->id]) ? $decided[$company->id]->enabled : $feature->included(),
                'price'   => (int) ($decided[$company->id]->monthly_price ?? 0),
                'since'   => $decided[$company->id]->starts_at ?? null,
                'decided' => isset($decided[$company->id]),
            ]);

        return view('platform.features.show', ['feature' => $feature, 'companies' => $companies]);
    }
}

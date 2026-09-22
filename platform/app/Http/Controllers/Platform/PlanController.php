<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    /** مفاتيح الوحدات التي تفتحها الباقة. */
    public const FEATURES = [
        'pickup_agents' => 'مندوبو الاستلام',
        'hubs'          => 'مراكز الفرز والأكياس',
        'api_access'    => 'ربط API للتجّار',
        'webhooks'      => 'Webhooks',
        'branded_apps'  => 'تطبيقات بعلامة الشركة',
        'custom_domain' => 'نطاق خاص',
    ];

    public function index(): View
    {
        return view('platform.plans.index', [
            'plans' => Plan::orderBy('sort_order')->get(),
            'counts' => Subscription::acrossCompanies()
                ->whereIn('status', ['trialing', 'active'])
                ->selectRaw('plan_id, count(*) as c')
                ->groupBy('plan_id')
                ->pluck('c', 'plan_id'),
            'features' => self::FEATURES,
        ]);
    }

    public function create(): View
    {
        return view('platform.plans.form', ['plan' => new Plan, 'features' => self::FEATURES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = Plan::create($this->validated($request));

        return redirect()->route('admin.plans.index')->with('success', "أُضيفت باقة {$plan->name}.");
    }

    public function edit(Plan $plan): View
    {
        return view('platform.plans.form', ['plan' => $plan, 'features' => self::FEATURES]);
    }

    /**
     * تعديل الباقة لا يمسّ الاشتراكات القائمة: أسعارها مُجمَّدة وقت
     * الاشتراك، فرفع السعر اليوم لا يفاجئ شركة اشتركت الشهر الماضي.
     */
    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validated($request, $plan));

        return redirect()->route('admin.plans.index')
            ->with('success', 'حُفظت الباقة. الاشتراكات القائمة تحتفظ بأسعارها.');
    }

    protected function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code'                    => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/',
                                          Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name'                    => ['required', 'string', 'max:120'],
            'description'             => ['nullable', 'string', 'max:500'],
            'price_monthly'           => ['required', 'integer', 'min:0', 'max:1000000000'],
            'price_yearly'            => ['required', 'integer', 'min:0', 'max:10000000000'],
            'commission_per_shipment' => ['required', 'integer', 'min:0', 'max:1000000'],
            'commission_percent'      => ['required', 'numeric', 'min:0', 'max:100'],
            'max_branches'            => ['nullable', 'integer', 'min:1'],
            'max_users'               => ['nullable', 'integer', 'min:1'],
            'max_couriers'            => ['nullable', 'integer', 'min:1'],
            'max_merchants'           => ['nullable', 'integer', 'min:1'],
            'max_shipments_per_month' => ['nullable', 'integer', 'min:1'],
            'features'                => ['nullable', 'array'],
            'features.*'              => ['string'],
            'is_active'               => ['nullable', 'boolean'],
            'sort_order'              => ['nullable', 'integer', 'min:0'],
        ], [], [
            'code' => 'الرمز', 'name' => 'الاسم', 'price_monthly' => 'السعر الشهري',
            'price_yearly' => 'السعر السنوي', 'commission_per_shipment' => 'عمولة الشحنة',
        ]);

        // مصفوفة المختار -> خريطة مفتاح/قيمة يقرأها Plan::has()
        $chosen = $data['features'] ?? [];
        $data['features'] = collect(self::FEATURES)
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => in_array($key, $chosen, true)])
            ->all();

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}

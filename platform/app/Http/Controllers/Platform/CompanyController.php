<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\RegisterCompany;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCompanyRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(Request $request): View
    {
        $companies = Company::query()
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%")
            ))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->withCount('users')
            ->orderBy('name')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        // استعلامان مجمَّعان بدل استعلامَين لكل شركة في الحلقة
        $shipmentCounts = DB::table('shipments')
            ->whereNull('deleted_at')
            ->selectRaw('company_id, count(*) as total, sum(case when created_at >= ? then 1 else 0 end) as this_month',
                [now()->startOfMonth()])
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $subscriptions = Subscription::query()
            ->acrossCompanies()
            ->whereIn('status', ['trialing', 'active'])
            ->with('plan:id,name')
            ->get()
            ->keyBy('company_id');

        return view('platform.companies.index', compact('companies', 'shipmentCounts', 'subscriptions'));
    }

    public function create(): View
    {
        return view('platform.companies.form', [
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'plans'        => Plan::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(RegisterCompanyRequest $request, RegisterCompany $register): RedirectResponse
    {
        $company = $register->handle($request->validated(), $request->user());

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('success', "سُجّلت {$company->name}. نظامها جاهز على {$company->slug}.".
                ' صاحب الشركة يدخل برقم هاتفه.');
    }

    public function show(Company $company): View
    {
        $stats = Tenancy::runFor($company, fn () => [
            'shipments'  => Shipment::count(),
            'this_month' => Shipment::where('created_at', '>=', now()->startOfMonth())->count(),
            'delivered'  => Shipment::where('status', ShipmentStatus::Delivered->value)->count(),
            'merchants'  => Merchant::count(),
            'couriers'   => Courier::count(),
            'users'      => User::count(),
            'owed'       => (int) Merchant::where('balance', '>', 0)->sum('balance'),
            'in_hand'    => (int) Courier::sum('cash_in_hand'),
        ]);

        return view('platform.companies.show', [
            'company'      => $company,
            'stats'        => $stats,
            'subscription' => Subscription::acrossCompanies()
                ->where('company_id', $company->id)
                ->with('plan')
                ->latest('id')
                ->first(),
            'plans'        => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'audit'        => AuditLog::where('company_id', $company->id)->latest('id')->limit(20)->get(),
            'invoices'     => Invoice::where('company_id', $company->id)->latest('id')->limit(6)->get(),
        ]);
    }

    /** إيقاف فوري: الشركة تُمنع من الدخول في الطلب التالي مباشرة. */
    public function suspend(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['reason' => 'السبب']);

        $company->update([
            'status'           => 'suspended',
            'suspended_at'     => now(),
            'suspended_reason' => $data['reason'],
        ]);

        AuditLog::create([
            'company_id' => $company->id,
            'user_id'    => $request->user()->id,
            'user_name'  => $request->user()->name,
            'action'     => 'company_suspended',
            'new_values' => ['reason' => $data['reason']],
            'ip'         => $request->ip(),
        ]);

        return back()->with('success', "أُوقفت {$company->name}.");
    }

    public function activate(Request $request, Company $company): RedirectResponse
    {
        $company->update([
            'status'           => 'active',
            'suspended_at'     => null,
            'suspended_reason' => null,
        ]);

        AuditLog::create([
            'company_id' => $company->id,
            'user_id'    => $request->user()->id,
            'user_name'  => $request->user()->name,
            'action'     => 'company_activated',
            'ip'         => $request->ip(),
        ]);

        return back()->with('success', "فُعّلت {$company->name}.");
    }
}

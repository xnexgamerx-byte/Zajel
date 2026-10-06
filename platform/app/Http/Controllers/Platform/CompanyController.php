<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\ChangeSubscription;
use App\Actions\Billing\EnforceDues;
use App\Actions\Platform\RegisterCompany;
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
use App\Support\Phone;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        // القائم لكلّ شركة كما في «الاشتراكات»: الأحدث يغلب في keyBy
        $subscriptions = Subscription::query()
            ->acrossCompanies()
            ->whereIn('status', ChangeSubscription::LIVE)
            ->with('plan:id,name')
            ->orderBy('id')
            ->get()
            ->keyBy('company_id');

        return view('platform.companies.index', compact('companies', 'shipmentCounts', 'subscriptions'));
    }

    public function create(): View
    {
        return view('platform.companies.form', [
            'company'      => new Company,
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'plans'        => Plan::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(RegisterCompanyRequest $request, RegisterCompany $register): RedirectResponse
    {
        $company = $register->handle($request->validated(), $request->user());
        $username = $request->validated('owner_username') ?: $request->validated('owner_phone');

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('success', "سُجّلت {$company->name}. نظامها جاهز على {$company->slug}.".
                " صاحب الشركة يدخل باسم المستخدم {$username}.");
    }

    public function show(Company $company): View
    {
        $stats = Tenancy::runFor($company, fn () => [
            'shipments'  => Shipment::count(),
            'this_month' => Shipment::where('created_at', '>=', now()->startOfMonth())->count(),
            // سُلِّمت كلّها أو بعضها — كما تُحسب في فاتورة الشركة (GenerateInvoice)
            'delivered'  => Shipment::whereNotNull('delivered_at')->count(),
            'merchants'  => Merchant::count(),
            'couriers'   => Courier::count(),
            'users'      => User::count(),
            'owed'       => (int) Merchant::where('balance', '>', 0)->sum('balance'),
            'in_hand'    => (int) Courier::sum('cash_in_hand'),
        ]);

        return view('platform.companies.show', [
            'company'      => $company->loadMissing('governorate:id,name_ar'),
            'stats'        => $stats,
            // القائم وحده: الملغى في سجلّ «الاشتراكات» لا هنا
            'subscription' => Subscription::acrossCompanies()
                ->where('company_id', $company->id)
                ->whereIn('status', ChangeSubscription::LIVE)
                ->with('plan')
                ->latest('id')
                ->first(),
            'plans'        => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'audit'        => AuditLog::where('company_id', $company->id)->latest('id')->limit(20)->get(),
            'invoices'     => Invoice::where('company_id', $company->id)->latest('id')->limit(6)->get(),
        ]);
    }

    public function edit(Company $company): View
    {
        return view('platform.companies.edit', [
            'company'      => $company,
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
        ]);
    }

    /**
     * بيانات الشركة من المنصّة: اسمها وطرق الوصول إليها ولونها.
     *
     * النطاق الفرعي لا يُعدَّل: هو عنوان نظامها، عليه تتصل تطبيقاتها ويُطبع
     * رابط التتبّع على ملصقاتها، وبه يُعرَف العنوان المجاني (ZAJEL_DEFAULT_COMPANY).
     * وما تغيّر وحده يُسجَّل، بقيمته قبل وبعد.
     */
    public function update(Request $request, Company $company): RedirectResponse
    {
        // ما لا يُفهم رقماً عراقياً يُرَدّ، والمفهوم يُحفظ بصيغةٍ واحدة
        $iraqi = function (string $attribute, mixed $value, \Closure $fail) {
            if (Phone::normalise((string) $value) === null) {
                $fail('الهاتف رقمٌ عراقي بصيغة 07xxxxxxxxx.');
            }
        };

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:160'],
            'name_en'        => ['nullable', 'string', 'max:160'],
            'phone'          => ['nullable', 'string', 'max:30', $iraqi],
            'email'          => ['nullable', 'email', 'max:160'],
            'governorate_id' => ['nullable', 'integer', Rule::exists('governorates', 'id')],
            'address'        => ['nullable', 'string', 'max:255'],
            'primary_color'  => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'primary_color.regex' => 'اللون بصيغة #RRGGBB.',
        ], [
            'name' => 'اسم الشركة', 'name_en' => 'الاسم بالإنجليزي', 'phone' => 'الهاتف', 'email' => 'البريد',
            'governorate_id' => 'المحافظة', 'address' => 'العنوان', 'primary_color' => 'اللون',
        ]);

        $company->fill([
            'name'           => $data['name'],
            'name_en'        => $data['name_en'] ?? null,
            'phone'          => Phone::normalise($data['phone'] ?? null),
            'email'          => $data['email'] ?? null,
            'governorate_id' => isset($data['governorate_id']) ? (int) $data['governorate_id'] : null,
            'address'        => $data['address'] ?? null,
            // منتقي الألوان يرسل الحروف صغيرة: اللون نفسه بحروفٍ أخرى ليس تعديلاً
            'primary_color'  => strcasecmp($data['primary_color'], (string) $company->primary_color) === 0
                ? $company->primary_color
                : strtoupper($data['primary_color']),
        ]);

        $changed = $company->getDirty();

        if (! $changed) {
            return redirect()->route('admin.companies.show', $company)->with('success', 'لم يتغيّر شيء.');
        }

        $old = array_intersect_key($company->getOriginal(), $changed);
        $company->save();

        AuditLog::create([
            'company_id'     => $company->id,
            'user_id'        => $request->user()->id,
            'user_name'      => $request->user()->name,
            'action'         => 'company_updated',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'old_values'     => $old,
            'new_values'     => $changed,
            'ip'             => $request->ip(),
        ]);

        return redirect()->route('admin.companies.show', $company)->with('success', "حُفظت بيانات {$company->name}.");
    }

    /**
     * لا يوقفها التأخّر (docs/plan/36) — شركة صاحب المنصّة مثلاً. والإعفاء يعيد فوراً نظاماً
     * أوقفه التأخّر؛ ورفعه يتركها للّيلة التالية إن كانت متأخّرة.
     */
    public function billingExempt(Request $request, Company $company, EnforceDues $enforce): RedirectResponse
    {
        $exempt = $request->boolean('exempt');

        if ($exempt === (bool) $company->billing_exempt) {
            return back()->with('success', 'لم يتغيّر شيء.');
        }

        $company->forceFill(['billing_exempt' => $exempt])->save();

        AuditLog::create([
            'company_id' => $company->id,
            'user_id'    => $request->user()->id,
            'user_name'  => $request->user()->name,
            'action'     => 'billing_exempt_changed',
            'old_values' => ['billing_exempt' => ! $exempt],
            'new_values' => ['billing_exempt' => $exempt],
            'ip'         => $request->ip(),
        ]);

        $resumed = $exempt && $enforce->resume($company, $request->user());

        return back()->with('success', $exempt
            ? "أُعفيت {$company->name} من الإيقاف التلقائي".($resumed ? '، وعاد نظامها.' : '.')
            : "رُفع إعفاء {$company->name}: إن تأخّرت فوق المهلة يتوقّف نظامها.");
    }

    /** إيقاف فوري: الشركة تُمنع من الدخول في الطلب التالي مباشرة. */
    public function suspend(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['reason' => 'السبب']);

        $company->update([
            'status'            => 'suspended',
            'suspended_at'      => now(),
            'suspended_reason'  => $data['reason'],
            // بيد المنصّة: لا يرفعه سدادٌ ولا ليلة، يرفعه من أوقفه (docs/plan/36)
            'suspension_source' => 'platform',
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
            'status'            => 'active',
            'suspended_at'      => null,
            'suspended_reason'  => null,
            'suspension_source' => null,
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

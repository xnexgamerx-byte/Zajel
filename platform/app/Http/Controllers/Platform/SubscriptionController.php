<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\ChangeSubscription;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * مال المنصّة مع الشركات: ما تدفعه كل شركةٍ لقاء النظام، وما بقي عليها.
 *
 * المنصّة وسيطٌ يبيع النظام. أسعار التوصيل ليست هنا: تلك بين الشركة
 * وتجّارها، تضبطها من «التسعيرات» في نظامها.
 */
class SubscriptionController extends Controller
{
    /** فواتير لم تُسدَّد كاملةً بعد. */
    private const UNPAID = ['issued', 'overdue'];

    public function index(Request $request): View
    {
        $companies = Company::query()
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%")
            ))
            ->orderBy('name')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        $ids = $companies->pluck('id');

        // الأحدث لكل شركة: keyBy يُبقي آخر ما رُتِّب بالمعرّف
        $current = Subscription::acrossCompanies()
            ->whereIn('company_id', $ids)
            ->whereIn('status', ChangeSubscription::LIVE)
            ->with('plan:id,name')
            ->orderBy('id')
            ->get()
            ->keyBy('company_id');

        $owed = Invoice::acrossCompanies()
            ->whereIn('company_id', $ids)
            ->whereIn('status', self::UNPAID)
            ->groupBy('company_id')
            ->selectRaw('company_id, sum(total - amount_paid) as due')
            ->pluck('due', 'company_id');

        $live = Subscription::acrossCompanies()
            ->whereIn('status', ChangeSubscription::LIVE)
            ->get(['id', 'company_id', 'status', 'billing_cycle', 'price']);

        return view('platform.subscriptions.index', [
            'companies' => $companies,
            'current'   => $current,
            'owed'      => $owed,
            'summary'   => [
                // الدخل الشهري من المدفوع وحده: السنويّ يُقسَم على اثني عشر، والتجريبيّ لم يُدفع
                'monthly'   => (int) $live->where('status', 'active')->sum(
                    fn (Subscription $s) => $s->billing_cycle === 'yearly' ? (int) round($s->price / 12) : (int) $s->price
                ),
                'owed'      => (int) Invoice::acrossCompanies()->whereIn('status', self::UNPAID)
                    ->sum(DB::raw('total - amount_paid')),
                'collected' => (int) Payment::acrossCompanies()->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'without'   => Company::where('status', '!=', 'cancelled')
                    ->whereNotIn('id', $live->pluck('company_id')->unique())
                    ->count(),
            ],
        ]);
    }

    public function show(Company $company): View
    {
        $history = Subscription::acrossCompanies()
            ->where('company_id', $company->id)
            ->with('plan:id,name')
            ->latest('id')
            ->get();

        return view('platform.subscriptions.show', [
            'company'  => $company,
            'current'  => $history->first(fn (Subscription $s) => in_array($s->status, ChangeSubscription::LIVE, true)),
            'history'  => $history,
            'plans'    => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'invoices' => Invoice::acrossCompanies()->where('company_id', $company->id)->latest('id')->limit(6)->get(),
            'owed'     => (int) Invoice::acrossCompanies()->where('company_id', $company->id)
                ->whereIn('status', self::UNPAID)->sum(DB::raw('total - amount_paid')),
            'paid'     => (int) Payment::acrossCompanies()->where('company_id', $company->id)->sum('amount'),
        ]);
    }

    public function store(Request $request, Company $company, ChangeSubscription $change): RedirectResponse
    {
        // «250,000» و«٢٥٠٬٠٠٠» كما تُكتب: أرقامٌ لاتينية بلا فواصل قبل التحقّق
        if ($request->filled('price')) {
            $request->merge(['price' => preg_replace('/[\s,،٬]+/u', '', Phone::latinDigits((string) $request->input('price')))]);
        }

        $data = $request->validate([
            'plan_id'       => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'price'         => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            // شهرٌ إلى الوراء لمن بدأ قبل تسجيله هنا، وثلاثةٌ إلى الأمام لمن يبدأ لاحقاً
            'starts_at'     => ['required', 'date_format:Y-m-d',
                                'after_or_equal:'.now()->subMonthNoOverflow()->toDateString(),
                                'before_or_equal:'.now()->addMonthsNoOverflow(3)->toDateString()],
            'notes'         => ['nullable', 'string', 'max:500'],
        ], [
            'starts_at.after_or_equal'  => 'البداية لا تسبق اليوم بأكثر من شهر.',
            'starts_at.before_or_equal' => 'البداية خلال ثلاثة أشهرٍ من اليوم.',
        ], [
            'plan_id' => 'الباقة', 'billing_cycle' => 'الدورة', 'price' => 'المبلغ',
            'starts_at' => 'تاريخ البداية', 'notes' => 'الملاحظة',
        ]);

        $subscription = $change->start($company, Plan::findOrFail($data['plan_id']), $data, $request->user());

        return redirect()->route('admin.subscriptions.show', $company)->with('success',
            "بدأ اشتراك {$company->name}: ".number_format($subscription->price).' د.ع '
            .($subscription->billing_cycle === 'yearly' ? 'سنوياً' : 'شهرياً').'.');
    }

    public function cancel(Request $request, Company $company, ChangeSubscription $change): RedirectResponse
    {
        $ended = $change->cancel($company, $request->user());

        return redirect()->route('admin.subscriptions.show', $company)->with('success', $ended
            ? "أُلغي اشتراك {$company->name}: لا تجديد ولا فواتير اشتراكٍ بعد هذا الشهر. الشركة تعمل حتى توقفها من صفحتها."
            : 'لا اشتراك قائم لإلغائه.');
    }
}

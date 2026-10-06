<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\EnforceDues;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Support\Billing\BillingPolicy;
use App\Support\Billing\Dues;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «إعدادات المنصّة» (docs/plan/36): كيف تدفع الشركات، ومهلة الإيقاف التلقائي بعد موعد
 * الفاتورة، وأيام التنبيه قبل انتهاء الاشتراك. وتحتها الشركات المتأخّرة الآن، فتعرف قبل أن
 * تحفظ مهلةً مَن يتوقّف بها.
 */
class SettingsController extends Controller
{
    public function edit(): View
    {
        $today = CarbonImmutable::today();

        // أقدم متأخّرةٍ لكل شركة، بأيام تأخّرها
        $late = Invoice::acrossCompanies()
            ->whereIn('status', Dues::OPEN)
            ->whereColumn('amount_paid', '<', 'total')
            ->where('due_at', '<', $today->toDateString())
            ->with('company:id,name,status,billing_exempt,suspension_source')
            ->orderBy('due_at')
            ->get()
            ->unique('company_id')
            ->map(fn (Invoice $invoice) => [
                'invoice' => $invoice,
                'company' => $invoice->company,
                'days'    => (int) CarbonImmutable::parse($invoice->due_at)->diffInDays($today),
                'balance' => (int) Invoice::acrossCompanies()->where('company_id', $invoice->company_id)
                    ->whereIn('status', Dues::OPEN)->sum(DB::raw('total - amount_paid')),
            ])
            ->values();

        return view('platform.settings', [
            'methods'  => BillingPolicy::paymentMethods(),
            'grace'    => BillingPolicy::graceDays(),
            'reminder' => BillingPolicy::reminderDays(),
            'late'     => $late,
        ]);
    }

    public function update(Request $request, EnforceDues $enforce): RedirectResponse
    {
        $data = $request->validate([
            'payment_methods' => ['nullable', 'string', 'max:2000'],
            'grace_days'      => ['nullable', 'integer', 'min:1', 'max:365'],
            'reminder_days'   => ['required', 'integer', 'min:1', 'max:60'],
        ], [], [
            'payment_methods' => 'طرق الدفع', 'grace_days' => 'مهلة الإيقاف', 'reminder_days' => 'أيام التنبيه',
        ]);

        $methods = collect(preg_split('/\R/u', (string) ($data['payment_methods'] ?? '')))
            ->map(fn (string $line) => trim($line))->filter()->implode("\n");

        PlatformSetting::put(BillingPolicy::PAYMENT_METHODS, $methods);
        PlatformSetting::put(BillingPolicy::GRACE_DAYS, isset($data['grace_days']) ? (int) $data['grace_days'] : null);
        PlatformSetting::put(BillingPolicy::REMINDER_DAYS, (int) $data['reminder_days']);

        // مهلةٌ أطول أو بلا مهلة: ما أوقفه التأخّر ولم يعد فائتاً يعود الآن. والأقصر تُطبَّق ليلاً
        foreach (Company::where('status', 'suspended')->where('suspension_source', 'billing')->get() as $company) {
            $enforce->resume($company, $request->user());
        }

        return redirect()->route('admin.settings')->with('success', 'حُفظت إعدادات المنصّة.');
    }
}

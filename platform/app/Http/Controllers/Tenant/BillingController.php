<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Billing\PaymentNotices;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentNotice;
use App\Support\Billing\BillingPolicy;
use App\Support\Billing\Dues;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «اشتراك الشركة وفواتيرها» (docs/plan/36): ما على الشركة لإدارة المنصّة — اشتراكها
 * وعمولتها ورسوم ميزاتها — وكيف تدفع، و«دفعتُ» تُبلغ به عن دفعتها.
 *
 * لصاحب الشركة ومن يدير بياناتها في الفرع الرئيسي. وحين يوقف التأخّرُ نظامَها تبقى هذه
 * الصفحة وحدها مفتوحة (IdentifyTenant)، فيدفع منها فيعود.
 */
class BillingController extends Controller
{
    public function index(Request $request): View
    {
        $company = Tenancy::company();
        $dues = Dues::for($company);

        return view('tenant.billing.index', [
            'dues'     => $dues,
            'invoices' => Invoice::whereIn('status', ['issued', 'overdue', 'paid'])->latest('period_start')->latest('id')
                ->paginate(12)->withQueryString(),
            'notices'  => PaymentNotice::with('invoice:id,number')->latest('id')->limit(10)->get(),
            'methods'  => BillingPolicy::paymentMethods(),
            'features' => $company->featureStates(),
        ]);
    }

    public function invoice(Invoice $invoice): View
    {
        abort_if($invoice->status === 'draft', 404);

        return view('tenant.billing.invoice', [
            'invoice' => $invoice->load(['items', 'payments', 'subscription.plan']),
        ]);
    }

    public function notify(Request $request, PaymentNotices $notices): RedirectResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount'     => ['required', 'integer', 'min:1', 'max:10000000000'],
            'method'     => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference'  => ['nullable', 'string', 'max:120'],
            'paid_on'    => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subMonths(6)->toDateString()],
            'note'       => ['nullable', 'string', 'max:500'],
            'proof'      => PaymentNotices::PROOF_RULE,
        ], [
            'paid_on.before_or_equal' => 'تاريخ الدفع لا يكون بعد اليوم.',
            'paid_on.after_or_equal'  => 'تاريخ الدفع خلال الأشهر الستة الماضية.',
            'proof.mimes'             => 'الإيصال صورةٌ (JPG أو PNG أو WEBP) أو PDF.',
            'proof.max'               => 'الإيصال أكبر من ٥ ميغابايت.',
        ], [
            'invoice_id' => 'الفاتورة', 'amount' => 'المبلغ', 'method' => 'طريقة الدفع',
            'reference' => 'رقم الحوالة', 'paid_on' => 'تاريخ الدفع', 'note' => 'الملاحظة', 'proof' => 'الإيصال',
        ]);

        $notice = $notices->report(Tenancy::company(), $data, $request->user(), $request->file('proof'));

        return redirect()->route('billing')->with('success',
            'وصل إبلاغك بدفع '.number_format($notice->amount).' د.ع — يُسجَّل على الفاتورة حين تؤكّده إدارة المنصّة.');
    }

    public function proof(PaymentNotice $notice): StreamedResponse
    {
        return $notice->proofResponse();
    }
}

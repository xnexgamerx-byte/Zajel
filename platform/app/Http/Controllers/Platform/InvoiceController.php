<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\GenerateInvoice;
use App\Actions\Billing\RecordPayment;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $invoices = Invoice::query()
            ->with('company:id,name,slug')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        $totals = DB::table('invoices')
            ->selectRaw('sum(total) as billed, sum(amount_paid) as collected')
            ->selectRaw("sum(case when status in ('issued','overdue') then total - amount_paid else 0 end) as outstanding")
            ->first();

        return view('platform.invoices.index', [
            'invoices'  => $invoices,
            'totals'    => $totals,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'lastMonth' => CarbonImmutable::now()->subMonth(),
        ]);
    }

    /** توليد فواتير شهر لكل الشركات المفعّلة من الواجهة. */
    public function generate(Request $request, GenerateInvoice $generate): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ], [], ['month' => 'الشهر']);

        $period = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01');

        $created = 0;
        $skipped = 0;

        foreach (Company::whereIn('status', ['active', 'trial'])->orderBy('id')->get() as $company) {
            try {
                $generate->handle($company, $period);
                $created++;
            } catch (\Illuminate\Validation\ValidationException) {
                $skipped++;   // مفوترة سلفاً
            }
        }

        return back()->with('success', "صدرت {$created} فاتورة لشهر {$data['month']}."
            .($skipped ? " تُخطّيت {$skipped} لأنها مفوترة سلفاً." : ''));
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['company', 'items', 'payments', 'subscription.plan']);

        return view('platform.invoices.show', compact('invoice'));
    }

    public function pay(Request $request, Invoice $invoice, RecordPayment $record): RedirectResponse
    {
        $data = $request->validate([
            'amount'    => ['required', 'integer', 'min:1'],
            'method'    => ['required', Rule::in(['cash', 'zaincash', 'asiahawala', 'fastpay', 'qi', 'fib', 'bank_transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_at'   => ['nullable', 'date'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => 'المبلغ', 'method' => 'الطريقة']);

        $record->handle($invoice, $data, $request->user());

        return back()->with('success', 'سُجّلت الدفعة.');
    }
}

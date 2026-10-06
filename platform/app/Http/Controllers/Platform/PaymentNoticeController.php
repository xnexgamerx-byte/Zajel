<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\PaymentNotices;
use App\Http\Controllers\Controller;
use App\Models\PaymentNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * دفعاتٌ أبلغت عنها الشركات (docs/plan/36): تؤكّدها فتُسجَّل على فاتورتها — ويعود نظامٌ أوقفه
 * التأخّر — أو ترفضها بسببٍ تقرؤه الشركة في صفحة فواتيرها.
 */
class PaymentNoticeController extends Controller
{
    public function confirm(Request $request, PaymentNotice $notice, PaymentNotices $notices): RedirectResponse
    {
        $payment = $notices->confirm($notice, $request->user());

        return back()->with('success', 'سُجّلت دفعة '.number_format($payment->amount).' د.ع على فاتورة '
            .$notice->invoice?->number.' من إبلاغ '.$notice->company?->name.'.');
    }

    public function reject(Request $request, PaymentNotice $notice, PaymentNotices $notices): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['reason' => 'سبب الرفض']);

        $notices->reject($notice, trim($data['reason']), $request->user());

        return back()->with('success', 'رُفض الإبلاغ، وتقرأ الشركة سببه في صفحة فواتيرها.');
    }

    public function proof(PaymentNotice $notice): StreamedResponse
    {
        return $notice->proofResponse();
    }
}

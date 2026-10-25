<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Receipts\ConfirmReceipt;
use App\Http\Controllers\Controller;
use App\Models\MerchantSettlement;
use App\Models\ReturnBatch;
use App\Models\Transaction;
use App\Support\ReceiptCode;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * كشف حساب التاجر بنفسه.
 *
 * هذه الشاشة وحدها تُلغي أكثر مكالمة تصل خدمة العملاء: "شكد لي عندكم؟".
 * الأرقام مقروءة من دفتر الحركات لا محسوبة هنا، فهي نفسها التي تراها
 * الشركة — لا رقمان لحساب واحد.
 */
class StatementController extends Controller
{
    public function __invoke(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');

        return view('portal.statement', [
            'transactions' => Transaction::forAccount('merchant', $merchant->id)
                ->when($request->query('from'), fn ($q, $d) => $q->whereFromDate('created_at', $d))
                ->when($request->query('to'), fn ($q, $d) => $q->whereUntilDate('created_at', $d))
                ->with('shipment:id,number,recipient_name')
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),

            'settlements' => MerchantSettlement::where('merchant_id', $merchant->id)
                ->latest('id')->limit(10)->get(),

            // «كشوفات الرواجع»: ما سُلّم له من رواجع بإيصالاتها، وما ينتظر تأكيده (docs/plan/69)
            'returnBatches' => ReturnBatch::where('merchant_id', $merchant->id)->with('courier:id,name,hidden_from_merchants')
                ->latest('id')->limit(10)->get(),
            'scanRequired' => ConfirmReceipt::required(),
        ]);
    }

    /**
     * «استلمت»: التاجر يؤكّد أن ما دُفع له وصله — ومن لم يؤكّد يظهر في
     * «عملاء لم يؤكّدوا دفعات»، فيُسأل قبل أن يصير الخلاف شهراً.
     */
    public function confirm(Request $request, MerchantSettlement $settlement): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $settlement->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        // الشركة تطلب الورقة: يُؤكَّد بمسح وصلها لا بزرّ (docs/plan/69)
        if (ConfirmReceipt::required() && ! ReceiptCode::matches($settlement->receipt_code, $request->input('code'))) {
            return redirect()->route('portal.receive.index')->withErrors(['code' => ConfirmReceipt::scanFirst($settlement)]);
        }

        $confirmed = MerchantSettlement::whereKey($settlement->id)->where('status', 'paid')->whereNull('merchant_confirmed_at')
            ->update(['merchant_confirmed_at' => now()]);

        return $confirmed
            ? back()->with('success', "أكّدت استلام دفعة الكشف {$settlement->code}.")
            : back()->withErrors(['settlement' => "الكشف {$settlement->code} لم يُدفع بعد أو أكّدته سلفاً."]);
    }
}

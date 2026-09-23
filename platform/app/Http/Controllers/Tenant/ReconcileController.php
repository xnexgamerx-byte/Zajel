<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * مطابقة الدفتر: هل تقول الأرقام الشيء نفسه من كل جهة؟
 *
 * سؤالان لا يُجيب عنهما أيّ كشف: هل رصيدُ كلِّ حسابٍ مجموعُ قيوده؟ وهل
 * قيودُ كلِّ شحنةٍ مستحقُّها؟ الأوّل يكشف رصيداً عُدِّل خارج الدفتر،
 * والثاني شحنةً سُلِّمت ولم يُقيَّد مالها — أو قُيِّد مرّتين.
 */
class ReconcileController extends Controller
{
    public function __invoke(Request $request, Ledger $ledger): View
    {
        $merchantId = $request->integer('merchant_id') ?: null;

        return view('tenant.money.reconcile', [
            'balances'   => $ledger->balancesOff(),
            'byMerchant' => $ledger->offLedgerByMerchant(),
            'shipments'  => $ledger->shipmentsOffLedger($merchantId, null, 200),
            'merchantId' => $merchantId,
            'names'      => Merchant::withTrashed()->pluck('business_name', 'id'),
        ]);
    }
}

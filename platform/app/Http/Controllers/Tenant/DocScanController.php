<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BranchRemittance;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\MerchantSettlement;
use App\Models\ReturnBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * رمز QR على الورق يُستلم به (docs/plan/61): كشف المندوب يصل مع ماله من الفرع، وحوالة الفرع
 * مع نقدها، ووصل حساب التاجر بيده، وإيصال الراجع يعود موقَّعاً — يُمسح الرمز بكاميرا الهاتف أو بقارئ الباركود فيفتح ما يُستلم به.
 *
 * الرمز رابطٌ لصفحةٍ تحت صلاحياتها المعتادة: من لا يملك المال لا يفتح شيئاً، وما لا يُرى بفرعه لا يُرى.
 */
class DocScanController extends Controller
{
    /** كشف المندوب: مسودّته المفتوحة إن كانت، وإلّا ما بيده وزرّ فتح كشفه */
    public function courier(Courier $courier): View|RedirectResponse
    {
        $draft = CourierSettlement::where('courier_id', $courier->id)->where('status', 'draft')->latest('id')->first();

        if ($draft) {
            return redirect()->route('settlements.couriers.show', $draft);
        }

        return view('tenant.settlements.couriers.scan', ['courier' => $courier]);
    }

    public function remittance(BranchRemittance $remittance): RedirectResponse
    {
        return redirect()->to(route('branch-accounts.remittances').'#remittance-'.$remittance->id);
    }

    public function merchant(MerchantSettlement $settlement): RedirectResponse
    {
        return redirect()->route('settlements.merchants.show', $settlement);
    }

    /** إيصال الراجع يعود موقَّعاً من التاجر: يُفتح سطره في «دفعات الراجع» ليؤكَّد استلامه */
    public function returns(ReturnBatch $batch): RedirectResponse
    {
        return redirect()->to(route('return-batches.index', ['merchant_id' => $batch->merchant_id]).'#batch-'.$batch->id);
    }

    /** وصل الحوالة يرافق نقدها: يُمسح عند الفرع المستلم */
    public function remittancePrint(BranchRemittance $remittance): View
    {
        return view('tenant.branch_accounts.remittance_print', [
            'remittance' => $remittance->load('fromBranch:id,name', 'toBranch:id,name', 'sentBy:id,name'),
        ]);
    }

    /** وصل حساب التاجر بالرمز: يُمسح فيفتح كشفه ليُدفع له */
    public function merchantPrint(Request $request, MerchantSettlement $settlement): View
    {
        return view('tenant.settlements.merchants.print', [
            'settlement' => $settlement->load('merchant'),
            'lines' => $settlement->lines()->with('shipment:id,number,recipient_name,status,delivered_at')->orderBy('id')->get(),
        ]);
    }
}

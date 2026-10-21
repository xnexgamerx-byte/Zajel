<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Receipts\ConfirmReceipt;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MerchantSettlement;
use App\Models\ReturnBatch;
use App\Support\ReceiptCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * رابط الباركود على وصل الحساب وإيصال الراجع (docs/plan/69): /rc/<الرمز>. رابطٌ واحدٌ لمن
 * يمسحه، كلٌّ إلى ما يخصّه: التاجر إلى تأكيد استلامه في بوابته، والموظّف إلى الكشف أو الإيصال
 * في النظام كما كان رمز الورقة يفتحه (docs/plan/61) — تحت صلاحياته المعتادة.
 */
class ReceiptLinkController extends Controller
{
    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $code = ReceiptCode::normalize($code);

        abort_if($code === null, 404);

        $role = $request->user()->role;

        if ($role === UserRole::Merchant) {
            return ConfirmReceipt::required()
                ? redirect()->route('portal.receive.index', ['code' => $code])
                : redirect()->route('portal.statement');
        }

        abort_if($role === UserRole::Courier, 403, 'هذه الورقة يمسحها التاجر أو موظّف الشركة.');

        if ($settlement = MerchantSettlement::where('receipt_code', $code)->first()) {
            return redirect()->route('doc.merchant', $settlement);
        }

        if ($batch = ReturnBatch::where('receipt_code', $code)->first()) {
            return redirect()->route('doc.returns', $batch);
        }

        abort(404);
    }
}

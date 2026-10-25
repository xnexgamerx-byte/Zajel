<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Receipts\ConfirmReceipt;
use App\Http\Controllers\Controller;
use App\Models\MerchantSettlement;
use App\Models\ReturnBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «استلام بالباركود» في بوابة التاجر (docs/plan/69): يمسح الورقة التي وصلته — وصل حسابه مع
 * ماله، أو إيصال رواجعه — فيُؤكَّد استلامها. قارئ الباركود يكتب الرمز في الحقل، وكاميرا الهاتف
 * تفتح رابط الورقة فيصل إلى هنا وفيه الرمز.
 */
class ReceiveController extends Controller
{
    public function __construct(private ConfirmReceipt $receipts) {}

    public function index(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');
        $scanned = $request->query('code');

        return view('portal.receive', [
            'scanned' => $scanned,
            'doc' => filled($scanned) ? $this->receipts->find($merchant, $scanned) : null,
            'settlements' => MerchantSettlement::where('merchant_id', $merchant->id)->where('status', 'paid')
                ->whereNull('merchant_confirmed_at')->latest('id')->limit(20)->get(),
            'batches' => ReturnBatch::where('merchant_id', $merchant->id)->whereNull('received_at')
                ->with('courier:id,name,hidden_from_merchants')->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:300']], [], ['code' => 'رمز الورقة']);

        $doc = $this->receipts->find($request->attributes->get('merchant'), $data['code']);

        if (! $doc) {
            return back()->withInput()->withErrors(['code' => ConfirmReceipt::NOT_YOURS]);
        }

        [$confirmed, $message] = $this->receipts->confirm($doc, $request->user(), 'التاجر بمسح الإيصال من بوابته');

        return $confirmed
            ? redirect()->route('portal.receive.index')->with('success', $message)
            : redirect()->route('portal.receive.index')->withErrors(['code' => $message]);
    }
}

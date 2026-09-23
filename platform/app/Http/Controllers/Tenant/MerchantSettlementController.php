<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MerchantSettlementController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.settlements.merchants.index', [
            'settlements' => MerchantSettlement::with('merchant:id,business_name,code')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('merchant_id'), fn ($q, $m) => $q->where('merchant_id', $m))
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),

            'pending' => Merchant::query()
                ->where('balance', '!=', 0)
                ->orderByDesc('balance')
                ->get(),
        ]);
    }

    public function store(Request $request, BuildMerchantSettlement $build): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
        ], [], ['merchant_id' => 'التاجر']);

        $merchant = Merchant::find($data['merchant_id']);

        if (! $merchant) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $settlement = $build->handle($merchant, $request->user(), [
            'from' => $data['from'] ?? null,
            'to'   => $data['to'] ?? null,
        ]);

        return redirect()
            ->route('settlements.merchants.show', $settlement)
            ->with('success', "فُتح كشف {$settlement->code}، فيه ".\App\Support\Arabic::shipments((int) $settlement->shipments_count).'.');
    }

    public function show(MerchantSettlement $settlement): View
    {
        $settlement->load(['merchant', 'lines.shipment.governorate:id,name_ar']);

        return view('tenant.settlements.merchants.show', compact('settlement'));
    }

    public function confirm(Request $request, MerchantSettlement $settlement, PayMerchantSettlement $action): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $action->confirm($settlement, $request->user(), $data['notes'] ?? null);

        return back()->with('success', "أُقفِل كشف {$settlement->code}. سجّل الدفع بعد تحويل المبلغ.");
    }

    public function pay(Request $request, MerchantSettlement $settlement, PayMerchantSettlement $action): RedirectResponse
    {
        $data = $request->validate([
            'payout_method'    => ['required', Rule::in(['cash', 'zaincash', 'asiahawala', 'fastpay', 'qi', 'fib', 'bank_transfer'])],
            'payout_reference' => ['nullable', 'string', 'max:120'],
        ], [], ['payout_method' => 'طريقة الدفع', 'payout_reference' => 'رقم الحوالة']);

        $action->pay($settlement, $request->user(), $data['payout_method'], $data['payout_reference'] ?? null);

        return back()->with('success', "سُجِّل دفع كشف {$settlement->code}.");
    }
}

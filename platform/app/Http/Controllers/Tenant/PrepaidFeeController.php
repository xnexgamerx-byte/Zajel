<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Cash\ReceivePrepaidFees;
use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\Merchant;
use App\Models\PrepaidReceipt;
use App\Models\Shipment;
use App\Support\Arabic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «استلام أجور مدفوعة مقدّماً» — «استلام مبالغ وصولات مدفوعة التوصيل» في المعتاد:
 * ما ينتظر القبض لكل تاجر، وقبضه بإيصالٍ في صندوق، وأرشيف الإيصالات.
 */
class PrepaidFeeController extends Controller
{
    /** أقصى ما يُعرض ويُقبض لتاجرٍ في المرّة */
    public const MAX = 500;

    public function index(Request $request): View
    {
        $user = $request->user();
        $pending = fn () => ReceivePrepaidFees::pending(Shipment::query()->visibleTo($user));

        $waiting = $pending()
            ->selectRaw('shipments.merchant_id, count(*) as shipments, sum(shipments.total_fees) as amount, min(shipments.created_at) as oldest')
            ->groupBy('shipments.merchant_id')
            ->orderByRaw('min(shipments.created_at)')
            ->toBase()->get();

        $names = Merchant::withTrashed()->whereIn('id', $waiting->pluck('merchant_id'))->pluck('business_name', 'id');
        $merchant = $request->integer('merchant_id') ? Merchant::visibleTo($user)->find($request->integer('merchant_id')) : null;

        return view('tenant.money.prepaid', [
            'waiting'    => $waiting,
            'names'      => $names,
            'merchant'   => $merchant,
            'shipments'  => $merchant ? $pending()->where('shipments.merchant_id', $merchant->id)
                ->with('governorate:id,name_ar', 'city:id,name_ar')
                ->orderBy('shipments.id')->limit(self::MAX)->get() : collect(),
            'boxes'      => CashBox::active()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'balance']),
            'defaultBox' => CashBox::forActor($user, $user->branch_id)?->id,
            'receipts'   => PrepaidReceipt::visibleTo($user)->with(['merchant:id,business_name', 'cashBox:id,name', 'user:id,name'])
                ->latest('id')->paginate(config('zajel.per_page'))->withQueryString(),
        ]);
    }

    public function store(Request $request, ReceivePrepaidFees $receive): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id'    => ['required', 'integer'],
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'shipment_ids.*' => ['integer'],
            'cash_box_id'    => ['required', 'integer'],
            'note'           => ['nullable', 'string', 'max:255'],
        ], ['shipment_ids.required' => 'اختر شحنةً واحدة على الأقل.'], [
            'merchant_id' => 'التاجر', 'shipment_ids' => 'الشحنات', 'cash_box_id' => 'الصندوق', 'note' => 'الملاحظة',
        ]);

        $user = $request->user();
        $merchant = Merchant::visibleTo($user)->find($data['merchant_id']);
        $box = CashBox::active()->visibleTo($user)->find($data['cash_box_id']);

        if (! $merchant) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        if (! $box) {
            return back()->withErrors(['cash_box_id' => 'الصندوق غير موجود أو موقوف.']);
        }

        // ما يراه الموظّف وحده: رقمُ شحنةٍ من فرعٍ آخر لا يُقبض عنها
        $ids = Shipment::query()->visibleTo($user)->whereIn('shipments.id', $data['shipment_ids'])->pluck('shipments.id')->all();

        $receipt = $receive->handle($merchant, $ids, $box, $user, $data['note'] ?? null);

        return redirect()->route('prepaid-fees.index')->with('success',
            'قُبض '.number_format($receipt->amount).' د.ع من '.$merchant->business_name.' عن '
            .Arabic::shipments($receipt->shipments_count).' في '.$box->name.' — إيصال '.$receipt->number.'.');
    }
}

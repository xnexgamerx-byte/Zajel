<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierReturnReceipt;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «إيصالات راجع المندوبين»: كإيصالات الراجع للتجّار من الجهة الأخرى — كلّ استلامٍ لراجع
 * مندوبٍ بالمخزن إيصالٌ برقم، بشحناته ومن استلمه ومتى، ويُطبع.
 */
class CourierReturnReceiptController extends Controller
{
    public function index(Request $request): View
    {
        $term = mb_substr(trim((string) $request->query('q')), 0, 60);

        $receipts = CourierReturnReceipt::query()
            ->visibleTo($request->user())
            ->with(['courier:id,name', 'receivedBy:id,name'])
            ->when($request->integer('courier_id'), fn ($q, $id) => $q->where('courier_id', $id))
            // رقم الإيصال، أو رقم وصلٍ فيه
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', $term)
                ->orWhereHas('shipments', fn ($s) => $s->where('number', $term)->orWhere('barcode', $term))))
            ->when($request->date('from'), fn ($q, $d) => $q->whereFromDate('received_at', $d->toDateString()))
            ->when($request->date('to'), fn ($q, $d) => $q->whereUntilDate('received_at', $d->toDateString()))
            ->latest('received_at')->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.returns.courier-receipts', [
            'receipts' => $receipts,
            'term'     => $term,
            'couriers' => Courier::delivering()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function print(CourierReturnReceipt $receipt): View
    {
        $receipt->load([
            'courier:id,name,phone,code', 'receivedBy:id,name',
            'shipments' => fn ($q) => $q->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar'])->orderBy('id'),
        ]);

        return view('tenant.returns.courier-receipt', ['receipt' => $receipt]);
    }
}

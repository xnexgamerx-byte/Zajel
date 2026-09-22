<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\CourierSettlement;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "شكد بيدي؟" — سؤال المندوب اليومي.
 *
 * الرقم من دفتر الحركات نفسه الذي تقرأه الشركة، فلا خلاف على رقمين.
 */
class CashController extends Controller
{
    public function __invoke(Request $request): View
    {
        $courier = $request->attributes->get('courier');

        return view('courier.cash', [
            'transactions' => Transaction::forAccount('courier', $courier->id)
                ->with('shipment:id,number')
                ->latest('id')
                ->limit(40)
                ->get(),

            'settlements' => CourierSettlement::where('courier_id', $courier->id)
                ->latest('id')->limit(5)->get(),
        ]);
    }
}

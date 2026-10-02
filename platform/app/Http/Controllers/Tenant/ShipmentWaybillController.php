<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Waybills\CreateFromWaybill;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\WaybillBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «شحنة من وصلٍ مطبوع» — «إنشاء شحنة باركود» في المعتاد: يُمسح الوصل الذي كتب
 * عليه التاجر بيده، فيُفتح نموذج الشحنة وعليه رقمه وتاجره، وبعد الحفظ يعود
 * إلى هنا للوصل التالي.
 */
class ShipmentWaybillController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $code = WaybillBook::fromInput($request->query('code'));

        if ($code === '') {
            return view('tenant.shipments.waybill', ['code' => '', 'problem' => null, 'used' => null]);
        }

        if ($problem = CreateFromWaybill::problem($code, $request->user())) {
            // وصلٌ استُعمل: رابطٌ لشحنته إن كانت ممّا يراه فرع الموظّف
            $used = Shipment::visibleTo($request->user())->where('barcode', WaybillBook::normalise($code))->first();

            return view('tenant.shipments.waybill', ['code' => WaybillBook::normalise($code), 'problem' => $problem, 'used' => $used]);
        }

        return redirect()->route('shipments.create', ['waybill' => WaybillBook::normalise($code)]);
    }
}

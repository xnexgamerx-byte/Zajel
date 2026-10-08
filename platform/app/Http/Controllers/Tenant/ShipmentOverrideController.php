<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\OverrideShipment;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «تعديل الأجور والطلبية» بصلاحيةٍ خاصّة (docs/plan/38): أيّاً كانت حال الشحنة.
 */
class ShipmentOverrideController extends Controller
{
    public function edit(Shipment $shipment): View
    {
        return view('tenant.shipments.override', [
            'shipment'         => $shipment->load('merchant:id,business_name', 'deliveryCourier:id,name'),
            'merchantPosted'   => OverrideShipment::merchantPosted($shipment),
            'commissionPosted' => OverrideShipment::commissionPosted($shipment),
        ]);
    }

    public function update(Request $request, Shipment $shipment, OverrideShipment $override): RedirectResponse
    {
        $request->merge(array_map(
            fn ($phone) => $phone === null ? null : Phone::latinDigits((string) $phone),
            $request->only(['recipient_phone', 'recipient_phone_alt']),
        ));

        $data = $request->validate([
            'recipient_name'      => ['nullable', 'string', 'max:160'],
            'recipient_phone'     => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'recipient_phone_alt' => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
            'address'             => ['nullable', 'string', 'max:500'],
            'landmark'            => ['nullable', 'string', 'max:255'],
            'description'         => ['nullable', 'string', 'max:500'],
            'pieces_count'        => ['nullable', 'integer', 'min:1', 'max:1000'],
            'notes'               => ['nullable', 'string', 'max:500'],
            'merchant_reference'  => ['nullable', 'string', 'max:100'],
            'delivery_fee'        => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'return_fee'          => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'courier_commission'  => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'reason'              => ['required', 'string', 'max:255'],
        ], ['reason.required' => 'اكتب سبب التعديل: يبقى في سجلّ الشحنة وفي الحساب.'], [
            'recipient_phone' => 'هاتف المستلم', 'recipient_phone_alt' => 'الهاتف البديل',
            'delivery_fee' => 'أجرة التوصيل', 'return_fee' => 'أجرة الراجع', 'courier_commission' => 'أجرة المندوب',
        ]);

        $reason = $data['reason'];
        unset($data['reason']);

        $override->handle($shipment, $data, $request->user(), $reason);

        return redirect()->route('shipments.show', $shipment)
            ->with('success', "حُفظ تعديل الشحنة {$shipment->number}، وقُيِّد فرق الأجور في الحساب إن كان.");
    }
}

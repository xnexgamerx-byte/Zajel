<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Shipments\ShipmentTickets;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «الزبون يريد يدفع غير المبلغ؟» — المندوب لا يغيّره: يطلبه من الكول سنتر
 * (docs/plan/30)، وينتظر الجواب في صفحة الشحنة نفسها.
 */
class TicketController extends Controller
{
    public function store(Request $request, Shipment $shipment, ShipmentTickets $tickets): RedirectResponse
    {
        $courier = $request->attributes->get('courier');

        abort_unless($shipment->delivery_courier_id === $courier->id, 404);

        $data = $request->validate([
            'kind'             => ['required', Rule::in(array_keys(ShipmentTicket::KINDS))],
            'requested_amount' => ['required', 'integer', 'min:0', 'max:'.ShipmentTickets::MAX_AMOUNT],
            'reason'           => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'kind.required'   => 'اختر: السعر تغيّر، أم أخذ الزبون جزءاً من الطلب؟',
            'reason.required' => 'اكتب ما قاله الزبون — به تقرّر الكول سنتر.',
        ], [
            'kind' => 'السبب', 'requested_amount' => 'المبلغ الذي سيدفعه الزبون', 'reason' => 'ما قاله الزبون',
        ]);

        $ticket = $tickets->open($shipment, $courier, $request->user(), $data['kind'], (int) $data['requested_amount'], $data['reason']);

        return redirect()->route('courier.shipments.show', $shipment)
            ->with('success', "أُرسل طلبك {$ticket->number} إلى الكول سنتر. لا تسلّم بالمبلغ الجديد حتى يُعتمد.");
    }

    /** حال آخر طلب: الصفحة تسأل كل حين وهو ينتظر، وتُحدَّث حين يأتي الجواب */
    public function status(Request $request, Shipment $shipment): JsonResponse
    {
        $courier = $request->attributes->get('courier');

        abort_unless($shipment->delivery_courier_id === $courier->id, 404);

        $ticket = $shipment->tickets()->where('courier_id', $courier->id)->first();

        return response()->json(['status' => $ticket?->status]);
    }
}

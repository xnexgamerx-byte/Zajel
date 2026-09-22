<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ConfirmAmount;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * تأكيد مبلغ الوصل. مسار واحد لأن الإجراء واحد ولا رجعة فيه.
 */
class ShipmentAmountController extends Controller
{
    public function update(Request $request, Shipment $shipment, ConfirmAmount $confirm): RedirectResponse
    {
        $data = $request->validate([
            'collected_amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'note'             => ['nullable', 'string', 'max:255'],
            'acknowledge'      => ['accepted'],
        ], [
            'acknowledge.accepted' => 'أقرّ بأن المبلغ نهائي قبل التأكيد.',
        ], [
            'collected_amount' => 'المبلغ المحصَّل',
        ]);

        $before = (int) $shipment->collected_amount;

        $confirm->handle($shipment, $data['collected_amount'], $request->user(), $data['note'] ?? null);

        $message = $data['collected_amount'] === $before
            ? "تأكّد مبلغ الوصل {$shipment->number} ولا يُعدَّل بعد الآن."
            : "صُحّح مبلغ الوصل {$shipment->number} من ".number_format($before)
                .' إلى '.number_format($data['collected_amount']).' وقُيّد الفرق في الحساب.';

        return back()->with('success', $message);
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ConfirmAmount;
use App\Http\Controllers\Concerns\ReportsBulkOutcome;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Shipments\BulkSelection;
use App\Services\Shipments\ShipmentStages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * تأكيد مبلغ الوصل — من صفحته بمبلغٍ يُراجَع، أو «اعتماد التسليم» دفعةً من
 * «انتظار موافقة التسليم». الإجراء واحد ولا رجعة فيه: ConfirmAmount.
 */
class ShipmentAmountController extends Controller
{
    use ReportsBulkOutcome;

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

    /**
     * «اعتماد التسليم»: المختارة من «انتظار موافقة التسليم» — أو كل ما في القائمة —
     * تُعتمد بالمبلغ الذي سجّله المندوب ثم يُقفَل. وما يحتاج مبلغاً آخر يُصحَّح من
     * صفحته قبل ذلك.
     *
     * ولا يُعتمد إلّا ما في المرحلة فعلاً: رقمٌ لشحنةٍ خارجها (اعتُمدت، أو دخلت
     * تسوية، أو سُلّمت كما طُلب) يُتخطّى ويُقال لماذا، لا يُقفَل مبلغه.
     */
    public function approve(Request $request, ConfirmAmount $confirm): RedirectResponse
    {
        $data = $request->validate(BulkSelection::rules(), BulkSelection::messages(), ['shipment_ids' => 'الشحنات']);

        $query = BulkSelection::resolve(Shipment::query()->visibleTo($request->user())->orderBy('shipments.id'), $data);

        if (is_string($query)) {
            return back()->withErrors(['shipment_ids' => $query]);
        }

        $waiting = ShipmentStages::awaitingApproval(clone $query)->pluck('shipments.id')->flip();
        $result = ['moved' => [], 'skipped' => []];

        set_time_limit(180);

        foreach ($query->get() as $shipment) {
            if (! $waiting->has($shipment->id)) {
                $result['skipped'][$shipment->number] = 'ليست بانتظار الموافقة';

                continue;
            }

            try {
                $confirm->handle($shipment, (int) $shipment->collected_amount, $request->user(), 'اعتماد التسليم');
                $result['moved'][] = $shipment->number;
            } catch (ValidationException $e) {
                $result['skipped'][$shipment->number] = collect($e->errors())->flatten()->first();
            }
        }

        return $this->outcome($result,
            'اعتُمد تسليم :count بالمبالغ التي سجّلها المندوب، وقُفلت',
            'لم يُعتمد تسليم أيّ شحنة.');
    }
}

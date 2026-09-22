<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeStatusRequest;
use App\Models\Courier;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentStatusController extends Controller
{
    public function __construct(protected ChangeShipmentStatus $changeStatus) {}

    /** تغيير حالة شحنة واحدة من صفحة تفاصيلها. */
    public function update(ChangeStatusRequest $request, Shipment $shipment): RedirectResponse
    {
        $to = ShipmentStatus::from($request->validated('status'));

        $this->changeStatus->handle($shipment, $to, $request->user(), array_filter([
            'courier_id'        => $request->validated('courier_id'),
            'hub_id'            => $request->validated('hub_id'),
            'failure_reason_id' => $request->validated('failure_reason_id'),
            'note'              => $request->validated('note'),
            'collected_amount'  => $request->validated('collected_amount'),
        ], fn ($v) => $v !== null));

        return back()->with('success', "تم تحديث الشحنة {$shipment->number} إلى «{$to->label()}».");
    }

    /**
     * إسناد مجموعة شحنات إلى مندوب دفعة واحدة.
     *
     * هذه العملية اليومية الأكثر تكراراً في شركة توصيل: صباحاً يوزَّع
     * ما في المخزن على المندوبين. شحنة شحنة يعني ساعة عمل كل يوم.
     */
    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['integer'],
            'courier_id'     => ['required', 'integer'],
        ], [], ['shipment_ids' => 'الشحنات', 'courier_id' => 'المندوب']);

        $courier = Courier::delivering()->active()->find($data['courier_id']);

        if (! $courier) {
            return back()->withErrors(['courier_id' => 'المندوب غير موجود أو غير مفعّل أو ليس مندوب توصيل.']);
        }

        $shipments = Shipment::whereIn('id', $data['shipment_ids'])
            ->visibleTo($request->user())
            ->get();

        $moved = 0;
        $skipped = [];

        foreach ($shipments as $shipment) {
            if (! $shipment->status->canMoveTo(ShipmentStatus::OutForDelivery)) {
                $skipped[] = $shipment->number;

                continue;
            }

            $this->changeStatus->handle($shipment, ShipmentStatus::OutForDelivery, $request->user(), [
                'courier_id' => $courier->id,
                'note'       => 'إسناد جماعي',
            ]);

            $moved++;
        }

        $message = "أُسندت {$moved} شحنة إلى {$courier->name}.";

        if ($skipped) {
            $message .= ' تُخطّيت '.count($skipped).' شحنة لأن حالتها لا تسمح: '
                .implode('، ', array_slice($skipped, 0, 5))
                .(count($skipped) > 5 ? ' وغيرها' : '').'.';
        }

        return back()->with('success', $message);
    }

    /** ملخّص نقد المندوبين — من يحمل كم، ومن تجاوز سقفه. */
    public function cashBoard(Request $request)
    {
        $couriers = Courier::query()
            ->where(fn ($q) => $q->where('cash_in_hand', '!=', 0)->orWhere('commission_balance', '!=', 0))
            ->orderByDesc('cash_in_hand')
            ->get();

        $totals = DB::table('couriers')
            ->where('company_id', $request->user()->company_id)
            ->selectRaw('sum(cash_in_hand) as cash, sum(commission_balance) as commission')
            ->first();

        return view('tenant.couriers.cash', [
            'couriers'   => $couriers,
            'total'      => (int) $totals->cash,
            'commission' => (int) $totals->commission,
        ]);
    }
}

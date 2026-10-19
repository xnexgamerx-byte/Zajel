<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\CollectionBatchItem;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Shipment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «الكشف المالي للمندوب» (docs/plan/68 §٨): ما بيده، وما سلّمه، وما عليه من عجز، وما لم
 * يُحاسَب عليه بعد وأقدمه — ووجباته. من هنا تُنشأ له وجبة تحصيل.
 */
class CourierFinanceController extends Controller
{
    public function show(Request $request, Courier $courier, BuildCourierSettlement $build): View
    {
        abort_unless(Courier::query()->visibleTo($request->user())->whereKey($courier->id)->exists(), 404);

        $shipments = Shipment::query()->where('delivery_courier_id', $courier->id);
        $counts = (clone $shipments)->toBase()->selectRaw('count(*) as assigned,
            sum(case when '.Shipment::sqlDelivered().' then 1 else 0 end) as delivered,
            sum(case when status = ? then 1 else 0 end) as returned,
            sum(case when status = ? then 1 else 0 end) as active', [ShipmentStatus::Returned->value, ShipmentStatus::OutForDelivery->value])
            ->first();

        $unsettled = $build->eligibleQuery($courier);
        $settled = CourierSettlement::query()->where('courier_id', $courier->id)
            ->whereIn('status', ['confirmed', 'paid'])->whereNull('cancelled_at');

        // العجز من حركات حسابه: ما كُتب عليه، ناقص ما سدّده وما شُطب
        $shortage = (int) Transaction::forAccount('courier', $courier->id)
            ->whereIn('category', ['shortage', 'shortage_recovered', 'shortage_written_off'])
            ->selectRaw("coalesce(sum(case when direction = 'debit' then amount else -amount end), 0) as v")
            ->value('v');

        $items = CollectionBatchItem::query()->where('courier_id', $courier->id)
            ->with(['batch:id,number,status,approved_at,created_at', 'settlement:id,code,confirmed_at'])
            ->latest('id')->limit(30)->get();

        return view('tenant.profits.courier', [
            'courier'   => $courier,
            'counts'    => $counts,
            'figures'   => [
                'cash_in_hand' => (int) $courier->cash_in_hand,
                'commission'   => (int) $courier->commission_balance,
                'handed'       => (int) (clone $settled)->sum('net_amount'),
                'collected'    => (int) (clone $settled)->sum('cod_total'),
                'commissions'  => (int) (clone $settled)->sum('commission_total'),
                'shortage'     => $shortage,
                'unsettled'    => (int) (clone $unsettled)->sum('collected_amount'),
                'unsettled_n'  => (int) (clone $unsettled)->count(),
            ],
            'oldest'    => (clone $unsettled)->orderBy('delivered_at')->first(['id', 'number', 'delivered_at', 'status_changed_at']),
            'lastSettlement' => (clone $settled)->latest('confirmed_at')->first(['id', 'code', 'confirmed_at', 'net_amount']),
            'items'     => $items,
            'openItem'  => $items->first(fn ($i) => $i->batch?->isOpen()),
        ]);
    }
}

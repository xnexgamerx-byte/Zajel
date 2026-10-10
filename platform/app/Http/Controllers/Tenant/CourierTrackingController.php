<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «تتبّع المناديب» (docs/plan/59): أين كلّ مندوبٍ الآن — من آخر شحنةٍ تحدّثت حالتها على يده:
 * رقمها وحالتها ومنطقتها ومتى، وكم بيده الآن وكم وصّل اليوم. الأحدث حركةً أوّلاً، ومن سكت
 * طويلاً يُرى.
 */
class CourierTrackingController extends Controller
{
    /** ما بيد المندوب الآن: خرج معه ولم يُحسم */
    private const IN_HAND = [ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed];

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $search = trim((string) $request->query('q'));

        // آخر شحنةٍ لكلّ مندوب: الأحدث تغيّراً، وعند التساوي الأحدث رقماً
        $latest = Shipment::query()->visibleTo($user)
            ->whereNotNull('shipments.delivery_courier_id')
            ->whereNotNull('shipments.status_changed_at')
            ->select('shipments.id')
            ->selectRaw('row_number() over (partition by shipments.delivery_courier_id
                order by shipments.status_changed_at desc, shipments.id desc) as rn');

        $rows = Shipment::query()
            ->whereIn('id', DB::query()->fromSub($latest, 'latest')->where('rn', 1)->select('id'))
            ->with(['deliveryCourier:id,name,phone,code,status', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->when($search !== '', fn ($q) => $q->whereHas('deliveryCourier', fn ($c) => $c
                ->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderByDesc('status_changed_at')
            ->get();

        $ids = $rows->pluck('delivery_courier_id')->all();
        $inHand = Shipment::query()->visibleTo($user)->whereIn('shipments.delivery_courier_id', $ids)
            ->whereIn('shipments.status', array_map(fn ($s) => $s->value, self::IN_HAND))
            ->groupBy('shipments.delivery_courier_id')
            ->selectRaw('shipments.delivery_courier_id as courier, count(*) as n')->pluck('n', 'courier');
        $today = Shipment::query()->visibleTo($user)->whereIn('shipments.delivery_courier_id', $ids)
            ->whereNotNull('shipments.delivered_at')
            ->where('shipments.delivered_at', '>=', now('Asia/Baghdad')->startOfDay()->utc())
            ->groupBy('shipments.delivery_courier_id')
            ->selectRaw('shipments.delivery_courier_id as courier, count(*) as n')->pluck('n', 'courier');

        return view('tenant.couriers.tracking', [
            'rows' => $rows,
            'inHand' => $inHand,
            'today' => $today,
            'search' => $search,
        ]);
    }
}

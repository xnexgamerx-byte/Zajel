<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'map' => $this->map($user, $rows),
            'rows' => $rows,
            'inHand' => $inHand,
            'today' => $today,
            'search' => $search,
        ]);
    }

    /**
     * الخريطة (docs/plan/61): كلّ مندوبٍ عند آخر موقعٍ أرسله هاتفه مع حدثٍ في تطبيقه — وإلّا
     * فتقريبياً عند مركز محافظة آخر شحنةٍ له. وتُفتح على محافظة فرع الناظر: الرئيسي على بغداد.
     *
     * @param  Collection<int, Shipment>  $rows
     * @return array{center: array{0: float, 1: float}, zoom: int, points: list<array<string, mixed>>}
     */
    private function map($user, $rows): array
    {
        $ids = $rows->pluck('delivery_courier_id')->filter()->all();
        $last = ShipmentEvent::query()->whereIn('id', ShipmentEvent::query()->whereIn('courier_id', $ids)
            ->whereNotNull('lat')->whereNotNull('lng')->groupBy('courier_id')->selectRaw('max(id)'))
            ->get(['courier_id', 'lat', 'lng', 'created_at'])->keyBy('courier_id');
        $centres = Governorate::whereNotNull('lat')->get(['id', 'lat', 'lng'])->keyBy('id');

        $points = $rows->map(function (Shipment $row) use ($last, $centres) {
            $seen = $last[$row->delivery_courier_id] ?? null;
            $centre = $centres[$row->governorate_id] ?? null;
            [$lat, $lng] = $seen ? [(float) $seen->lat, (float) $seen->lng] : [(float) $centre?->lat, (float) $centre?->lng];

            if (! $lat || ! $lng) {
                return null;
            }

            return [
                'lat' => $lat,
                'lng' => $lng,
                'exact' => $seen !== null,
                'name' => $row->deliveryCourier?->name,
                'note' => $row->number.' · '.$row->statusLabel().' · '.($seen?->created_at ?? $row->status_changed_at)?->diffForHumans(),
                'stale' => ($seen?->created_at ?? $row->status_changed_at)?->lt(now()->subHours(3)) ?? true,
            ];
        })->filter()->values()->all();

        $branch = $user->branch_id ? Branch::find($user->branch_id) : Branch::where('is_main', true)->first();
        $home = $branch?->lat ? [(float) $branch->lat, (float) $branch->lng]
            : (($g = $centres[$branch?->governorate_id ?? 0] ?? $centres->first()) ? [(float) $g->lat, (float) $g->lng] : [33.3152, 44.3661]);

        return ['center' => $home, 'zoom' => 11, 'points' => $points];
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Support\CourierLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «تتبّع المناديب» (docs/plan/59، 62): أين كلّ مندوبٍ الآن — من موقع هاتفه على الخريطة، ومن آخر شحنةٍ تحدّثت حالتها على يده:
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
            ->with(['deliveryCourier:id,name,phone,code,status,last_seen_at', 'governorate:id,name_ar', 'city:id,name_ar'])
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
            'map' => $this->map($user, $rows, $search),
            'rows' => $rows,
            'inHand' => $inHand,
            'today' => $today,
            'search' => $search,
        ]);
    }

    /**
     * الخريطة (docs/plan/62): كلّ مندوب توصيلٍ عند موقع هاتفه — يُرسله ما دامت شاشته مفتوحة، حدّث
     * شيئاً أو لم يحدّث. ومن لم يُرسل هاتفه شيئاً بعد: آخر موقعٍ مع حدثٍ في تطبيقه، وإلّا فتقريبياً عند
     * مركز محافظة آخر شحنةٍ له. وتُفتح على محافظة فرع الناظر: الرئيسي على بغداد.
     *
     * @param  Collection<int, Shipment>  $rows
     * @return array{center: array{0: float, 1: float}, zoom: int, points: list<array<string, mixed>>, live: int}
     */
    private function map($user, $rows, string $search): array
    {
        $byCourier = $rows->keyBy('delivery_courier_id');
        $couriers = Courier::query()->visibleTo($user)->delivering()->active()
            ->when($search !== '', fn ($q) => $q->where(fn ($c) => $c
                ->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->where(fn ($q) => $q->whereNotNull('last_lat')->orWhereIn('id', $byCourier->keys()->all() ?: [0]))
            ->get(['id', 'name', 'last_lat', 'last_lng', 'last_seen_at']);

        $ids = $couriers->pluck('id')->all();
        $last = ShipmentEvent::query()->whereIn('id', ShipmentEvent::query()->whereIn('courier_id', $ids ?: [0])
            ->whereNotNull('lat')->whereNotNull('lng')->groupBy('courier_id')->selectRaw('max(id)'))
            ->get(['courier_id', 'lat', 'lng', 'created_at'])->keyBy('courier_id');
        $centres = Governorate::whereNotNull('lat')->get(['id', 'lat', 'lng'])->keyBy('id');
        $live = 0;

        $points = $couriers->map(function (Courier $courier) use ($byCourier, $last, $centres, &$live) {
            $row = $byCourier[$courier->id] ?? null;

            if ($courier->last_lat && $courier->last_seen_at) {
                $fresh = $courier->last_seen_at->gt(now()->subMinutes(CourierLocation::LIVE_MINUTES));
                $live += $fresh ? 1 : 0;

                return [
                    'lat' => (float) $courier->last_lat,
                    'lng' => (float) $courier->last_lng,
                    'exact' => true,
                    'name' => $courier->name,
                    'note' => ($fresh ? 'موقع هاتفه الآن' : 'آخر موقعٍ من هاتفه '.$courier->last_seen_at->locale('ar')->diffForHumans())
                        .($row ? ' · آخر شحنة '.$row->number.' '.$row->statusLabel() : ''),
                    'stale' => $courier->last_seen_at->lt(now()->subMinutes(CourierLocation::QUIET_MINUTES)),
                ];
            }

            $seen = $last[$courier->id] ?? null;
            $centre = $row ? ($centres[$row->governorate_id] ?? null) : null;
            [$lat, $lng] = $seen ? [(float) $seen->lat, (float) $seen->lng] : [(float) $centre?->lat, (float) $centre?->lng];

            if (! $lat || ! $lng) {
                return null;
            }

            $at = $seen?->created_at ?? $row?->status_changed_at;

            // موقعٌ التقطه هاتفه مع إجراءٍ على شحنة: دقيقٌ لكنه قديم — وإلّا فمحافظة شحنته تقريباً
            return [
                'lat' => $lat,
                'lng' => $lng,
                'exact' => $seen !== null,
                'name' => $courier->name,
                'note' => ($seen ? 'موقع هاتفه مع آخر إجراء' : 'تقريبيّ: هاتفه لم يُرسل موقعه بعد')
                    .($row ? ' · آخر شحنة '.$row->number.' '.$row->statusLabel() : '')
                    .($at ? ' · '.$at->locale('ar')->diffForHumans() : ''),
                'stale' => true,
            ];
        })->filter()->values()->all();

        $branch = $user->branch_id ? Branch::find($user->branch_id) : Branch::where('is_main', true)->first();
        $home = $branch?->lat ? [(float) $branch->lat, (float) $branch->lng]
            : (($g = $centres[$branch?->governorate_id ?? 0] ?? $centres->first()) ? [(float) $g->lat, (float) $g->lng] : [33.3152, 44.3661]);

        return ['center' => $home, 'zoom' => 11, 'points' => $points, 'live' => $live];
    }
}

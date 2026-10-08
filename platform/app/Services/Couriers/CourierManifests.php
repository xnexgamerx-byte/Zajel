<?php

namespace App\Services\Couriers;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «منفيستات مندوبي التوصيل» كما في المعتاد: كشفٌ لكل مندوبٍ عن كل يومٍ خرج فيه بشحنات —
 * لا ما بيده اليوم وحده. يُقرأ من سجلّ الشحنات نفسه: كل إسنادٍ («قيد التوصيل» باسم
 * مندوب) سطرٌ في كشف يومه، فالكشوف القديمة كلّها حاضرة بلا جدولٍ يُملأ.
 *
 * ولكل كشفٍ ما صارت إليه شحناته الآن (docs/plan/38): واصل، وراجعٌ نهائيّ (للتاجر أو في
 * المخزن)، وما زال عند المندوب (قيد التوصيل، إعادة توصيل، مؤجل، للمعالجة، راجعٌ بيده)،
 * ونسبة ما أُنجز.
 */
final class CourierManifests
{
    public const BUCKETS = [
        'delivered'       => 'واصل',
        'returned'        => 'راجع للتاجر',
        'return_in_store' => 'راجع في المخزن',
        'out'             => 'قيد التوصيل',
        'redelivery'      => 'إعادة توصيل',
        'postponed'       => 'مؤجل',
        'to_process'      => 'للمعالجة',
        'return_with_him' => 'راجع عند المندوب',
        'other'           => 'أخرى',
    ];

    /**
     * الكشوف صفحةً صفحة، الأحدث أوّلاً.
     *
     * @param  array{q?: string, courier_id?: ?int, from?: ?string, to?: ?string}  $filters
     */
    public function page(User $viewer, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $base = $this->assignments($viewer, $filters);

        // عدد الكشوف (مندوبٌ × يوم) من جدولٍ مشتقّ: SQLite لا يعدّ عمودين مميَّزين معاً
        $total = (int) \Illuminate\Support\Facades\DB::query()->fromSub((clone $base)->toBase()
            ->selectRaw('shipment_events.courier_id, date(shipment_events.created_at) as day')
            ->groupBy('shipment_events.courier_id', 'day'), 'manifests')->count();

        $page = LengthAwarePaginator::resolveCurrentPage();

        $rows = (clone $base)->toBase()
            ->selectRaw('shipment_events.courier_id, date(shipment_events.created_at) as day,
                         count(distinct shipment_events.shipment_id) as shipments,
                         min(shipment_events.created_at) as first_at')
            ->groupBy('shipment_events.courier_id', 'day')
            ->orderByDesc('day')
            ->orderBy('shipment_events.courier_id')
            ->forPage($page, $perPage)
            ->get();

        $couriers = Courier::withTrashed()->whereIn('id', $rows->pluck('courier_id')->unique())
            ->get(['id', 'name', 'code', 'phone', 'deleted_at'])->keyBy('id');

        $breakdown = $this->breakdown($viewer, $rows);

        $items = $rows->map(function ($row) use ($couriers, $breakdown) {
            $counts = $breakdown->get($row->courier_id.'|'.$row->day, collect());
            $done = (int) ($counts['delivered'] ?? 0) + (int) ($counts['returned'] ?? 0) + (int) ($counts['return_in_store'] ?? 0);

            return (object) [
                'courier'   => $couriers->get($row->courier_id),
                'courier_id' => (int) $row->courier_id,
                'day'       => Carbon::parse($row->day),
                'code'      => 'M'.$row->courier_id.'-'.str_replace('-', '', $row->day),
                'shipments' => (int) $row->shipments,
                'counts'    => $counts,
                'done'      => $done,
                'percent'   => $row->shipments > 0 ? (int) round($done / $row->shipments * 100) : 0,
            ];
        });

        return new LengthAwarePaginator($items, (int) $total, $perPage, $page, [
            'path'  => LengthAwarePaginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * شحنات كشف مندوبٍ في يومٍ بعينه، بما صارت إليه الآن.
     *
     * @return Collection<int, Shipment>
     */
    public function shipmentsOf(User $viewer, Courier $courier, Carbon $day): Collection
    {
        $ids = ShipmentEvent::query()
            ->where('to_status', ShipmentStatus::OutForDelivery->value)
            ->where('courier_id', $courier->id)
            ->whereOnDate('created_at', $day)
            ->distinct()
            ->pluck('shipment_id');

        return Shipment::query()
            ->visibleTo($viewer)
            ->whereIn('shipments.id', $ids->all() ?: [0])
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar', 'deliveryCourier:id,name'])
            ->orderBy('governorate_id')->orderBy('city_id')->orderBy('id')
            ->get();
    }

    /** إلى أيّ خانةٍ صارت الشحنة من كشف مندوبها */
    public static function bucketOf(Shipment $shipment, int $courierId): string
    {
        return static::bucket($shipment->status->value, $shipment->delivered_at !== null,
            $shipment->return_received_at !== null, $shipment->redelivery_at !== null, (int) $shipment->delivery_courier_id === $courierId);
    }

    protected static function bucket(string $status, bool $delivered, bool $received, bool $redelivery, bool $stillHis): string
    {
        return match (true) {
            $delivered                                       => 'delivered',
            $status === ShipmentStatus::Returned->value      => 'returned',
            $status === ShipmentStatus::Returning->value && $received => 'return_in_store',
            ! $stillHis                                      => 'other',
            $status === ShipmentStatus::OutForDelivery->value => $redelivery ? 'redelivery' : 'out',
            $status === ShipmentStatus::Postponed->value     => 'postponed',
            $status === ShipmentStatus::FailedAttempt->value => 'to_process',
            $status === ShipmentStatus::Returning->value     => 'return_with_him',
            default                                          => 'other',
        };
    }

    /** إسنادات الشحنات للمناديب: كل حدث «قيد التوصيل» باسم مندوب، لمن يراه */
    protected function assignments(User $viewer, array $filters): Builder
    {
        $couriers = Courier::withTrashed()->visibleTo($viewer)->select('id');
        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $digits = \App\Support\Phone::latinDigits($q);
            $couriers->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')
                ->orWhere('code', 'like', $q.'%')
                ->orWhere('phone', 'like', '%'.$digits.'%'));
        }

        return ShipmentEvent::query()
            ->where('shipment_events.to_status', ShipmentStatus::OutForDelivery->value)
            ->whereNotNull('shipment_events.courier_id')
            ->whereIn('shipment_events.courier_id', $couriers)
            ->when($filters['courier_id'] ?? null, fn ($w, $id) => $w->where('shipment_events.courier_id', $id))
            ->when($filters['from'] ?? null, fn ($w, $from) => $w->whereFromDate('shipment_events.created_at', $from))
            ->when($filters['to'] ?? null, fn ($w, $to) => $w->whereUntilDate('shipment_events.created_at', $to));
    }

    /**
     * ما صارت إليه شحنات كل كشفٍ في الصفحة، عدّاً بالخانة.
     *
     * @return Collection<string, Collection<string, int>>
     */
    protected function breakdown(User $viewer, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $days = $rows->pluck('day');

        $lines = ShipmentEvent::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_events.shipment_id')
            ->where('shipment_events.to_status', ShipmentStatus::OutForDelivery->value)
            ->whereIn('shipment_events.courier_id', $rows->pluck('courier_id')->unique()->all())
            ->whereFromDate('shipment_events.created_at', $days->min())
            ->whereUntilDate('shipment_events.created_at', $days->max())
            ->whereIn('shipments.id', Shipment::query()->visibleTo($viewer)->select('shipments.id'))
            ->toBase()
            ->selectRaw('shipment_events.courier_id, date(shipment_events.created_at) as day, shipments.id as shipment_id,
                         shipments.status, shipments.delivered_at, shipments.return_received_at, shipments.redelivery_at,
                         shipments.delivery_courier_id')
            ->distinct()
            ->get();

        $wanted = $rows->map(fn ($row) => $row->courier_id.'|'.$row->day)->flip();

        return $lines
            ->filter(fn ($line) => $wanted->has($line->courier_id.'|'.$line->day))
            ->unique(fn ($line) => $line->courier_id.'|'.$line->day.'|'.$line->shipment_id)
            ->groupBy(fn ($line) => $line->courier_id.'|'.$line->day)
            ->map(fn (Collection $lines) => $lines->countBy(fn ($line) => static::bucket(
                $line->status, $line->delivered_at !== null, $line->return_received_at !== null,
                $line->redelivery_at !== null, (int) $line->delivery_courier_id === (int) $line->courier_id,
            )));
    }
}

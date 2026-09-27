<?php

namespace App\Services\Dashboard;

use App\Enums\ShipmentStatus;
use App\Models\Manifest;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * البطاقات السبع في رئيسية النظام المعتاد، كلٌّ منها سؤالٌ يُسأل كل صباح:
 *
 * ١ وصولاتٌ متكرّرة · ٢ عند المندوب منذ ٧٢ ساعة · ٣ واصلٌ إجباريّ خلال ٢٤ ساعة ·
 * ٤ مبالغُ لم تُسدَّد بأيام تأخيرها · ٥ بين فرعين منذ أكثر من ٢٤ ساعة ·
 * ٦ رواجع أُرسلت إلى فرعٍ ولم تُستلم · ٧ كشوف النقل المرسلة خلال ٢٤ ساعة.
 *
 * كل بطاقةٍ لمن يفتح ما خلفها وحده، وبصفوفٍ قليلة وعددها الكلّيّ — الرئيسية
 * تنبّه، والشاشة خلفها تُعالج.
 */
final class HomeAlerts
{
    public const ROWS = 6;

    /**
     * @return list<array{key: string, title: string, hint: string, total: int, link: ?string,
     *     rows: Collection<int, array{cells: list<?string>, href: ?string, late: bool}>}>
     */
    public function for(User $user): array
    {
        $cards = [];

        if ($user->can('control.duplicates')) {
            $cards[] = $this->duplicates($user);
        }

        if ($user->can('shipments.view')) {
            $cards[] = $this->withCourierTooLong($user);
        }

        if ($user->can('control.force')) {
            $cards[] = $this->forcedToday($user);
        }

        if ($user->can('money.view')) {
            $cards[] = $this->unpaidAmounts($user);
        }

        if ($user->can('transport.manage')) {
            $cards[] = $this->betweenBranchesTooLong($user);
            $cards[] = $this->returnsNotReceived($user);
            $cards[] = $this->manifestsSent();
        }

        return $cards;
    }

    private function shipments(User $user): Builder
    {
        return Shipment::query()->visibleTo($user);
    }

    /** ١ — وصولاتٌ متكرّرة لم تُحسم: المتجر · رقم الوصل · المحافظة */
    private function duplicates(User $user): array
    {
        $query = fn () => $this->shipments($user)
            ->whereNotNull('shipments.duplicate_of_id')
            ->whereNull('shipments.duplicate_cleared_at')
            ->where('shipments.status', '!=', ShipmentStatus::Cancelled->value);

        return [
            'key'   => 'duplicates',
            'title' => 'إيصالات متكرّرة',
            'hint'  => 'التاجر نفسه والمستلم نفسه والمبلغ نفسه خلال أيام — تُحسم قبل أن تُوزَّع مرّتين',
            'total' => $query()->count(),
            'rows'  => $query()->with(['merchant:id,business_name', 'governorate:id,name_ar'])
                ->latest('shipments.id')->limit(self::ROWS)->get()
                ->map(fn (Shipment $s) => $this->row([$s->merchant?->business_name, $s->number, $s->governorate?->name_ar],
                    route('shipments.show', $s))),
            'link'  => route('control.duplicates'),
        ];
    }

    /** ٢ — عند المندوب منذ أكثر من ٧٢ ساعة: المندوب · المندوب الفرعيّ · عدد الشحنات */
    private function withCourierTooLong(User $user): array
    {
        $rows = $this->shipments($user)
            ->where('shipments.status', ShipmentStatus::OutForDelivery->value)
            ->where('shipments.status_changed_at', '<', now()->subHours(72))
            ->join('couriers', 'couriers.id', '=', 'shipments.delivery_courier_id')
            ->leftJoin('couriers as parents', 'parents.id', '=', 'couriers.parent_id')
            ->selectRaw('couriers.id as courier_id, couriers.name as name, parents.name as parent_name, count(*) as total')
            ->groupBy('couriers.id', 'couriers.name', 'parents.name')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        return [
            'key'   => 'with_courier',
            'title' => 'طلبات عند المندوب منذ ٧٢ ساعة',
            'hint'  => 'خرجت معه ولم تُحسم منذ ثلاثة أيام',
            'total' => (int) $rows->sum('total'),
            'rows'  => $rows->take(self::ROWS)->map(fn ($r) => $this->row(
                // الشحنة باسم الفرعيّ، والمسؤول عنها أبوه
                [$r->parent_name ?? $r->name, $r->parent_name ? $r->name : '—', \App\Support\Arabic::shipments((int) $r->total)],
                route('shipments.index', ['stage' => 'out_for_delivery', 'courier_id' => $r->courier_id,
                    'stage_to' => now()->subDays(3)->toDateString()]),
            )),
            'link'  => route('shipments.index', ['stage' => 'out_for_delivery', 'stage_to' => now()->subDays(3)->toDateString()]),
        ];
    }

    /** ٣ — واصلٌ إجباريّ خلال آخر ٢٤ ساعة: المندوب · رقم الوصل · السبب */
    private function forcedToday(User $user): array
    {
        $query = fn () => $this->shipments($user)
            ->where('shipments.is_forced', true)
            ->where('shipments.status_changed_at', '>=', now()->subDay());

        return [
            'key'   => 'forced',
            'title' => 'واصل إجباري خلال آخر ٢٤ ساعة',
            'hint'  => 'حالةٌ غُيّرت خارج مسارها، بسببٍ مكتوب',
            'total' => $query()->count(),
            'rows'  => $query()->with('deliveryCourier:id,name')->latest('shipments.status_changed_at')->limit(self::ROWS)->get()
                ->map(fn (Shipment $s) => $this->row([$s->deliveryCourier?->name ?? '—', $s->number, $s->forced_reason],
                    route('shipments.show', $s))),
            'link'  => route('control.forced'),
        ];
    }

    /** ٤ — مبالغ وصولاتٍ حصّلها المندوب ولم يسدّدها: المندوب · المبلغ · أيام التأخير */
    private function unpaidAmounts(User $user): array
    {
        $rows = $this->shipments($user)
            ->whereNotNull('shipments.delivered_at')
            ->whereNull('shipments.courier_settled_at')
            ->where('shipments.collected_amount', '>', 0)
            ->join('couriers', 'couriers.id', '=', 'shipments.delivery_courier_id')
            ->selectRaw('couriers.name as name, sum(shipments.collected_amount) as amount, min(shipments.delivered_at) as oldest')
            ->groupBy('couriers.id', 'couriers.name')
            ->orderBy('oldest')
            ->toBase()
            ->get();

        return [
            'key'   => 'unpaid',
            'title' => 'مبالغ وصولات لم يتمّ تسديدها',
            'hint'  => 'حصّلها المندوب ولم يسلّمها، أقدمها أوّلاً',
            'total' => $rows->count(),
            'rows'  => $rows->take(self::ROWS)->map(function ($r) {
                $days = (int) Carbon::parse($r->oldest)->startOfDay()->diffInDays(today());

                return $this->row([$r->name, number_format((int) $r->amount).' د.ع',
                    $days > 0 ? \App\Support\Arabic::days($days) : 'اليوم'], late: $days >= 3);
            }),
            'link'  => route('couriers.cash'),
        ];
    }

    /** ٥ — بين فرعين منذ أكثر من ٢٤ ساعة: الفرع المرسَل إليه · العدد */
    private function betweenBranchesTooLong(User $user): array
    {
        $rows = $this->shipments($user)
            ->where('shipments.status', ShipmentStatus::InTransit->value)
            ->where('shipments.status_changed_at', '<', now()->subDay())
            ->leftJoin('bags', 'bags.id', '=', 'shipments.current_bag_id')
            ->leftJoin('hubs', 'hubs.id', '=', 'bags.to_hub_id')
            ->selectRaw("coalesce(hubs.name, 'بلا كيس') as name, count(*) as total")
            ->groupBy('hubs.name')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        return [
            'key'   => 'in_transit',
            'title' => 'شحنات بين فرعين منذ أكثر من ٢٤ ساعة',
            'hint'  => 'في كيسٍ على كشف نقل ولم تصل',
            'total' => (int) $rows->sum('total'),
            'rows'  => $rows->take(self::ROWS)->map(fn ($r) => $this->row([$r->name, \App\Support\Arabic::shipments((int) $r->total)])),
            'link'  => route('shipments.index', ['stage' => 'in_transit', 'stage_to' => now()->subDay()->toDateString()]),
        ];
    }

    /** ٦ — رواجع أُرسلت إلى فرع تاجرها ولم تُستلم: بالطريق إلى · العدد */
    private function returnsNotReceived(User $user): array
    {
        $rows = $this->shipments($user)
            ->where('shipments.status', ShipmentStatus::Returning->value)
            ->whereNotNull('shipments.return_received_at')
            ->whereNotNull('shipments.current_bag_id')
            ->join('bags', 'bags.id', '=', 'shipments.current_bag_id')
            ->join('hubs', 'hubs.id', '=', 'bags.to_hub_id')
            ->selectRaw('hubs.name as name, count(*) as total')
            ->groupBy('hubs.name')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        return [
            'key'   => 'returns_away',
            'title' => 'شحنات راجعة أُرسلت إلى الفرع ولم تُستلم',
            'hint'  => 'كُيِّست إلى فرع تاجرها ولم يفتح كيسها بعد',
            'total' => (int) $rows->sum('total'),
            'rows'  => $rows->take(self::ROWS)->map(fn ($r) => $this->row([$r->name, \App\Support\Arabic::shipments((int) $r->total)])),
            'link'  => route('shipments.index', ['stage' => 'returns_on_the_way']),
        ];
    }

    /** ٧ — كشوف النقل المرسلة خلال آخر ٢٤ ساعة: الكشف · وقته · الفرع المرسَل إليه · العدد */
    private function manifestsSent(): array
    {
        $query = fn () => Manifest::query()
            ->whereNotNull('departed_at')
            ->where('departed_at', '>=', now()->subDay());

        return [
            'key'   => 'manifests',
            'title' => 'كشوف النقل المرسلة — آخر ٢٤ ساعة',
            'hint'  => 'ما خرج إلى الفروع، وكم يحمل',
            'total' => $query()->count(),
            'rows'  => $query()->with('toHub:id,name')->latest('departed_at')->limit(self::ROWS)->get()
                ->map(fn (Manifest $m) => $this->row([$m->code, $m->departed_at->format('H:i'), $m->toHub?->name,
                    \App\Support\Arabic::shipments((int) $m->shipments_count)], route('manifests.show', $m))),
            'link'  => route('manifests.archive'),
        ];
    }

    /** @param  list<?string>  $cells */
    private function row(array $cells, ?string $href = null, bool $late = false): array
    {
        return ['cells' => $cells, 'href' => $href, 'late' => $late];
    }
}

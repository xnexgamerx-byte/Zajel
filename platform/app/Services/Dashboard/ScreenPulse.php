<?php

namespace App\Services\Dashboard;

use App\Enums\ShipmentStatus;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use App\Models\User;
use App\Support\Arabic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * نبض الشاشة (docs/plan/101): في «بطاقات فاتحة» و«زجاجيّ» فوق كل شاشةٍ شريطٌ بعدّادات قسمها، ورسمٌ
 * لأيّامه الأربعة عشر، ورسمةٌ له — الشحنات، والتوصيل، والراجع، والمال، والفروع، والطلبات، والمتابعة.
 * لما يراه الموظّف وحده (visibleTo)، والمال لمن يرى المال. ويُحفظ دقيقةً لكل موظّفٍ وقسم: لا يُعاد
 * حسابه مع كل صفحة.
 */
final class ScreenPulse
{
    /** الأقسام التي لها نبض: مفتاح القائمة في StaffNavigation */
    public const MENUS = ['shipments', 'delivery', 'returns', 'money', 'position', 'branches', 'requests', 'followup'];

    private const DAYS = 14;

    /** شاشاتٌ خارج القوائم (المناديب، والتجّار…): قسمها من اسم مسارها */
    private const ROUTE_MENUS = [
        'couriers.' => 'delivery', 'merchants.' => 'shipments', 'shipments.' => 'shipments', 'pickups.' => 'requests',
        'returns.' => 'returns', 'settlements.' => 'money', 'cash.' => 'money', 'branch-accounts.' => 'branches', 'branches.' => 'branches',
    ];

    /** قسم الشاشة: القائمة الحاليّة، وإلّا من اسم مسارها */
    public static function menuFor(?string $menu, ?string $route): ?string
    {
        // قائمةٌ لها نبض تكفي؛ وشاشةٌ في قائمةٍ بلا نبض (الإعدادات) أو خارج القوائم قسمها من مسارها
        if (in_array($menu, self::MENUS, true) || $route === null) {
            return $menu;
        }

        foreach (self::ROUTE_MENUS as $prefix => $key) {
            if (str_starts_with($route, $prefix)) {
                return $key;
            }
        }

        return null;
    }

    public function __construct(private readonly User $user) {}

    /** @return array{art: string, title: string, counters: list<array{label: string, value: string, unit?: string, tone?: string}>, series: list<array{label: string, count: int, today: bool}>, series_label: string}|null */
    public function for(string $menu): ?array
    {
        if (! in_array($menu, self::MENUS, true) || ! $this->user->can('shipments.view')) {
            return null;
        }

        $money = in_array($menu, ['money', 'position'], true);
        if ($money && ! $this->user->can('money.view')) {
            return null;
        }

        return Cache::remember("pulse:{$this->user->company_id}:{$this->user->id}:{$menu}", 60, fn () => match ($menu) {
            'shipments' => $this->shipmentsPulse(),
            'delivery' => $this->deliveryPulse(),
            'returns' => $this->returnsPulse(),
            'money', 'position' => $this->moneyPulse(),
            'branches' => $this->branchesPulse(),
            'requests' => $this->requestsPulse(),
            'followup' => $this->followupPulse(),
        });
    }

    private function shipments()
    {
        return Shipment::query()->visibleTo($this->user);
    }

    private function count(ShipmentStatus ...$statuses): int
    {
        return $this->shipments()->whereIn('status', array_map(fn ($s) => $s->value, $statuses))->count();
    }

    private static function n(int|float $n): string
    {
        return number_format($n);
    }

    /**
     * أربعة عشر يوماً، أقدمها أوّلاً: عدد ما يقع في كلّ يومٍ من $column، أو مجموع $sum.
     *
     * @return list<array{label: string, count: int, today: bool}>
     */
    private function series($query, string $column, ?string $sum = null): array
    {
        $from = today()->subDays(self::DAYS - 1);
        $perDay = $query->where($column, '>=', $from)
            ->selectRaw("date($column) as day, ".($sum ? "sum($sum)" : 'count(*)').' as c')
            ->groupBy('day')->toBase()->pluck('c', 'day');

        return collect(range(self::DAYS - 1, 0))->map(function (int $ago) use ($perDay) {
            $day = today()->subDays($ago);

            return ['label' => (string) $day->day, 'count' => (int) ($perDay[$day->toDateString()] ?? 0), 'today' => $ago === 0];
        })->all();
    }

    private function shipmentsPulse(): array
    {
        return [
            'art' => 'boxes',
            'title' => 'نبض الشحنات',
            'counters' => [
                ['label' => 'مفتوحة', 'value' => self::n($this->shipments()->whereIn('status', ShipmentStatus::openValues())->count())],
                ['label' => 'جديدة اليوم', 'value' => self::n($this->shipments()->whereOnDate('created_at', today())->count()), 'tone' => 'accent'],
                ['label' => 'مع المناديب', 'value' => self::n($this->count(ShipmentStatus::OutForDelivery))],
                ['label' => 'متعثّرة', 'value' => self::n($this->count(ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed)), 'tone' => 'warn'],
            ],
            'series' => $this->series($this->shipments(), 'created_at'),
            'series_label' => 'الشحنات الجديدة كل يوم',
        ];
    }

    private function deliveryPulse(): array
    {
        $from = now()->subDays(30);
        $delivered = $this->shipments()->where('delivered_at', '>=', $from)->count();
        $closed = $delivered + $this->shipments()->where('status_changed_at', '>=', $from)->whereIn('status', [
            ShipmentStatus::Returning->value, ShipmentStatus::Returned->value, ShipmentStatus::FailedAttempt->value,
        ])->count();

        return [
            'art' => 'truck',
            'title' => 'نبض التوصيل',
            'counters' => [
                ['label' => 'مع المناديب', 'value' => self::n($this->count(ShipmentStatus::OutForDelivery))],
                ['label' => 'سُلّمت اليوم', 'value' => self::n($this->shipments()->whereOnDate('delivered_at', today())->count()), 'tone' => 'accent'],
                ['label' => 'مناديب على الطريق', 'value' => self::n($this->shipments()->where('status', ShipmentStatus::OutForDelivery->value)
                    ->whereNotNull('delivery_courier_id')->distinct()->count('delivery_courier_id'))],
                ['label' => 'نسبة التسليم (٣٠ يوماً)', 'value' => $closed ? round(100 * $delivered / $closed).'%' : '—', 'tone' => 'ok'],
            ],
            'series' => $this->series($this->shipments(), 'delivered_at'),
            'series_label' => 'المسلَّمة كل يوم',
        ];
    }

    private function returnsPulse(): array
    {
        return [
            'art' => 'undo',
            'title' => 'نبض الراجع',
            'counters' => [
                ['label' => 'في طريق الرجوع', 'value' => self::n($this->count(ShipmentStatus::Returning)), 'tone' => 'warn'],
                ['label' => 'رجعت هذا الأسبوع', 'value' => self::n($this->shipments()->where('status', ShipmentStatus::Returned->value)
                    ->where('status_changed_at', '>=', now()->startOfWeek())->count())],
                ['label' => 'تعثّرت اليوم', 'value' => self::n($this->shipments()->where('status', ShipmentStatus::FailedAttempt->value)
                    ->whereOnDate('status_changed_at', today())->count()), 'tone' => 'accent'],
            ],
            'series' => $this->series($this->shipments()->whereIn('status', [ShipmentStatus::Returning->value, ShipmentStatus::Returned->value]), 'status_changed_at'),
            'series_label' => 'ما صار راجعاً كل يوم',
        ];
    }

    private function moneyPulse(): array
    {
        $couriers = Courier::withTrashed()->visibleTo($this->user)->toBase()->selectRaw('sum(cash_in_hand) as cash')->first();

        return [
            'art' => 'wallet',
            'title' => 'نبض المال',
            'counters' => [
                ['label' => 'نقدٌ بيد المندوبين', 'value' => self::n((int) ($couriers->cash ?? 0)), 'unit' => 'د.ع', 'tone' => 'warn'],
                ['label' => 'مستحقّ للتجّار', 'value' => self::n((int) Merchant::visibleTo($this->user)->where('balance', '>', 0)->sum('balance')), 'unit' => 'د.ع'],
                ['label' => 'لم تُحصَّل بعد', 'value' => self::n((int) $this->shipments()->whereIn('status', ShipmentStatus::openValues())->sum('cod_amount')), 'unit' => 'د.ع', 'tone' => 'accent'],
            ],
            'series' => $this->series($this->shipments(), 'delivered_at', 'collected_amount'),
            'series_label' => 'المحصَّل كل يوم (د.ع)',
        ];
    }

    private function branchesPulse(): array
    {
        return [
            'art' => 'building',
            'title' => 'نبض الفروع',
            'counters' => [
                ['label' => 'الفروع', 'value' => self::n(Branch::query()->count())],
                ['label' => 'بين الفروع', 'value' => self::n($this->count(ShipmentStatus::InTransit)), 'tone' => 'accent'],
                ['label' => 'في المخازن', 'value' => self::n($this->count(ShipmentStatus::AtHub))],
            ],
            'series' => $this->series($this->shipments(), 'created_at'),
            'series_label' => 'الشحنات الجديدة كل يوم',
        ];
    }

    private function requestsPulse(): array
    {
        $pickups = fn () => PickupRequest::visibleTo($this->user);

        return [
            'art' => 'inbound',
            'title' => 'نبض الطلبات',
            'counters' => [
                ['label' => 'استلامٌ ينتظر', 'value' => self::n($pickups()->where('status', 'pending')->count()), 'tone' => 'warn'],
                ['label' => 'أُسند لمندوب', 'value' => self::n($pickups()->whereIn('status', ['assigned', 'in_progress'])->count())],
                ['label' => 'اكتمل اليوم', 'value' => self::n($pickups()->where('status', 'completed')->whereOnDate('completed_at', today())->count()), 'tone' => 'ok'],
            ],
            'series' => $this->series($pickups(), 'created_at'),
            'series_label' => 'طلبات الاستلام كل يوم',
        ];
    }

    private function followupPulse(): array
    {
        $stale = (int) config('zajel.stale_shipment_days', 5);

        return [
            'art' => 'review',
            'title' => 'نبض المتابعة',
            'counters' => [
                ['label' => 'متعثّرة', 'value' => self::n($this->count(ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed)), 'tone' => 'warn'],
                ['label' => 'طلبات المناديب', 'value' => self::n($this->user->can('tickets.handle') ? ShipmentTicket::visibleTo($this->user)->open()->count() : 0)],
                ['label' => 'راكدة منذ '.Arabic::days($stale), 'value' => self::n($this->shipments()->whereIn('status', ShipmentStatus::openValues())
                    ->where('status_changed_at', '<', Carbon::now()->subDays($stale))->count()), 'tone' => 'accent'],
            ],
            'series' => $this->series($this->shipments()->where('status', ShipmentStatus::FailedAttempt->value), 'status_changed_at'),
            'series_label' => 'التعثّر كل يوم',
        ];
    }
}

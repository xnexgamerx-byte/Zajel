<?php

namespace App\Services\Operations;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use App\Support\DeliveryDeadline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «التنبيهات التشغيلية» (docs/plan/39): ثلاث قوائم يظهر فيها الشيء بعد مرور آخر موعدٍ
 * للتوصيل عليه (٢٤ ساعة ما لم تغيّره الشركة):
 *
 * ١ شحناتٌ تجاوزت موعد توصيلها — مرتّبةً بأولويّتها: أيّام التأخير بعد الموعد وأهمّيتها.
 * ٢ شحناتٌ لم يُمسح باركودها عند نقطة انتقال — أين يُفترض أنها الآن، ومن المسؤول عنها.
 * ٣ مبالغ حصّلها المندوب ولم يسلّمها — للمحاسبة، حسب الفرع ثمّ المندوب.
 *
 * كلّه مقيَّدٌ بما يراه الموظّف (visibleTo): الفرع يرى فرعه.
 */
final class OperationalAlerts
{
    /** مبلغٌ يجعل الشحنة أهمّ: مالٌ أكبر في الطريق */
    public const BIG_AMOUNT = 100_000;

    /** أيّام التأخير بعد الموعد تُحسب نقاطاً حتى ثلاثة */
    public const MAX_LATE_POINTS = 3;

    /** نقاطٌ فأكثر = عاجلة، ونقطةٌ فأكثر = مرتفعة */
    public const URGENT = 3;

    /** نقاط الانتقال التي يُنتظر عندها مسحٌ ولم يأتِ */
    public const CHECKPOINTS = [
        'pickup'         => ['لم يستلمها مندوب الاستلام', 'أُدخلت ولم يُمسح باركودها عند الاستلام من التاجر'],
        'to_hub'         => ['استلمها المندوب ولم تدخل المخزن', 'مُسحت عند التاجر ولم تُمسح عند الدخول إلى المخزن'],
        'transit'        => ['بين فرعين ولم تُستلم', 'خرجت على كشف نقل ولم تُمسح في الفرع المرسَل إليه'],
        'return_courier' => ['راجع عند المندوب ولم يُسلَّم', 'قرّر الإرجاع ولم تُمسح عند استلامها منه في المخزن'],
        'return_bag'     => ['راجع أُرسل إلى فرع تاجره ولم يُستلم', 'كُيِّس إلى فرع التاجر ولم يُمسح عند وصوله'],
    ];

    public function hours(): int
    {
        return DeliveryDeadline::hours();
    }

    /** الحدّ: ما قبله قد مرّ عليه الموعد */
    public function threshold(): Carbon
    {
        return now()->subHours($this->hours());
    }

    /* ─────────────── ١ تجاوزت موعد التوصيل ─────────────── */

    /**
     * في طريقها إلى المستلم ومرّ على استلامها من التاجر أكثر من الموعد.
     * والمؤجّلة بطلب الزبون إلى يومٍ لم يأتِ ليست تأخيراً منّا.
     */
    public function overdue(User $user): Builder
    {
        return Shipment::query()->visibleTo($user)
            ->whereIn('shipments.status', DeliveryDeadline::onTheWayValues())
            ->whereNotNull('shipments.picked_up_at')
            ->where('shipments.picked_up_at', '<', $this->threshold())
            ->where(fn ($q) => $q->where('shipments.status', '!=', ShipmentStatus::Postponed->value)
                ->orWhereNull('shipments.scheduled_at')
                ->orWhere('shipments.scheduled_at', '<', today()->addDay()));
    }

    /** المؤجّلة إلى موعدٍ قادم: تُذكر عدداً ولا تُحسب تأخيراً */
    public function postponedAhead(User $user): int
    {
        return Shipment::query()->visibleTo($user)
            ->where('shipments.status', ShipmentStatus::Postponed->value)
            ->whereNotNull('shipments.picked_up_at')
            ->where('shipments.picked_up_at', '<', $this->threshold())
            ->where('shipments.scheduled_at', '>=', today()->addDay())
            ->count();
    }

    /**
     * القائمة بأولويّتها: لكلّ يومٍ بعد الموعد نقطة (حتى ثلاث)، ولكلّ علامة أهمّيةٍ نقطة —
     * تاجرٌ مميّز، وتاجرٌ سأل عنها في محادثةٍ مفتوحة، ومحاولتان فاشلتان فأكثر، ومبلغٌ كبير.
     * وفي النقاط نفسها: الأطول تأخيراً أوّلاً.
     */
    public function overdueRanked(User $user, string $sort = 'priority'): Builder
    {
        [$lateSql, $lateBindings] = $this->latePointsSql();
        $flags = $this->importanceSql();

        $query = $this->overdue($user)
            ->leftJoin('merchants', 'merchants.id', '=', 'shipments.merchant_id')
            ->select('shipments.*')
            ->selectRaw("{$lateSql} as late_points", $lateBindings);

        foreach ($flags as $name => $sql) {
            $query->selectRaw("{$sql} as {$name}");
        }

        if ($sort === 'priority') {
            $query->orderByRaw('('.$lateSql.' + '.implode(' + ', $flags).') desc', $lateBindings);
        }

        return $query->orderBy('shipments.picked_up_at')->orderBy('shipments.id');
    }

    /** @return array{0: string, 1: list<Carbon>} */
    private function latePointsSql(): array
    {
        $due = $this->threshold();
        $cases = [];
        $bindings = [];

        for ($days = self::MAX_LATE_POINTS; $days >= 1; $days--) {
            $cases[] = "when shipments.picked_up_at < ? then {$days}";
            $bindings[] = $due->copy()->subDays($days);
        }

        return ['(case '.implode(' ', $cases).' else 0 end)', $bindings];
    }

    /** @return array<string, string> علامات الأهمّية: كلٌّ 0 أو 1 */
    private function importanceSql(): array
    {
        return [
            'is_vip'     => '(case when merchants.is_vip = true then 1 else 0 end)',
            'asked'      => "(case when exists (select 1 from conversations where conversations.shipment_id = shipments.id and conversations.status = 'open') then 1 else 0 end)",
            'many_tries' => '(case when shipments.attempts_count >= 2 then 1 else 0 end)',
            'big_amount' => '(case when shipments.cod_amount >= '.self::BIG_AMOUNT.' then 1 else 0 end)',
        ];
    }

    /** أولويّة صفٍّ من overdueRanked: [النقاط، الدرجة، النبرة، الأسباب] */
    public static function priority(Shipment $row): array
    {
        $reasons = array_values(array_filter([
            (int) $row->late_points > 0 ? 'متأخرة '.\App\Support\Arabic::days((int) $row->late_points).' بعد الموعد' : null,
            (int) $row->is_vip ? 'تاجر مميّز' : null,
            (int) $row->asked ? 'التاجر سأل عنها' : null,
            (int) $row->many_tries ? 'محاولات فاشلة: '.(int) $row->attempts_count : null,
            (int) $row->big_amount ? 'مبلغ كبير' : null,
        ]));

        $points = (int) $row->late_points + (int) $row->is_vip + (int) $row->asked + (int) $row->many_tries + (int) $row->big_amount;

        return match (true) {
            $points >= self::URGENT => [$points, 'عاجلة', 'chip-bad', $reasons],
            $points >= 1            => [$points, 'مرتفعة', 'chip-warn', $reasons],
            default                 => [$points, 'عادية', 'chip-mute', $reasons],
        };
    }

    /* ─────────────── ٢ لم تُمسح عند نقطة انتقال ─────────────── */

    public function unscanned(User $user, string $kind): Builder
    {
        $since = $this->threshold();
        $query = Shipment::query()->visibleTo($user);

        return match ($kind) {
            'pickup' => $query
                ->whereIn('shipments.status', [ShipmentStatus::Created->value, ShipmentStatus::PendingPickup->value])
                ->where('shipments.created_at', '<', $since)
                ->with(['merchant:id,business_name,pickup_courier_id', 'merchant.pickupCourier:id,name,phone'])
                ->orderBy('shipments.created_at'),

            'to_hub' => $query
                ->where('shipments.status', ShipmentStatus::PickedUp->value)
                ->where('shipments.picked_up_at', '<', $since)
                ->with(['merchant:id,business_name,pickup_courier_id', 'pickupCourier:id,name,phone', 'merchant.pickupCourier:id,name,phone'])
                ->orderBy('shipments.picked_up_at'),

            'transit' => $query
                ->where('shipments.status', ShipmentStatus::InTransit->value)
                ->where('shipments.status_changed_at', '<', $since)
                ->with($this->bagRelations())
                ->orderBy('shipments.status_changed_at'),

            'return_courier' => $query
                ->where('shipments.status', ShipmentStatus::Returning->value)
                ->whereNull('shipments.return_received_at')
                ->where('shipments.status_changed_at', '<', $since)
                ->with(['merchant:id,business_name', 'deliveryCourier:id,name,phone'])
                ->orderBy('shipments.status_changed_at'),

            'return_bag' => $query
                ->where('shipments.status', ShipmentStatus::Returning->value)
                ->whereNotNull('shipments.return_received_at')
                ->whereIn('shipments.current_bag_id', \App\Models\Bag::query()->select('id')
                    ->whereIn('status', ['sealed', 'in_transit', 'received'])
                    ->where('sealed_at', '<', $since))
                ->with($this->bagRelations())
                ->orderBy('shipments.status_changed_at'),

            default => throw new \InvalidArgumentException("Unknown checkpoint {$kind}"),
        };
    }

    /** @return array<string, int> */
    public function unscannedCounts(User $user): array
    {
        return collect(array_keys(self::CHECKPOINTS))
            ->mapWithKeys(fn (string $kind) => [$kind => $this->unscanned($user, $kind)->toBase()->getCountForPagination()])
            ->all();
    }

    private function bagRelations(): array
    {
        return [
            'merchant:id,business_name',
            'currentBag:id,code,status,from_hub_id,to_hub_id,sealed_at,received_at',
            'currentBag.fromHub:id,name', 'currentBag.toHub:id,name',
            'currentBag.manifests' => fn ($q) => $q->with('courier:id,name,phone')->orderByDesc('manifests.id'),
        ];
    }

    /**
     * أين يُفترض أنها الآن، ومن المسؤول عنها — بكلامٍ يُقرأ.
     *
     * @return array{where: string, who: string, phone: ?string, missing: bool}
     */
    public static function whereabouts(Shipment $shipment, string $kind): array
    {
        $bag = $shipment->currentBag;
        $manifest = $bag?->manifests->first();
        $route = $bag ? trim(($bag->fromHub?->name ?? '—').' ← '.($bag->toHub?->name ?? '—')) : null;

        return match ($kind) {
            'pickup' => [
                'where' => 'عند التاجر '.($shipment->merchant?->business_name ?? ''),
                'who' => $shipment->merchant?->pickupCourier?->name ?? 'لا مندوب استلام للتاجر',
                'phone' => $shipment->merchant?->pickupCourier?->phone, 'missing' => false,
            ],
            'to_hub' => [
                'where' => 'مع مندوب الاستلام',
                'who' => ($c = $shipment->pickupCourier ?? $shipment->merchant?->pickupCourier)?->name ?? '—',
                'phone' => $c?->phone, 'missing' => false,
            ],
            'return_courier' => [
                'where' => 'مع مندوب التوصيل',
                'who' => $shipment->deliveryCourier?->name ?? '—',
                'phone' => $shipment->deliveryCourier?->phone, 'missing' => false,
            ],
            default => match (true) {
                // كشفٌ وصل وكيسها لم يكن فيه: مفقودٌ في الطريق
                (bool) $manifest?->pivot?->is_missing => [
                    'where' => 'مفقودة من الكشف '.$manifest->code.' — الكيس '.$bag->code,
                    'who' => $manifest->carrierLabel() ?? 'ناقل الكشف', 'phone' => $manifest->courier?->phone ?? $manifest->driver_phone, 'missing' => true,
                ],
                $bag?->status === 'received' => [
                    'where' => 'وصل كيسها '.$bag->code.' إلى '.($bag->toHub?->name ?? '—').' ولم يُفتح',
                    'who' => 'فرع '.($bag->toHub?->name ?? '—'), 'phone' => null, 'missing' => false,
                ],
                $manifest !== null => [
                    'where' => 'على الكشف '.$manifest->code.' ('.$route.')',
                    'who' => $manifest->carrierLabel() ?? 'ناقل الكشف', 'phone' => $manifest->courier?->phone ?? $manifest->driver_phone, 'missing' => false,
                ],
                default => [
                    'where' => $bag ? 'في الكيس '.$bag->code.' ('.$route.') بلا كشف' : 'بلا كيس',
                    'who' => 'فرع '.($bag?->fromHub?->name ?? '—'), 'phone' => null, 'missing' => false,
                ],
            },
        };
    }

    /** منذ متى وهي عند هذه النقطة */
    public static function since(Shipment $shipment, string $kind): ?Carbon
    {
        return match ($kind) {
            'pickup' => $shipment->created_at,
            'to_hub' => $shipment->picked_up_at,
            'return_bag' => $shipment->currentBag?->sealed_at ?? $shipment->status_changed_at,
            default => $shipment->status_changed_at,
        };
    }

    /* ─────────────── ٣ تحصيلاتٌ لم تُسوَّ ─────────────── */

    /**
     * مبالغ سُلّمت شحناتها قبل الموعد ولم يسلّمها المندوب، حسب فرعه ثمّ هو.
     *
     * @return Collection<int, object{branch_id: ?int, branch: string, courier_id: int, courier: string, phone: ?string, shipments: int, amount: int, oldest: string}>
     */
    public function unsettled(User $user): Collection
    {
        return Shipment::query()->visibleTo($user)
            ->whereNotNull('shipments.delivered_at')
            ->where('shipments.delivered_at', '<', $this->threshold())
            ->whereNull('shipments.courier_settled_at')
            ->where('shipments.collected_amount', '>', 0)
            ->join('couriers', 'couriers.id', '=', 'shipments.delivery_courier_id')
            ->leftJoin('branches', 'branches.id', '=', 'couriers.branch_id')
            ->selectRaw("couriers.branch_id as branch_id, coalesce(branches.name, 'بلا فرع') as branch,
                couriers.id as courier_id, couriers.name as courier, couriers.phone as phone,
                count(*) as shipments, sum(shipments.collected_amount) as amount, min(shipments.delivered_at) as oldest")
            ->groupBy('couriers.branch_id', 'branches.name', 'couriers.id', 'couriers.name', 'couriers.phone')
            ->orderBy('branches.name')
            ->orderBy('oldest')
            ->toBase()
            ->get()
            ->map(function ($row) {
                $row->shipments = (int) $row->shipments;
                $row->amount = (int) $row->amount;

                return $row;
            });
    }
}

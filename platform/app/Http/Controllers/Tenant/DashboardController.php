<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Services\Dashboard\HomeAlerts;
use App\Support\HomeLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * لوحة اليوم لموظّف الشركة.
 *
 * ستّ بطاقات رقابية تجيب أسئلة الصباح: شنو دخل؟ شنو بيد المندوبين؟
 * شنو متعثّر؟ وكم مال معلّق؟ — بلا فتح ستّ شاشات.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        // لوحته كما خصّصها هو أو مرتبته (HomeLayout): ما أُخفي لا يُحسب أصلاً
        $home = HomeLayout::for($user);
        $show = $home['sections'];
        $on = fn (string $section) => isset($show[$section]);

        // كل العدّادات من استعلام واحد مجمَّع بدل ستّة، على المفتوحة وحدها:
        // البطاقات لا تعدّ غيرها، والمنتهية تكبر مع كل يوم (١٥٥ ألفاً من ١٦٥
        // في سنة) فكان التجميع يقرأها كلّها ليرميها: ٧٨ مللي ثانية ← ٢
        $byStatus = Shipment::query()
            ->visibleTo($user)
            ->whereIn('status', ShipmentStatus::openValues())
            ->selectRaw('status, count(*) as c, sum(cod_amount) as cod')
            ->groupBy('status')
            ->toBase()
            ->get();

        $open = $byStatus->whereIn('status', ShipmentStatus::openValues());
        $of = fn (ShipmentStatus $s) => (int) $byStatus->where('status', $s->value)->sum('c');

        $today = $on('today') ? Shipment::query()->visibleTo($user)->whereOnDate('created_at', today())->count() : 0;
        $deliveredToday = $on('today') ? Shipment::query()->visibleTo($user)
            ->whereOnDate('delivered_at', today())->count() : 0;

        // المحذوفون داخلون: مندوبٌ فُصل وبيده نقد لم يُسلَّم — والنقد لا يُحذف بحذفه
        // ومن فرعه وحده إن كان مقيَّداً بفرع
        $money = $on('money') ? Courier::withTrashed()->visibleTo($user)
            ->toBase()
            ->selectRaw('sum(cash_in_hand) as cash, sum(commission_balance) as commission')
            ->first() : null;

        // أقدم نقدٍ لم يُسلَّم بعد، وأقدم كشف تاجرٍ لم يُدفع
        $oldestUncollected = $on('money') ? Shipment::query()
            ->visibleTo($user)
            ->whereNotNull('delivered_at')
            ->whereNull('courier_settled_at')
            ->where('collected_amount', '>', 0)
            ->min('delivered_at') : null;

        $oldestUnpaid = $on('money') ? MerchantSettlement::query()->visibleTo($user)
            ->where('status', 'confirmed')
            ->min('confirmed_at') : null;

        $staleAfter = (int) config('zajel.stale_shipment_days', 5);

        return view('tenant.dashboard', [
            'show'      => $show,
            'shortcuts' => $home['shortcuts'],

            'week' => $on('today') ? $this->lastSevenDays($user) : [],

            // البطاقات السبع كما في رئيسية المعتاد، لمن يفتح ما خلفها — وما اختاره منها
            'alerts' => $on('alerts') ? app(HomeAlerts::class)->for($user, $home['alerts']) : [],

            'cards' => [
                'today'          => $today,
                'delivered_today' => $deliveredToday,
                'open'           => (int) $open->sum('c'),
                'with_couriers'  => $of(ShipmentStatus::OutForDelivery),
                'at_hub'         => $of(ShipmentStatus::AtHub) + $of(ShipmentStatus::InTransit),
                'stuck'          => $of(ShipmentStatus::FailedAttempt) + $of(ShipmentStatus::Postponed),
                'cod_open'       => (int) $open->sum('cod'),
                'cash_in_hand'   => (int) ($money->cash ?? 0),
                'owed_merchants' => $on('money') ? (int) Merchant::visibleTo($user)->where('balance', '>', 0)->sum('balance') : 0,
                'pending_pickups' => $on('pickups') ? PickupRequest::visibleTo($user)->where('status', 'pending')->count() : 0,
            ],

            // الشحنات المتعثّرة: أهم قائمة في الشاشة — كل يوم تأخير يزيد احتمال الراجع
            'stuck' => $on('stuck') ? Shipment::query()
                ->visibleTo($user)
                ->whereIn('status', [ShipmentStatus::FailedAttempt->value, ShipmentStatus::Postponed->value])
                ->with(['merchant:id,business_name', 'deliveryCourier:id,name', 'lastFailureReason:id,name_ar,category'])
                ->orderBy('status_changed_at')
                ->limit(10)
                ->get() : collect(),

            // من تجاوز سقف نقده يجب أن يُسوّى قبل أن يُسنَد إليه المزيد
            /*
            | التقادُم، وهو ما ينقص الأرقام أعلاه.
            |
            | «٨.٦ مليون بيد المندوبين» رقم لا يُقلق أحداً، و«أقدمها منذ
            | ٣٧ يوماً» يُقلق. المبلغ وحده يبدو دورةَ عملٍ طبيعية؛ عمرُه
            | هو ما يكشف أن أحداً لم يُسوِّ حساب مندوب منذ شهر.
            */
            'aging' => [
                'cod_oldest_days' => $oldestUncollected
                    ? (int) Carbon::parse($oldestUncollected)->diffInDays(now())
                    : null,
                'merchant_oldest_days' => $oldestUnpaid
                    ? (int) Carbon::parse($oldestUnpaid)->diffInDays(now())
                    : null,
                'stale_shipments' => $on('stale') ? Shipment::query()
                    ->visibleTo($user)
                    ->whereIn('status', ShipmentStatus::openValues())
                    ->where('status_changed_at', '<', now()->subDays($staleAfter))
                    ->count() : 0,
                'stale_after' => $staleAfter,
            ],

            'overCashLimit' => $on('cash_limit') ? Courier::query()
                ->visibleTo($user)
                ->where('cash_limit', '>', 0)
                ->whereColumn('cash_in_hand', '>=', 'cash_limit')
                ->orderByDesc('cash_in_hand')
                ->get() : collect(),

            'byGovernorate' => ! $on('governorates') ? collect() : Shipment::query()
                ->visibleTo($user)
                ->whereIn('status', ShipmentStatus::openValues())
                ->join('governorates', 'governorates.id', '=', 'shipments.governorate_id')
                ->selectRaw('governorates.name_ar as name, count(*) as c')
                ->groupBy('governorates.name_ar')
                ->orderByDesc('c')
                ->limit(8)
                ->toBase()
                ->get(),
        ]);
    }

    /**
     * الأيام السبعة الأخيرة، أقدمها أوّلاً واليوم آخرها: ما أُنشئ وما سُلّم في
     * كلٍّ منها، لرسمَي اللوحة. استعلامان مجمَّعان على فهرسَي التاريخ لا
     * أربعة عشر، ويومٌ بلا شحنات صفرٌ لا ثغرة في الرسم.
     *
     * @return list<array{date: Carbon, created: int, delivered: int}>
     */
    private function lastSevenDays($user): array
    {
        $from = today()->subDays(6);

        $perDay = fn (string $column) => Shipment::query()
            ->visibleTo($user)
            ->where("shipments.$column", '>=', $from)
            ->selectRaw("date(shipments.$column) as day, count(*) as c")
            ->groupBy('day')
            ->toBase()
            ->pluck('c', 'day');

        $created = $perDay('created_at');
        $delivered = $perDay('delivered_at');

        return collect(range(6, 0))
            ->map(fn (int $ago) => today()->subDays($ago))
            ->map(fn (Carbon $day) => [
                'date'      => $day,
                'created'   => (int) ($created[$day->toDateString()] ?? 0),
                'delivered' => (int) ($delivered[$day->toDateString()] ?? 0),
            ])
            ->all();
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRequest;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // كل العدّادات من استعلام واحد مجمَّع بدل ستّة
        $byStatus = Shipment::query()
            ->visibleTo($user)
            ->selectRaw('status, count(*) as c, sum(cod_amount) as cod')
            ->groupBy('status')
            ->toBase()
            ->get();

        $terminal = array_map(fn (ShipmentStatus $s) => $s->value, ShipmentStatus::terminal());
        $open = $byStatus->whereNotIn('status', $terminal);
        $of = fn (ShipmentStatus $s) => (int) $byStatus->where('status', $s->value)->sum('c');

        $today = Shipment::query()->visibleTo($user)->whereDate('created_at', today())->count();
        $deliveredToday = Shipment::query()->visibleTo($user)
            ->whereDate('delivered_at', today())->count();

        $money = DB::table('couriers')
            ->where('company_id', $user->company_id)
            ->whereNull('deleted_at')
            ->selectRaw('sum(cash_in_hand) as cash, sum(commission_balance) as commission')
            ->first();

        // أقدم نقدٍ لم يُسلَّم بعد، وأقدم كشف تاجرٍ لم يُدفع
        $oldestUncollected = Shipment::query()
            ->visibleTo($user)
            ->whereNotNull('delivered_at')
            ->whereNull('courier_settled_at')
            ->where('collected_amount', '>', 0)
            ->min('delivered_at');

        $oldestUnpaid = DB::table('merchant_settlements')
            ->where('company_id', $user->company_id)
            ->where('status', 'confirmed')
            ->min('confirmed_at');

        $staleAfter = (int) config('zajel.stale_shipment_days', 5);

        return view('tenant.dashboard', [
            'cards' => [
                'today'          => $today,
                'delivered_today' => $deliveredToday,
                'open'           => (int) $open->sum('c'),
                'with_couriers'  => $of(ShipmentStatus::OutForDelivery),
                'at_hub'         => $of(ShipmentStatus::AtHub) + $of(ShipmentStatus::InTransit),
                'stuck'          => $of(ShipmentStatus::FailedAttempt) + $of(ShipmentStatus::Postponed),
                'cod_open'       => (int) $open->sum('cod'),
                'cash_in_hand'   => (int) ($money->cash ?? 0),
                'owed_merchants' => (int) Merchant::where('balance', '>', 0)->sum('balance'),
                'pending_pickups' => PickupRequest::where('status', 'pending')->count(),
            ],

            // الشحنات المتعثّرة: أهم قائمة في الشاشة — كل يوم تأخير يزيد احتمال الراجع
            'stuck' => Shipment::query()
                ->visibleTo($user)
                ->whereIn('status', [ShipmentStatus::FailedAttempt->value, ShipmentStatus::Postponed->value])
                ->with(['merchant:id,business_name', 'deliveryCourier:id,name', 'lastFailureReason:id,name_ar,category'])
                ->orderBy('status_changed_at')
                ->limit(10)
                ->get(),

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
                'stale_shipments' => Shipment::query()
                    ->visibleTo($user)
                    ->whereNotIn('status', $terminal)
                    ->where('status_changed_at', '<', now()->subDays($staleAfter))
                    ->count(),
                'stale_after' => $staleAfter,
            ],

            'overCashLimit' => Courier::query()
                ->where('cash_limit', '>', 0)
                ->whereColumn('cash_in_hand', '>=', 'cash_limit')
                ->orderByDesc('cash_in_hand')
                ->get(),

            'byGovernorate' => Shipment::query()
                ->visibleTo($user)
                ->whereNotIn('status', $terminal)
                ->join('governorates', 'governorates.id', '=', 'shipments.governorate_id')
                ->selectRaw('governorates.name_ar as name, count(*) as c')
                ->groupBy('governorates.name_ar')
                ->orderByDesc('c')
                ->limit(8)
                ->toBase()
                ->get(),
        ]);
    }
}

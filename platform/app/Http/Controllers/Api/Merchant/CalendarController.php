<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * «تقويم الشحنات» (docs/plan/87): ما أدخله التاجر في فترةٍ يوماً يوماً، وأين صار كلٌّ منها الآن — للرئيسية
 * بطاقةٌ صغيرة بآخر سبعة أيام، ولشاشته فترةٌ يختارها.
 *
 * يُحسب بيوم الإدخال، فيشمل ما حاسبناه عليه وما سلّمناه إليه: هو أداؤه لا قائمةٌ جارية.
 */
class CalendarController extends Controller
{
    /** أطول فترة: ثلاثة أشهر */
    private const MAX_DAYS = 93;

    /** أين صارت الشحنة، بخاناتٍ خمس */
    public const BUCKETS = [
        'delivered' => 'تم التسليم',
        'open' => 'قيد التوصيل',
        'postponed' => 'مؤجل',
        'processing' => 'للمعالجة',
        'returned' => 'راجع',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $today = now('Asia/Baghdad')->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'], 'Asia/Baghdad') : $today->copy();
        $from = isset($data['from']) ? Carbon::parse($data['from'], 'Asia/Baghdad') : $to->copy()->subDays(6);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        $rows = Shipment::where('merchant_id', $merchant->id)
            ->whereBetween('created_at', [
                $from->copy()->startOfDay()->timezone(config('app.timezone')),
                $to->copy()->endOfDay()->timezone(config('app.timezone')),
            ])
            ->selectRaw('date(created_at) as d, status, count(*) as c')
            ->groupByRaw('date(created_at), status')
            ->toBase()->get();

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'total' => 0] + array_fill_keys(array_keys(self::BUCKETS), 0);
        }
        foreach ($rows as $row) {
            $date = substr((string) $row->d, 0, 10);
            if (! isset($days[$date])) {
                continue;
            }
            $days[$date]['total'] += (int) $row->c;
            if ($bucket = self::bucket((string) $row->status)) {
                $days[$date][$bucket] += (int) $row->c;
            }
        }

        $totals = array_fill_keys(array_keys(self::BUCKETS), 0);
        foreach ($days as $day) {
            foreach ($totals as $key => $n) {
                $totals[$key] = $n + $day[$key];
            }
        }
        $decided = $totals['delivered'] + $totals['returned'];

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => array_sum(array_column($days, 'total')),
            'buckets' => collect(self::BUCKETS)->map(fn ($label, $key) => [
                'key' => $key, 'label' => $label, 'count' => $totals[$key],
            ])->values(),
            // نسبة التسليم ممّا حُسم: الواصل من الواصل والراجع
            'rate' => $decided > 0 ? (int) round($totals['delivered'] * 100 / $decided) : null,
            'days' => array_values($days),
            // من أدخلها، حين للمتجر أكثر من حساب (موظّفوه)
            'by_user' => $this->byUser($merchant->id, $from, $to),
        ]);
    }

    public static function bucket(string $status): ?string
    {
        return match ($status) {
            ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value => 'delivered',
            ShipmentStatus::Postponed->value => 'postponed',
            ShipmentStatus::FailedAttempt->value => 'processing',
            ShipmentStatus::Returning->value, ShipmentStatus::Returned->value => 'returned',
            ShipmentStatus::Created->value, ShipmentStatus::PendingPickup->value, ShipmentStatus::PickedUp->value,
            ShipmentStatus::AtHub->value, ShipmentStatus::InTransit->value, ShipmentStatus::OutForDelivery->value => 'open',
            default => null,
        };
    }

    /** @return list<array{name: string, count: int}> */
    private function byUser(int $merchantId, Carbon $from, Carbon $to): array
    {
        $users = User::where('merchant_id', $merchantId)->pluck('name', 'id');
        if ($users->count() < 2) {
            return [];
        }

        return Shipment::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [
                $from->copy()->startOfDay()->timezone(config('app.timezone')),
                $to->copy()->endOfDay()->timezone(config('app.timezone')),
            ])
            ->whereIn('created_by_user_id', $users->keys())
            ->selectRaw('created_by_user_id as u, count(*) as c')->groupBy('created_by_user_id')
            ->toBase()->get()
            ->map(fn ($r) => ['name' => (string) $users[$r->u], 'count' => (int) $r->c])
            ->sortByDesc('count')->values()->all();
    }
}

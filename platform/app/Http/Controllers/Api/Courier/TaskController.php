<?php

namespace App\Http\Controllers\Api\Courier;

use App\Actions\Returns\ReceiveReturns;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «مهامي» في تطبيق المندوب (docs/plan/66، 75): أربع خانات بما بيده —
 *
 * - التوصيل: ما خرج معه أوّل مرّة، مجمّعاً بالمحافظة والمنطقة.
 * - إعادة التوصيل: ما فشل ثم قرّر الكول سنتر أو التاجر إعادته إليه.
 * - المؤجل: ما أجّله هو أو الكول سنتر، بموعده — اليوم أوّلاً.
 * - الراجع: ما يرجع معه إلى المخزن، وما لم يُسلَّم ولم يُقرَّر فيه بعد: يبقى أمامه حتى يُعالَج
 *   لا يختفي.
 */
class TaskController extends Controller
{
    public const KINDS = ['delivery', 'redelivery', 'postponed', 'returns'];

    public function index(Request $request): JsonResponse
    {
        $courier = $request->attributes->get('courier');
        $data = $request->validate([
            'kind' => ['nullable', 'in:'.implode(',', self::KINDS)],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $kind = $data['kind'] ?? 'delivery';
        $term = trim((string) ($data['q'] ?? ''));

        $query = self::of($courier, $kind)
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar']);

        match ($kind) {
            'postponed' => $query->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->orderBy('id'),
            'returns' => $query->orderBy('status_changed_at'),
            default => $query->orderBy('governorate_id')->orderBy('city_id')->orderBy('id'),
        };

        if ($term !== '') {
            $query->search($term);
        }

        $shipments = $query->get();

        // طلب تغيير المبلغ الذي ينتظر جواباً، أو واصلٌ جزئيّ اعتُمد: يُرى على السطر
        $tickets = ShipmentTicket::query()->where('courier_id', $courier->id)
            ->whereIn('shipment_id', $shipments->pluck('id'))
            ->where(fn ($q) => $q->open()->orWhere(fn ($w) => $w->awaitingPartial()))
            ->get()->keyBy('shipment_id');

        $row = fn (Shipment $s) => ShipmentController::row($s, $tickets[$s->id] ?? null);

        return response()->json([
            'kind' => $kind,
            'count' => $shipments->count(),
            'to_collect' => in_array($kind, ['delivery', 'redelivery'], true) ? (int) $shipments->sum('cod_amount') : 0,
            // عدد كلّ خانة على شريحتها، بلا بحث
            'counts' => collect(self::KINDS)->mapWithKeys(fn ($k) => [$k => self::of($courier, $k)->count()]),
            'groups' => match ($kind) {
                'postponed' => self::byDay($shipments, $row),
                'returns' => self::byState($shipments, $row),
                default => self::byPlace($shipments, $row),
            },
        ]);
    }

    /** ما في كلّ خانة — شرطٌ واحد للقائمة ولعددها */
    public static function of(Courier $courier, string $kind): Builder
    {
        $query = Shipment::query()->where('delivery_courier_id', $courier->id);

        return match ($kind) {
            'redelivery' => $query->where('status', ShipmentStatus::OutForDelivery->value)->whereNotNull('redelivery_at'),
            'postponed' => $query->where('status', ShipmentStatus::Postponed->value),
            // الراجع المؤكّد بيده، وما لم يُسلَّم وينتظر قرار المعالجة
            'returns' => $query->where(fn ($q) => $q->where(fn ($w) => ReceiveReturns::withCourier($w))
                ->orWhere('status', ShipmentStatus::FailedAttempt->value)),
            default => $query->where('status', ShipmentStatus::OutForDelivery->value)->whereNull('redelivery_at'),
        };
    }

    /** التوصيل وإعادته: بالمحافظة والمنطقة، فيمشي على خطّ */
    private static function byPlace(Collection $shipments, \Closure $row): Collection
    {
        return $shipments
            ->groupBy(fn (Shipment $s) => ($s->governorate?->name_ar ?? '—').'|'.($s->city?->name_ar ?? ''))
            ->map(function ($items, $key) use ($row) {
                [$governorate, $city] = explode('|', $key, 2);

                return [
                    'governorate' => $governorate,
                    'city' => $city !== '' ? $city : null,
                    'count' => $items->count(),
                    'items' => $items->map($row)->values(),
                ];
            })->values();
    }

    /** المؤجل بموعده: فات موعدها، اليوم، غداً، بعده، وبلا موعد */
    private static function byDay(Collection $shipments, \Closure $row): Collection
    {
        $day = function (Shipment $s): string {
            $at = $s->scheduled_at;

            return match (true) {
                $at === null => 'بلا موعد',
                $at->lt(today()) => 'فات موعدها',
                $at->isToday() => 'موعدها اليوم',
                $at->isTomorrow() => 'موعدها غداً',
                default => 'بعد ذلك',
            };
        };

        return $shipments->groupBy($day)
            ->map(fn ($items, $title) => ['title' => $title, 'count' => $items->count(), 'items' => $items->map($row)->values()])
            ->sortBy(fn ($g) => array_search($g['title'], ['فات موعدها', 'موعدها اليوم', 'موعدها غداً', 'بعد ذلك', 'بلا موعد'], true))
            ->values();
    }

    /** الراجع: ما ينتظر قرار المعالجة أوّلاً، ثم ما يُسلَّم للمخزن */
    private static function byState(Collection $shipments, \Closure $row): Collection
    {
        return $shipments
            ->groupBy(fn (Shipment $s) => $s->status === ShipmentStatus::FailedAttempt ? 'waiting' : 'back')
            ->sortKeysDesc()
            ->map(fn ($items, $state) => [
                'title' => $state === 'waiting' ? 'لم تُسلَّم — تنتظر قرار المعالجة' : 'راجع — سلّمه للمخزن',
                'count' => $items->count(),
                'items' => $items->map($row)->values(),
            ])->values();
    }
}

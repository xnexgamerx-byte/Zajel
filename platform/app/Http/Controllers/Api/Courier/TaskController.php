<?php

namespace App\Http\Controllers\Api\Courier;

use App\Actions\Returns\ReceiveReturns;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «مهامي» في تطبيق المندوب (docs/plan/66): ما بيده للتوصيل مجمّعاً بالمحافظة والمنطقة، وما يرجع
 * معه إلى المخزن — كما في شاشته في الموقع.
 */
class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courier = $request->attributes->get('courier');
        $data = $request->validate([
            'kind' => ['nullable', 'in:delivery,returns'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $kind = $data['kind'] ?? 'delivery';
        $term = trim((string) ($data['q'] ?? ''));

        $query = Shipment::query()->where('delivery_courier_id', $courier->id)
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar']);

        $kind === 'returns'
            ? $query->where(fn ($q) => ReceiveReturns::withCourier($q))->orderBy('status_changed_at')
            : $query->where('status', ShipmentStatus::OutForDelivery->value)->orderBy('governorate_id')->orderBy('city_id')->orderBy('id');

        if ($term !== '') {
            $query->search($term);
        }

        $shipments = $query->get();

        // طلب تغيير المبلغ الذي ينتظر جواباً، أو واصلٌ جزئيّ اعتُمد: يُرى على السطر
        $tickets = ShipmentTicket::query()->where('courier_id', $courier->id)
            ->whereIn('shipment_id', $shipments->pluck('id'))
            ->where(fn ($q) => $q->open()->orWhere(fn ($w) => $w->awaitingPartial()))
            ->get()->keyBy('shipment_id');

        $groups = $shipments
            ->groupBy(fn (Shipment $s) => ($s->governorate?->name_ar ?? '—').'|'.($s->city?->name_ar ?? ''))
            ->map(function ($items, $key) use ($tickets) {
                [$governorate, $city] = explode('|', $key, 2);

                return [
                    'governorate' => $governorate,
                    'city' => $city !== '' ? $city : null,
                    'count' => $items->count(),
                    'items' => $items->map(fn (Shipment $s) => ShipmentController::row($s, $tickets[$s->id] ?? null))->values(),
                ];
            })->values();

        return response()->json([
            'kind' => $kind,
            'count' => $shipments->count(),
            'to_collect' => $kind === 'returns' ? 0 : (int) $shipments->sum('cod_amount'),
            'groups' => $groups,
        ]);
    }
}

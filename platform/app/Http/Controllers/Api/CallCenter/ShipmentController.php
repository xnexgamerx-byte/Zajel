<?php

namespace App\Http\Controllers\Api\CallCenter;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «الزبون اتّصل بخصوص الوصل كذا» (docs/plan/73): البحث برقم الوصل أو هاتف الزبون أو اسمه، أو مسح
 * باركوده — والشحنة بسجلّها كما تراها الموظّفة في الموقع.
 */
class ShipmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('shipments.view'), 403);
        $term = trim((string) $request->query('q'));

        $found = $term === '' ? collect() : Shipment::query()->visibleTo($request->user())->search($term)
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->latest('shipments.id')->limit(30)->get();

        return response()->json(['data' => $found->map(fn (Shipment $s) => [
            'id' => $s->id,
            'number' => $s->number,
            'recipient' => $s->recipient_name,
            'phone' => $s->recipient_phone,
            'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
            'merchant' => $s->merchant?->business_name,
            'status' => $s->status->value,
            'status_label' => $s->statusLabel(),
            'amount' => (int) $s->cod_amount,
        ])->values()]);
    }

    public function show(Request $request, int $shipment): JsonResponse
    {
        abort_unless($request->user()->can('shipments.view'), 403);
        $s = Shipment::query()->visibleTo($request->user())
            ->with(['merchant:id,business_name,phone', 'governorate:id,name_ar', 'city:id,name_ar',
                'deliveryCourier:id,name,phone', 'lastFailureReason:id,name_ar'])
            ->findOrFail($shipment);

        return response()->json(['data' => [
            'id' => $s->id,
            'number' => $s->number,
            'status' => $s->status->value,
            'status_label' => $s->statusLabel(),
            'recipient' => $s->recipient_name,
            'phone' => $s->recipient_phone,
            'phone_alt' => $s->recipient_phone_alt,
            'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
            'address' => $s->address,
            'landmark' => $s->landmark,
            'amount' => (int) $s->cod_amount,
            'notes' => $s->notes,
            'reason' => $s->lastFailureReason?->name_ar,
            'attempts' => (int) $s->attempts_count,
            'merchant' => ['name' => $s->merchant?->business_name, 'phone' => $s->merchant?->phone],
            'courier' => ['name' => $s->deliveryCourier?->name, 'phone' => $s->deliveryCourier?->phone],
            // في المعالجة الآن: تُفتح منها
            'processing' => $s->status === ShipmentStatus::FailedAttempt,
            'events' => ShipmentEvent::query()->where('shipment_id', $s->id)->latest('id')->limit(20)->get()
                ->map(fn (ShipmentEvent $e) => [
                    'status' => ShipmentStatus::tryFrom((string) $e->to_status)?->label() ?? $e->to_status,
                    'note' => $e->note,
                    'by' => $e->actor_name,
                    'at' => $e->created_at?->toIso8601String(),
                ])->values(),
        ]]);
    }
}

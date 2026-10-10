<?php

namespace App\Support;

use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;

/**
 * أين الشحنة الآن على الخريطة (docs/plan/61): آخر موقعٍ أرسله هاتف المندوب مع حدثٍ عليها وهي
 * بيده — وإلّا فتقريبياً مركز محافظتها. الدقيق وحده يُعرض للزبون؛ والتقريبيّ للتاجر بعلامته.
 */
final class ShipmentLocation
{
    /** @return ?array{lat: float, lng: float, exact: bool, at: ?string} */
    public static function of(Shipment $shipment): ?array
    {
        $event = ShipmentEvent::query()->where('shipment_id', $shipment->id)
            ->whereNotNull('lat')->whereNotNull('lng')->latest('id')->first(['lat', 'lng', 'created_at']);

        if ($event) {
            return ['lat' => (float) $event->lat, 'lng' => (float) $event->lng, 'exact' => true, 'at' => $event->created_at?->toIso8601String()];
        }

        $centre = Governorate::query()->whereKey($shipment->governorate_id)->whereNotNull('lat')->first(['lat', 'lng']);

        return $centre ? ['lat' => (float) $centre->lat, 'lng' => (float) $centre->lng, 'exact' => false, 'at' => null] : null;
    }
}

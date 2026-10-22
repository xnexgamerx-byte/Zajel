<?php

namespace App\Actions\Couriers;

use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Support\FeatureGate;
use App\Support\ScanCode;

/**
 * مسح الشحنة عند الاستلام والتسليم (docs/plan/72): ما يكتبه الماسح أو المندوب — رقم الوصل، أو
 * باركوده، أو رابط QR. مندوب الاستلام يستلم من التاجر ما مسحه وحده، ومندوب التوصيل لا يسجّل
 * على شحنةٍ عند الباب قبل مسحها: الطرد في يده لا في ذاكرته.
 *
 * يُطابق الرقم والباركود ومرجع التاجر بالضبط، لا الاسم ولا الهاتف: الماسح لا يُخطئ حرفاً،
 * والبحث الجزئي يجعل رقماً ناقصاً شحنةً أخرى.
 */
final class ScanParcel
{
    public static function required(): bool
    {
        return FeatureGate::enabled(Feature::ScanParcels);
    }

    /** ما مُسح عند الباب هو هذه الشحنة: رقمها أو باركودها أو رابط وصلها */
    public static function matches(Shipment $shipment, ?string $raw): bool
    {
        $code = ScanCode::read($raw)->code;

        return $code !== '' && in_array($code, array_filter([$shipment->number, $shipment->barcode]), true);
    }

    /**
     * شحنةٌ من شحنات تاجر الطلب تنتظر الاستلام — أو ما يُقال لمندوب الاستلام.
     */
    public static function resolve(PickupRequest $pickup, ?string $raw): Shipment|string
    {
        $code = ScanCode::read($raw)->code;
        if ($code === '') {
            return 'امسح باركود الشحنة أو اكتب رقمها.';
        }

        $shipment = Shipment::query()
            ->where(fn ($q) => $q->where('number', $code)->orWhere('barcode', $code)
                ->orWhere(fn ($w) => $w->where('merchant_id', $pickup->merchant_id)->where('merchant_reference', $code)))
            ->orderByRaw('merchant_id = ? desc', [$pickup->merchant_id])
            ->first();

        return match (true) {
            $shipment === null => "لا شحنة بالرقم «{$code}».",
            (int) $shipment->merchant_id !== (int) $pickup->merchant_id => "الشحنة {$shipment->number} ليست لهذا التاجر.",
            ! in_array($shipment->status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true) => "الشحنة {$shipment->number} استُلمت سلفاً ({$shipment->statusLabel()}).",
            default => $shipment,
        };
    }

    /**
     * أرقام الشحنات الممسوحة كلّها، أو أوّل ما يمنع منها.
     *
     * @param  list<int|string>  $ids
     * @return list<int>|string
     */
    public static function ids(PickupRequest $pickup, array $ids): array|string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 'امسح الطرود التي استلمتها — بلا مسحٍ لا يُستلم شيء.';
        }

        $found = Shipment::query()->whereIn('id', $ids)->get(['id', 'number', 'merchant_id', 'status']);
        foreach ($ids as $id) {
            $shipment = $found->firstWhere('id', $id);
            if ($shipment === null || (int) $shipment->merchant_id !== (int) $pickup->merchant_id) {
                return 'بين الممسوح شحنةٌ ليست لهذا التاجر. امسح من جديد.';
            }
            if (! in_array($shipment->status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true)) {
                return "الشحنة {$shipment->number} استُلمت سلفاً.";
            }
        }

        return $ids;
    }
}

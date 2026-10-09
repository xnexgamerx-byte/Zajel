<?php

namespace App\Support;

use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Shipment;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;

/**
 * آخر موعدٍ للتوصيل (docs/plan/39): ٢٤ ساعة ما لم تغيّره الشركة.
 *
 * تُعدّ من استلام الشحنة من التاجر (picked_up_at) — قبله ليست بيد الشركة. وبها
 * تُقاس «التنبيهات التشغيلية» كلّها: الشحنة يظهر تنبيهها بعد مرورها.
 */
final class DeliveryDeadline
{
    public const HOURS = 24;

    /**
     * في طريقها إلى المستلم: ما يُحاسَب على الموعد. الراجع خرج من التوصيل إلى الإرجاع،
     * وما قبل الاستلام عند التاجر.
     */
    public const ON_THE_WAY = [
        ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::InTransit,
        ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed,
    ];

    /** @return list<string> */
    public static function onTheWayValues(): array
    {
        return array_map(fn (ShipmentStatus $s) => $s->value, self::ON_THE_WAY);
    }

    public static function hours(?Company $company = null): int
    {
        $company ??= Tenancy::company();
        $hours = $company?->setting('delivery.deadline_hours');

        return is_numeric($hours) && (int) $hours >= 1 && (int) $hours <= 240 ? (int) $hours : self::HOURS;
    }

    /** متى يحلّ موعدها، أو null إن لم تُستلم من التاجر بعد */
    public static function dueAt(Shipment $shipment, ?Company $company = null): ?Carbon
    {
        return $shipment->picked_up_at?->copy()->addHours(self::hours($company));
    }

    /** ساعات تأخيرها بعد الموعد وهي لم تُحسم — 0 إن لم تتأخّر أو حُسمت */
    public static function lateHours(Shipment $shipment, ?Company $company = null): int
    {
        $due = self::dueAt($shipment, $company);

        if (! $due || ! in_array($shipment->status, self::ON_THE_WAY, true) || now()->lte($due)) {
            return 0;
        }

        return (int) floor($due->diffInMinutes(now()) / 60);
    }
}

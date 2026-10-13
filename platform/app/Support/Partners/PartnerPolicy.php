<?php

namespace App\Support\Partners;

use App\Enums\ShipmentStatus;

/**
 * ما يقدر الشريك أن يسجّله على الطرد (docs/plan/64). قائمةٌ مغلقة فوق خريطة الحالات نفسها:
 * أي انتقالٍ خارجها يُرفض، حتى لو كان مسموحاً عندنا لموظّفٍ داخلي.
 */
final class PartnerPolicy
{
    /** الحالات التي يكون فيها الطرد عند الشريك: منها يُسجَّل، وإليها يُعاد عند الاستلام */
    public const HELD = [
        ShipmentStatus::InTransit,
        ShipmentStatus::AtHub,
        ShipmentStatus::OutForDelivery,
        ShipmentStatus::FailedAttempt,
        ShipmentStatus::Postponed,
        ShipmentStatus::Returning,
    ];

    /**
     * من كل حالةٍ ما يحقّ للشريك أن يقوله. «Delivered» ليست هنا بمعناها المباشر: يُرسَل تقريراً
     * ينتظر مراجعتنا (PartnerReport)، فلا يحرّك مالاً قبل أن نوافق.
     *
     * @var array<string, list<ShipmentStatus>>
     */
    private const NEXT = [
        'in_transit' => [ShipmentStatus::AtHub],
        'at_hub' => [ShipmentStatus::OutForDelivery, ShipmentStatus::Returning],
        'out_for_delivery' => [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed, ShipmentStatus::Delivered],
        'failed_attempt' => [ShipmentStatus::OutForDelivery, ShipmentStatus::Returning],
        'postponed' => [ShipmentStatus::OutForDelivery, ShipmentStatus::Returning],
    ];

    /** ما يُرسَل به الانتقال من الشريك: قيمة الحالة نفسها، و«delivered» تقرير تسليم */
    public static function parse(string $value): ?ShipmentStatus
    {
        $status = ShipmentStatus::tryFrom($value);

        return $status !== null && in_array($status, self::allowedValues(), true) ? $status : null;
    }

    /** @return list<ShipmentStatus> */
    public static function allowedValues(): array
    {
        $values = array_unique(array_map(
            fn (ShipmentStatus $s) => $s->value,
            array_merge(...array_values(self::NEXT)),
        ));

        return array_map(fn (string $v) => ShipmentStatus::from($v), $values);
    }

    /** ما يقدر الشريك أن ينتقل إليه من هذه الحالة */
    public static function next(ShipmentStatus $from): array
    {
        return array_values(array_filter(
            self::NEXT[$from->value] ?? [],
            fn (ShipmentStatus $to) => $from->canMoveTo($to),
        ));
    }

    public static function holds(ShipmentStatus $status): bool
    {
        return in_array($status, self::HELD, true);
    }
}

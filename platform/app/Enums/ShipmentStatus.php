<?php

namespace App\Enums;

/**
 * مفردات حالات الشحنة — مصدر واحد للحقيقة.
 *
 * تُخزَّن نصّاً في قاعدة البيانات لا ENUM، حتى لا تحتاج إضافة حالة جديدة
 * إلى ALTER TABLE على جدول فيه ملايين الصفوف.
 */
enum ShipmentStatus: string
{
    case Created            = 'created';
    case PendingPickup      = 'pending_pickup';
    case PickedUp           = 'picked_up';
    case AtHub              = 'at_hub';
    case InTransit          = 'in_transit';
    case OutForDelivery     = 'out_for_delivery';
    case Delivered          = 'delivered';
    case PartiallyDelivered = 'partially_delivered';
    case FailedAttempt      = 'failed_attempt';
    case Postponed          = 'postponed';
    case Returning          = 'returning';
    case Returned           = 'returned';
    case Cancelled          = 'cancelled';
    case Lost               = 'lost';
    case Damaged            = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Created            => 'تم الإنشاء',
            self::PendingPickup      => 'بانتظار الاستلام',
            self::PickedUp           => 'تم الاستلام',
            self::AtHub              => 'في المخزن',
            self::InTransit          => 'قيد النقل',
            self::OutForDelivery     => 'مع المندوب',
            self::Delivered          => 'تم التسليم',
            self::PartiallyDelivered => 'تسليم جزئي',
            self::FailedAttempt      => 'محاولة فاشلة',
            self::Postponed          => 'مؤجَّلة',
            self::Returning          => 'قيد الإرجاع',
            self::Returned           => 'راجعة للتاجر',
            self::Cancelled          => 'ملغاة',
            self::Lost               => 'مفقودة',
            self::Damaged            => 'تالفة',
        };
    }

    /** لون الشارة في الواجهة. */
    public function color(): string
    {
        return match ($this) {
            self::Delivered                              => 'green',
            self::Returned, self::Cancelled              => 'gray',
            self::Lost, self::Damaged                    => 'red',
            self::FailedAttempt, self::Postponed         => 'amber',
            self::OutForDelivery, self::PartiallyDelivered => 'blue',
            default                                      => 'slate',
        };
    }

    /** الشحنة ما زالت في العمليات. */
    public function isOpen(): bool
    {
        return ! in_array($this, self::terminal(), true);
    }

    /** حالات لا تتغيّر بعدها الشحنة. */
    public static function terminal(): array
    {
        return [self::Delivered, self::Returned, self::Cancelled, self::Lost, self::Damaged];
    }

    /**
     * الحالات المفتوحة — قيمةً لا كائناً.
     *
     * تُستعمل في الاستعلامات بـ whereIn لا whereNotIn على النهائية:
     * «ليس ضمن» لا يستطيع محرّك قاعدة البيانات أن يقفز بها في الفهرس
     * فيمسح ما بعد الشركة كلّه. على ١٨٠ ألف شحنة: ٢٤٢ مللي ثانية مقابل ٧.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->isOpen()),
        ));
    }

    /** @return array<int, string> */
    public static function terminalValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::terminal());
    }

    /** حالات تُحتسب على التاجر مالياً. */
    public function isBillable(): bool
    {
        return in_array($this, [self::Delivered, self::PartiallyDelivered, self::Returned], true);
    }

    /**
     * الانتقالات المسموحة. كل انتقال خارج هذه الخريطة يُرفَض في الخدمة،
     * لأن "تم التسليم" ثم "بانتظار الاستلام" يعني حساباً خاطئاً لا خطأ عرض.
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Created            => [self::PendingPickup, self::PickedUp, self::Cancelled],
            self::PendingPickup      => [self::PickedUp, self::Cancelled],
            self::PickedUp           => [self::AtHub, self::OutForDelivery, self::Cancelled],
            self::AtHub              => [self::InTransit, self::OutForDelivery, self::Returning, self::Lost, self::Damaged],
            self::InTransit          => [self::AtHub, self::OutForDelivery, self::Lost, self::Damaged],
            self::OutForDelivery     => [self::Delivered, self::PartiallyDelivered, self::FailedAttempt,
                                         self::Postponed, self::AtHub, self::Lost, self::Damaged],
            self::FailedAttempt      => [self::OutForDelivery, self::AtHub, self::Postponed, self::Returning],
            self::Postponed          => [self::OutForDelivery, self::AtHub, self::Returning],
            self::PartiallyDelivered => [self::Returning, self::AtHub],
            self::Returning          => [self::Returned, self::AtHub],
            self::Delivered, self::Returned, self::Cancelled,
            self::Lost, self::Damaged => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** خيارات الاختيار في الواجهات. */
    public static function options(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label()],
            self::cases()
        );
    }
}

<?php

namespace App\Support\Billing;

use App\Models\PlatformSetting;

/**
 * ما يضبطه صاحب المنصّة من «إعدادات المنصّة» لمال الشركات (docs/plan/36).
 */
final class BillingPolicy
{
    public const PAYMENT_METHODS = 'billing.payment_methods';

    public const GRACE_DAYS = 'billing.grace_days';

    public const REMINDER_DAYS = 'billing.reminder_days';

    /** أيام التنبيه قبل انتهاء الاشتراك أو التجربة، ما لم تُضبط */
    public const DEFAULT_REMINDER_DAYS = 7;

    /** كيف تدفع الشركات: نصٌّ يكتبه صاحب المنصّة، سطرٌ لكل طريقة */
    public static function paymentMethods(): string
    {
        return (string) PlatformSetting::value(self::PAYMENT_METHODS, '');
    }

    /**
     * كم يوماً بعد موعد الفاتورة يتوقّف نظام الشركة إن لم تُسدَّد. null: لا إيقاف تلقائي —
     * وهو الأصل حتى يختار صاحب المنصّة مهلةً بنفسه.
     */
    public static function graceDays(): ?int
    {
        $days = PlatformSetting::value(self::GRACE_DAYS);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    public static function reminderDays(): int
    {
        $days = PlatformSetting::value(self::REMINDER_DAYS);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : self::DEFAULT_REMINDER_DAYS;
    }
}

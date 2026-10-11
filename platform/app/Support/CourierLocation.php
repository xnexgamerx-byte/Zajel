<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Courier;

/**
 * موقع هاتف المندوب الآن (docs/plan/62): يُرسله هاتفه ما دامت شاشته مفتوحة — كلّ دقيقةٍ تقريباً،
 * وكلّما عاد إليها — حدّث شيئاً أو لم يحدّث. فـ«تتبّع المناديب» يُريه حيث هو، لا حيث آخر شحنة.
 *
 * والشركة تُلزم به افتراضاً: لا تُفتح شاشة المندوب والموقع مقفول ما لم تُطفئ الإلزام.
 */
final class CourierLocation
{
    /** موقعٌ أحدث من هذا يُعدّ «الآن» */
    public const LIVE_MINUTES = 10;

    /** وما أقدم من هذا يُعدّ سكوتاً: أُغلقت الشاشة أو انقطع الهاتف */
    public const QUIET_MINUTES = 30;

    public static function required(?Company $company): bool
    {
        return (bool) ($company?->setting('courier.require_location') ?? true);
    }

    public static function record(Courier $courier, float $lat, float $lng): void
    {
        // بلا لمس updated_at: الموقع يتجدّد كلّ دقيقة ولا يعني أن سجلّ المندوب تغيّر
        Courier::query()->whereKey($courier->id)->toBase()->update([
            'last_lat' => round($lat, 7),
            'last_lng' => round($lng, 7),
            'last_seen_at' => now(),
        ]);
    }

    /** موقع المندوب الحيّ إن كان حديثاً — لخريطة شحنته عند التاجر والزبون */
    public static function live(?Courier $courier): ?array
    {
        if (! $courier?->last_lat || ! $courier->last_seen_at?->gt(now()->subMinutes(self::QUIET_MINUTES))) {
            return null;
        }

        return ['lat' => (float) $courier->last_lat, 'lng' => (float) $courier->last_lng, 'exact' => true,
            'at' => $courier->last_seen_at->toIso8601String()];
    }
}

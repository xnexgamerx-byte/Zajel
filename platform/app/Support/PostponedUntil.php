<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * موعد التأجيل (docs/plan/83): يومٌ اتُّفق عليه مع الزبون، ومعه ساعةٌ إن قيلت — «غداً»، «الليلة الساعة
 * 9:00 م». يُحفظ في scheduled_at، ومنتصف الليل يعني اليوم بلا ساعة.
 */
final class PostponedUntil
{
    private const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    /** اليوم من الحقل، وساعته إن كُتبت معه أو وحدها في حقلٍ ثانٍ (HH:MM) */
    public static function parse(?string $day, ?string $time = null): ?Carbon
    {
        if (! filled($day)) {
            return null;
        }

        $at = Carbon::parse($day);

        return filled($time) ? $at->setTimeFromTimeString((string) $time) : $at;
    }

    public static function hasTime(CarbonInterface $at): bool
    {
        return $at->format('H:i') !== '00:00';
    }

    /** «اليوم»، «غداً»، «الخميس 30/10» — والساعة إن كانت: «غداً الساعة 9:00 م» */
    public static function label(CarbonInterface $at): string
    {
        $day = match (true) {
            $at->isToday() => 'اليوم',
            $at->isTomorrow() => 'غداً',
            default => self::DAYS[$at->dayOfWeek].' '.$at->format('j/n'),
        };

        return self::hasTime($at)
            ? $day.' الساعة '.$at->format('g:i').' '.($at->hour < 12 ? 'ص' : 'م')
            : $day;
    }
}

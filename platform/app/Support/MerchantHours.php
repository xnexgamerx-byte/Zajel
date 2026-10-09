<?php

namespace App\Support;

use App\Models\Company;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ساعات مراسلة التاجر للشركة (docs/plan/39): من التاسعة صباحاً إلى الحادية عشرة
 * ليلاً ما لم تغيّرها الشركة في «بيانات الشركة».
 *
 * خارجها لا تُرسَل رسالته — لا تُحفظ لتُرسَل لاحقاً — ويرى في البوابة متى تُفتح.
 * والموظّف يردّ متى شاء: القيد على التاجر وحده.
 *
 * بتوقيت بغداد، والنهاية غير داخلة: «إلى ١١ ليلاً» آخرُ رسالةٍ فيها ١٠:٥٩.
 * ومن ٠ إلى ٢٤ = على مدار اليوم.
 */
final class MerchantHours
{
    public const FROM = 9;

    public const TO = 23;

    public static function from(?Company $company = null): int
    {
        return self::bounds($company)[0];
    }

    public static function to(?Company $company = null): int
    {
        return self::bounds($company)[1];
    }

    public static function allDay(?Company $company = null): bool
    {
        return self::bounds($company) === [0, 24];
    }

    public static function isOpen(?Company $company = null, ?CarbonInterface $at = null): bool
    {
        [$from, $to] = self::bounds($company);
        $hour = (int) self::local($at)->format('G');

        return $hour >= $from && $hour < $to;
    }

    /** متى تُفتح المراسلة: الآن إن كانت مفتوحة، وإلّا أوّل ساعتها اليوم أو غداً */
    public static function opensAt(?Company $company = null, ?CarbonInterface $at = null): Carbon
    {
        $at = self::local($at);
        $from = self::from($company);

        if (self::isOpen($company, $at)) {
            return $at;
        }

        $today = $at->copy()->setTime($from, 0);

        return $at->lt($today) ? $today : $today->addDay();
    }

    /** «من 9 صباحاً إلى 11 ليلاً» */
    public static function window(?Company $company = null): string
    {
        if (self::allDay($company)) {
            return 'على مدار اليوم';
        }

        return 'من '.self::label(self::from($company)).' إلى '.self::label(self::to($company));
    }

    public static function closedMessage(?Company $company = null): string
    {
        return 'مراسلة الشركة '.self::window($company).'، ولم تُرسَل رسالتك. أرسلها بعد '.self::label(self::from($company)).'.';
    }

    /** الساعة بكلام الناس: 9 صباحاً، 12 ظهراً، 4 عصراً، 11 ليلاً */
    public static function label(int $hour): string
    {
        $hour %= 24;
        $shown = $hour % 12 ?: 12;

        return $shown.' '.match (true) {
            $hour === 0 || $hour >= 20 => 'ليلاً',
            $hour < 12                 => 'صباحاً',
            $hour < 15                 => 'ظهراً',
            $hour < 18                 => 'عصراً',
            default                    => 'مساءً',
        };
    }

    /** @return array{0: int, 1: int} */
    private static function bounds(?Company $company): array
    {
        $company ??= Tenancy::company();
        $from = $company?->setting('support.merchant_from');
        $to = $company?->setting('support.merchant_to');

        $from = is_numeric($from) ? (int) $from : self::FROM;
        $to = is_numeric($to) ? (int) $to : self::TO;

        // ما لا يُفهم نافذةً يعود إلى المعتاد، لا يُغلق المراسلة كلّها
        return $from >= 0 && $to <= 24 && $from < $to ? [$from, $to] : [self::FROM, self::TO];
    }

    private static function local(?CarbonInterface $at): Carbon
    {
        return Carbon::instance($at ?? now())->timezone(config('app.timezone'));
    }
}

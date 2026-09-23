<?php

namespace App\Support;

/**
 * أرقام الهواتف العراقية — قاعدةٌ واحدة لكل مَن يقرأ رقماً.
 *
 * الرقم يصل بأشكالٍ شتّى: 0770…، ‎+964 770…، ٠٧٧٠… بأرقامٍ عربية، وبفواصل
 * ومسافات. والنظام يحفظه بصيغةٍ واحدة (07 وتسعة أرقام) ويحوّله عند
 * الحاجة إلى الصيغة الدولية (واتساب لا يقبل الصفر المحلّيّ).
 */
class Phone
{
    /** 07 وتسعة أرقام، أو null إن لم يكن رقماً عراقياً. */
    public static function normalise(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', static::latinDigits((string) $raw));

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00964')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '964')) {
            $digits = '0'.substr($digits, 3);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '7')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^07[0-9]{9}$/', $digits) ? $digits : null;
    }

    /** رابط محادثة واتساب: wa.me بالصيغة الدولية بلا «+» ولا صفر. */
    public static function whatsappUrl(?string $raw, ?string $text = null): ?string
    {
        $local = static::normalise($raw);

        if ($local === null) {
            return null;
        }

        return 'https://wa.me/964'.substr($local, 1)
            .($text !== null && $text !== '' ? '?text='.rawurlencode($text) : '');
    }

    /** الأرقام العربية والفارسية إلى لاتينية. */
    public static function latinDigits(string $raw): string
    {
        return strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}

<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * باركود Code 128 كرسمٍ SVG — ما تقرؤه كل ماسحةٍ وكل تطبيق هاتف.
 *
 * الوصل يُمسح في المخزن وفي الكيس وعند المندوب، فالباركود على الملصق ليس
 * زينة. ورقم الوصل أرقامٌ زوجية الطول غالباً، فيُرمَّز بالمجموعة C (رقمان
 * لكل رمز) فيقصر الباركود إلى النصف ويُقرأ من مسافةٍ أبعد؛ وما سوى ذلك
 * (باركود وصلٍ مطبوع مسبقاً فيه حروف) بالمجموعة B.
 *
 * جدول الأنماط من مواصفة Code 128: لكل رمز ستّة عروض (خطّ، فراغ، …) مجموعها
 * أحد عشر، وللتوقّف سبعة مجموعها ثلاثة عشر.
 */
final class Code128
{
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const START_C = 105;

    private const STOP = 106;

    /** هامشٌ صامت بعرض عشر وحدات من كل جهة: بدونه تخطئ الماسحة بداية الرمز. */
    private const QUIET = 10;

    /**
     * رموز الباركود بالترتيب: البداية، ثم البيانات، ثم رقم التحقّق، ثم التوقّف.
     *
     * @return list<int>
     */
    public static function encode(string $text): array
    {
        if ($text === '') {
            throw new InvalidArgumentException('لا باركود لنصٍّ فارغ.');
        }

        if (preg_match('/^\d+$/', $text) && strlen($text) % 2 === 0) {
            $codes = [self::START_C];
            foreach (str_split($text, 2) as $pair) {
                $codes[] = (int) $pair;
            }
        } else {
            $codes = [self::START_B];
            foreach (str_split($text) as $char) {
                $ord = ord($char);
                if ($ord < 32 || $ord > 126) {
                    throw new InvalidArgumentException('الباركود يقبل الحروف اللاتينية والأرقام فقط.');
                }
                $codes[] = $ord - 32;
            }
        }

        // رقم التحقّق: البداية بوزن ١، ثم كل رمزٍ بوزن موضعه، باقي القسمة على ١٠٣
        $sum = $codes[0];
        foreach (array_slice($codes, 1) as $position => $code) {
            $sum += ($position + 1) * $code;
        }

        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        return $codes;
    }

    /**
     * عروض الخطوط والفراغات متتاليةً، بالوحدات، يبدأ بخطّ.
     *
     * @return list<int>
     */
    public static function modules(string $text): array
    {
        $widths = [];
        foreach (self::encode($text) as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $width) {
                $widths[] = (int) $width;
            }
        }

        return $widths;
    }

    /** للوسم <img>: الصورة تُرسَم من عنوانها، فلا يدخل الصفحةَ HTML خام. */
    public static function dataUri(string $text): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text));
    }

    /** SVG يتمدّد بعرض حاويته؛ الارتفاع يُضبط من الصفحة. */
    public static function svg(string $text): string
    {
        $x = self::QUIET;
        $bars = '';

        foreach (self::modules($text) as $i => $width) {
            if ($i % 2 === 0) {
                $bars .= '<rect x="'.$x.'" width="'.$width.'" height="1"/>';
            }
            $x += $width;
        }

        $total = $x + self::QUIET;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' 1" preserveAspectRatio="none"'
            .' shape-rendering="crispEdges" role="img" aria-label="'.e($text).'">'.$bars.'</svg>';
    }
}

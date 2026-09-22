<?php

namespace App\Support;

/**
 * تمييز العدد العربي.
 *
 * «١٢ أيام» خطأ و«٣ يوماً» خطأ: الثلاثة إلى العشرة تأخذ الجمع، وما فوقها
 * يأخذ المفرد منصوباً، والواحد والاثنان لهما صيغتاهما. وأكثر أنظمة
 * الإدارة العربية تكتب «منذ 12 أيام» لأن الصيغة تُبنى بالسَّلسَلة لا
 * بالقاعدة — وهو أوّل ما يُلاحظه المستخدم العربي.
 */
class Arabic
{
    /** @param  array{string, string, string, string}  $forms  [مفرد, مثنّى, جمع, تمييز منصوب] */
    public static function count(int $n, array $forms): string
    {
        [$one, $two, $few, $many] = $forms;

        return match (true) {
            $n === 1 => $one,
            $n === 2 => $two,
            $n >= 3 && $n <= 10 => "{$n} {$few}",
            default => "{$n} {$many}",
        };
    }

    public static function days(int $n): string
    {
        return static::count($n, ['يوم واحد', 'يومان', 'أيام', 'يوماً']);
    }

    public static function shipments(int $n): string
    {
        return static::count($n, ['شحنة واحدة', 'شحنتان', 'شحنات', 'شحنة']);
    }

    public static function bags(int $n): string
    {
        return static::count($n, ['كيس واحد', 'كيسان', 'أكياس', 'كيساً']);
    }
}

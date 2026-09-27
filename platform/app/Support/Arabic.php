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

    public static function parcels(int $n): string
    {
        return static::count($n, ['طرد واحد', 'طردان', 'طرود', 'طرداً']);
    }

    public static function hours(int $n): string
    {
        return static::count($n, ['ساعة واحدة', 'ساعتان', 'ساعات', 'ساعة']);
    }

    public static function minutes(int $n): string
    {
        return static::count($n, ['دقيقة واحدة', 'دقيقتان', 'دقائق', 'دقيقة']);
    }

    /** مدّة انتظارٍ بأقرب وحدةٍ تُقرأ: دقائق، ثم ساعات، ثم أيام */
    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes < 60        => static::minutes(max(0, $minutes)),
            $minutes < 60 * 48   => static::hours((int) round($minutes / 60)),
            default              => static::days((int) round($minutes / 1440)),
        };
    }

    public static function merchants(int $n): string
    {
        return static::count($n, ['تاجر واحد', 'تاجران', 'تجّار', 'تاجراً']);
    }

    /**
     * الاسم مطويّاً للمقارنة لا للعرض: «الأعظمية» و«الاعظميه» و«الـأعظمية» اسمٌ
     * واحد، و«الدورة - الصحة» هي «الدورة/الصحة» و«الدورة الصحة».
     *
     * الهمزات والتاء المربوطة والألف المقصورة يكتبها كلٌّ على هواه، والتشكيل
     * والتطويل زينة، والفواصل بين جزأي الاسم تختلف من كاتبٍ لآخر.
     */
    public static function fold(?string $name): string
    {
        $text = strtr(Phone::latinDigits((string) $name), [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);

        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text);
        $text = preg_replace('/[\/\\\\\-_*().,،]+/u', ' ', $text);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * اسم اليوم بلا «ال» — «سبت»، «أحد» — لتسميات الرسوم الضيّقة. ثابتٌ هنا لا من
     * ترجمة Carbon: صيغتها القصيرة للعربية حرفٌ واحد («س»، «ج») لا يُقرأ.
     */
    public static function weekday(\DateTimeInterface $date): string
    {
        return ['أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'][(int) $date->format('w')];
    }
}

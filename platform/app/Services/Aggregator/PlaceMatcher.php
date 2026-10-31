<?php

namespace App\Services\Aggregator;

use App\Support\Arabic;

/**
 * يطابق أسماء محافظاتنا ومناطقنا بأسماء مدنهم ومناطقهم (docs/plan/104) بطيّ الأسماء نفسه في
 * استيراد المناطق (Arabic::looseFold): بلا «ال» ولا «حي» ولا تشكيل، والحروف الفارسية عربيّة.
 * لا يطابق إلّا اسماً واحداً بعينه؛ ما يحتمل أكثر يُترك للموظّف يختاره.
 */
class PlaceMatcher
{
    public static function normalise(?string $name): string
    {
        return Arabic::looseFold($name);
    }

    /**
     * @param  array<string, string>  $options  رقمهم ← اسمهم
     */
    public static function best(string $name, array $options): ?string
    {
        $wanted = self::normalise($name);

        if ($wanted === '') {
            return null;
        }

        $same = array_keys(array_filter($options, fn ($option) => self::normalise($option) === $wanted));

        return count($same) === 1 ? (string) $same[0] : null;
    }
}

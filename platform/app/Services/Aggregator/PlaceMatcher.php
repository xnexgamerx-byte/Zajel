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
        return self::lookup($name, $options)[0];
    }

    /**
     * رقمهم إن وُجد اسمٌ واحدٌ بعينه، وحال المطابقة: matched، أو ambiguous (يشبه أكثر من اسمٍ عندهم)،
     * أو none (لا يشبه شيئاً). الغامض والمفقود لا يُربطان بالتخمين: يختارهما الموظّف.
     *
     * @param  array<string, string>  $options  رقمهم ← اسمهم
     * @return array{0: ?string, 1: 'matched'|'ambiguous'|'none'}
     */
    public static function lookup(string $name, array $options): array
    {
        $wanted = self::normalise($name);

        if ($wanted === '') {
            return [null, 'none'];
        }

        $same = array_keys(array_filter($options, fn ($option) => self::normalise($option) === $wanted));

        return match (count($same)) {
            0 => [null, 'none'],
            1 => [(string) $same[0], 'matched'],
            default => [null, 'ambiguous'],
        };
    }
}

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

    /**
     * أسماء مناطقهم مطويّةً بأرقامها: يُبنى مرّةً لكل مدينةٍ لا لكل منطقةٍ عندنا.
     *
     * @param  array<string, string>  $options  رقمهم ← اسمهم
     * @return array<string, list<string>>
     */
    public static function index(array $options): array
    {
        $index = [];

        foreach ($options as $id => $name) {
            $index[self::normalise($name)][] = (string) $id;
        }

        return $index;
    }

    /**
     * أطول بدايةٍ من اسمنا تطابق منطقةً واحدةً عندهم: «دجيل الحاتمية» هي «دجيل»، و«مدينة الصدر حي طارق»
     * هي «مدينة الصدر» لا «مدينة». لا يُخمَّن إلّا بكلمةٍ كاملة من أول الاسم، وبمنطقةٍ واحدة بعينها:
     * بدايةٌ تطابق اسمين عندهم، أو اسمٌ كاملٌ يحتمل أكثر من منطقة، يُترك للموظّف.
     *
     * الحال matched إن طابق الاسم كاملاً، وprefix إن طابقت بدايته، وإلّا ambiguous أو none.
     *
     * @param  array<string, list<string>>  $index  من index()
     * @return array{0: ?string, 1: 'matched'|'prefix'|'ambiguous'|'none'}
     */
    public static function lookupPrefix(string $name, array $index): array
    {
        $full = self::normalise($name);
        $words = array_values(array_filter(explode(' ', $full), fn ($w) => $w !== ''));

        // الاسم كاملاً أوّلاً: ما تحتمله منطقتان لا يُقصَّر إلى بدايةٍ فيُربط بالتخمين
        $exact = $index[$full] ?? [];
        if (count($exact) > 1) {
            return [null, 'ambiguous'];
        }
        if (count($exact) === 1) {
            return [$exact[0], 'matched'];
        }

        for ($length = count($words) - 1; $length >= 1; $length--) {
            $ids = $index[implode(' ', array_slice($words, 0, $length))] ?? [];

            if (count($ids) > 1) {
                return [null, 'ambiguous'];
            }
            if (count($ids) === 1) {
                return [$ids[0], 'prefix'];
            }
        }

        return [null, 'none'];
    }
}

<?php

namespace App\Services\Orders;

use App\Support\Phone;

/**
 * الطلب بصوت التاجر (docs/plan/40): ما سمعه المتصفّح — أو مايك لوحة المفاتيح — نصّاً
 * متّصلاً بلا أسطر ولا فواصل، والأرقام فيه كلماتٌ غالباً، يُعاد أسطراً كما يُكتب الطلب
 * فيقرؤه OrderReader نفسه:
 *
 *   «الاسم علي حسين الرقم صفر سبعة سبعة صفر … بغداد الكرادة قرب الجامع المبلغ خمسة وعشرين الف»
 *   ← الاسم: علي حسين ⏎ الهاتف: 0770… ⏎ بغداد الكرادة قرب الجامع ⏎ المبلغ 25 الف
 *
 * الأرقام بالعراقيّ والفصيح: «ثنين» و«اثنان»، «خمسطعش» و«خمسة عشر»، «ميتين» و«مئتان»،
 * و«دبل سبعة» في الهاتف سبعتان. والمبلغ بالآلاف كما يُقال: «خمسة وعشرين الف ونص» ٢٥٬٥٠٠.
 */
final class SpokenOrder
{
    private const UNITS = [
        'صفر' => 0,
        'واحد' => 1, 'واحده' => 1, 'وحده' => 1,
        'اثنين' => 2, 'اثنان' => 2, 'ثنين' => 2, 'اتنين' => 2, 'اثنتين' => 2, 'ثنتين' => 2,
        'ثلاثه' => 3, 'ثلاث' => 3, 'تلاثه' => 3, 'تلاث' => 3, 'ثلث' => 3,
        'اربعه' => 4, 'اربع' => 4,
        'خمسه' => 5, 'خمس' => 5,
        'سته' => 6, 'ست' => 6,
        'سبعه' => 7, 'سبع' => 7,
        'ثمانيه' => 8, 'ثمانية' => 8, 'ثمان' => 8, 'ثماني' => 8, 'ثمنيه' => 8, 'ثمن' => 8, 'تمانيه' => 8,
        'تسعه' => 9, 'تسع' => 9,
    ];

    private const TEENS = [
        'عشره' => 10, 'عشر' => 10,
        'احدعش' => 11, 'حدعش' => 11, 'اثنعش' => 12, 'ثنعش' => 12, 'اطنعش' => 12,
        'ثلطعش' => 13, 'ثلاثطعش' => 13, 'ثلثطعش' => 13, 'اربعطعش' => 14, 'اربطعش' => 14, 'خمسطعش' => 15,
        'ستطعش' => 16, 'سطعش' => 16, 'سبعطعش' => 17, 'ثمنطعش' => 18, 'ثمانطعش' => 18, 'تسعطعش' => 19,
    ];

    private const TENS = [
        'عشرين' => 20, 'عشرون' => 20, 'ثلاثين' => 30, 'ثلاثون' => 30, 'تلاثين' => 30, 'اربعين' => 40, 'اربعون' => 40,
        'خمسين' => 50, 'خمسون' => 50, 'ستين' => 60, 'ستون' => 60, 'سبعين' => 70, 'سبعون' => 70,
        'ثمانين' => 80, 'ثمانون' => 80, 'ثمنين' => 80, 'تسعين' => 90, 'تسعون' => 90,
    ];

    private const HUNDREDS = [
        'ميه' => 100, 'مئه' => 100, 'مائه' => 100, 'مية' => 100, 'ميتين' => 200, 'مئتين' => 200, 'مئتان' => 200, 'متين' => 200,
        'ثلاثميه' => 300, 'ثلثميه' => 300, 'تلثميه' => 300, 'اربعميه' => 400, 'خمسميه' => 500, 'ستميه' => 600,
        'سبعميه' => 700, 'ثمنميه' => 800, 'ثمانميه' => 800, 'تسعميه' => 900,
    ];

    /** «ثلاث تساعات» ثلاث تسعات (999)، و«اربع اصفار» 0000 — في الهاتف. مطويّة */
    private const PLURALS = [
        'اصفار' => 0, 'صفار' => 0, 'صفرات' => 0, 'واحدات' => 1, 'ثنينات' => 2, 'اثنينات' => 2, 'ثلاثات' => 3, 'تلاثات' => 3,
        'اربعات' => 4, 'خمسات' => 5, 'ستات' => 6, 'سبعات' => 7, 'ثمانيات' => 8, 'ثمنات' => 8, 'تساعات' => 9, 'تسعات' => 9,
    ];

    private const THOUSANDS = ['الف' => 1_000, 'الاف' => 1_000, 'الفين' => 2_000, 'الفان' => 2_000, 'مليون' => 1_000_000, 'ملايين' => 1_000_000];

    /** كلماتٌ تبدأ حقلاً: يُبدأ عندها سطرٌ جديد بعنوانه كما يُكتب. مكتوبةٌ مطويّة، ويوسّعها tolerant() */
    private const LABELS = [
        // الاسم
        '(?:اسم (?:الزبون|المستلم|العميل|الزبونه)|الزبون اسمه|الزبونه اسمها|اسمه|اسمها|الاسم)' => 'الاسم:',
        // الهاتف البديل قبل الهاتف: «الرقم الثاني» لا «الرقم»
        '(?:(?:ال)?رقم (?:ال)?(?:ثاني|بديل|الاحتياط)|رقمه الثاني|رقمها الثاني)' => 'هاتف بديل:',
        '(?:رقم (?:الهاتف|التلفون|الموبايل|الزبون)|رقمه|رقمها|الرقم|التلفون|تلفونه|تلفونها|الموبايل|موبايله|موبايلها|الهاتف|هاتفه|هاتفها)' => 'الهاتف:',
        '(?:عنوانه|عنوانها|العنوان|ساكن|ساكنه|يسكن|تسكن|سكنه|سكنها)' => 'العنوان:',
        '(?:مبلغها|مبلغه|المبلغ|سعرها|سعره|السعر|بسعر|حسابها|حسابه|الحساب|المطلوب)' => 'المبلغ',
        '(?:عدد القطع|عددها|عدده|العدد)' => 'العدد',
        '(?:الملاحظه|ملاحظه|ملاحظات|ملاحظتي)' => 'ملاحظة:',
    ];

    /**
     * كلماتٌ عدديّة تكون اسماً أو كلمةً أخرى وحدها («ست زينب»، «سبع» اسم): تُقرأ رقماً إن
     * جاء بعدها معدودٌ أو قبلها كلمة المبلغ أو العدد فقط
     */
    private const AMBIGUOUS = ['ست', 'سبع', 'خمس', 'عشر', 'ثمن', 'ثلث', 'تسع', 'اربع', 'ثلاث', 'ثمان', 'تلاث', 'ميه', 'مية'];

    private const COUNTED = '/^(?:قطع|قطعه|قطعة|حبات|حبه|حبة|الاف|آلاف|الف|ألف|دينار|الاف)$/u';

    private const COUNTING = '/^(?:المبلغ|مبلغ|مبلغها|مبلغه|السعر|سعر|سعرها|سعره|بسعر|الحساب|حسابها|العدد|عدد|عددها|عدده|المطلوب)$/u';

    public static function normalise(string $speech): string
    {
        $text = Phone::latinDigits($speech);
        // ما يضعه بعض المتصفّحات من فواصل حدودُ حقول
        $text = (string) preg_replace('/\s*[،,؛;.!؟?]+(?:\s+|$)|\s*\R\s*/u', "\n", $text);

        $lines = [];
        foreach (explode("\n", $text) as $part) {
            $part = trim(self::numbers($part));
            if ($part !== '') {
                $lines[] = $part;
            }
        }
        $text = implode("\n", $lines);

        foreach (self::LABELS as $pattern => $label) {
            $text = (string) preg_replace('/(?<=^|\s)'.self::tolerant($pattern).'(?=\s|$)\s*[:：]?\s*/u', "\n".$label.' ', $text);
        }

        // رقم الهاتف سطرٌ وحده، والمبلغ بآلافه سطرٌ وحده: ما قبلهما اسمٌ وما بينهما عنوان
        $text = (string) preg_replace('/(?<![\d:])((?:\+?964|0)?\s*7\d(?:\s?\d){8})(?!\d)/u', "\n$1\n", $text);
        $text = (string) preg_replace('/(?<=\s|^)(?<!المبلغ )(?<!العدد )(\d+(?:\.\d{1,2})? الف)(?=\s|$)/u', "\n$1", $text);

        // عددٌ صغير في آخر الكلام بلا كلمة المبلغ مبلغٌ بالآلاف: «… قرب الجامع خمسة وعشرين»
        $text = (string) preg_replace('/(?<!العدد)(?<!المبلغ)\s+(\d{1,3})\s*$/u', "\n$1", trim($text));

        return implode("\n", array_values(array_filter(array_map(
            fn (string $line) => trim((string) preg_replace('/\s+/u', ' ', $line), " \t:"),
            explode("\n", $text),
        ), fn (string $line) => $line !== '' && ! preg_match('/^(?:الاسم|الهاتف|هاتف بديل|العنوان|ملاحظة|المبلغ|العدد)$/u', $line))));
    }

    /** نمطٌ مكتوبٌ مطويّاً يطابق الكلمة بأيّ شكلٍ كُتبت: ا بأخواتها، وه بالتاء المربوطة، وي بالألف المقصورة */
    private static function tolerant(string $pattern): string
    {
        return strtr($pattern, ['ا' => '[اأإآ]', 'ه' => '[هة]', 'ي' => '[يى]']);
    }

    /** أشكال الحرف الواحد شكلٌ واحد كي تُطابق الكلمات: أ إ آ ← ا، ة ← ه، ى ← ي */
    private static function fold(string $text): string
    {
        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ـ' => '']);
    }

    /**
     * كلمات الأرقام أرقاماً: سلسلة آحادٍ متتالية («صفر سبعة سبعة …») أرقامُ هاتفٍ تُلصق،
     * وما سواها عددٌ يُجمع («مية وخمسين الف» ١٥٠٠٠٠). والمبلغ من ألفٍ فأكثر يُكتب بالآلاف
     * («150 الف») كما يكتبه التاجر.
     */
    private static function numbers(string $text): string
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: [], fn ($t) => $t !== ''));
        $out = [];
        $run = [];

        $flush = function () use (&$run, &$out) {
            if ($run !== []) {
                $out[] = self::spell($run);
                $run = [];
            }
        };

        $join = false;
        foreach ($tokens as $i => $token) {
            $word = self::numberWord($token);
            $next = $tokens[$i + 1] ?? '';
            if ($word !== null && $join) {
                $word['joined'] = true;
            }
            $join = false;

            // «الف» بعد رقمٍ مكتوب («25 الف») يبقى كلمةً يقرؤها OrderReader
            if ($word !== null && $run === [] && $word['kind'] === 'thousand' && $out !== [] && preg_match('/\d$/', (string) end($out))) {
                $word = null;
            }

            // «ست زينب»: كلمةٌ عدديّة وحدها بين كلماتٍ ليست أرقاماً تبقى كما قيلت
            if ($word !== null && $run === [] && in_array(self::fold($word['word']), self::AMBIGUOUS, true)
                && self::numberWord($next) === null && ! preg_match(self::COUNTED, $next)
                && ! preg_match(self::COUNTING, self::fold((string) end($out)))) {
                $word = null;
            }

            if ($word === null) {
                // «و» وحدها بين كلمتي عدد وصلٌ لا كلمة: «اربعمية و عشرة» كـ«اربعمية وعشرة»
                if ($token === 'و' && $run !== [] && self::numberWord($next) !== null) {
                    $join = true;

                    continue;
                }
                $flush();
                $out[] = $token;

                continue;
            }

            $run[] = $word;
        }
        $flush();

        return implode(' ', $out);
    }

    /** @return ?array{word: string, value: int, joined: bool, digit: bool, kind: string} */
    private static function numberWord(string $token): ?array
    {
        $token = self::fold($token);

        foreach ([$token, preg_replace('/^و(?=\S{2,})/u', '', $token), preg_replace('/^ب(?=\S{3,})/u', '', $token)] as $i => $candidate) {
            foreach (['unit' => self::UNITS, 'teen' => self::TEENS, 'ten' => self::TENS, 'hundred' => self::HUNDREDS,
                'thousand' => self::THOUSANDS] as $kind => $words) {
                if (isset($words[$candidate])) {
                    return ['word' => $candidate, 'value' => $words[$candidate], 'joined' => $i === 1,
                        'digit' => $kind === 'unit', 'kind' => $kind];
                }
            }
            if (isset(self::PLURALS[$candidate])) {
                return ['word' => $candidate, 'value' => self::PLURALS[$candidate], 'joined' => $i === 1, 'digit' => false, 'kind' => 'plural'];
            }
            if (in_array($candidate, ['نص', 'ونص', 'نصف'], true)) {
                return ['word' => 'نص', 'value' => 0, 'joined' => true, 'digit' => false, 'kind' => 'half'];
            }
            if (in_array($candidate, ['دبل', 'ترابل', 'تربل'], true)) {
                return ['word' => $candidate, 'value' => $candidate === 'دبل' ? 2 : 3, 'joined' => false, 'digit' => false, 'kind' => 'repeat'];
            }
        }

        return null;
    }

    /**
     * سلسلةٌ من كلمات العدد نصّاً.
     *
     * @param list<array{word: string, value: int, joined: bool, digit: bool, kind: string}> $run
     */
    private static function spell(array $run): string
    {
        // هاتفٌ يُقال مقاطع: «صفر سبعة سبعة اربعمية وعشرة سبعة سبعة ثلاث تساعات» 07741077999
        $grouped = self::grouped($run);
        if ($grouped !== null && preg_match('/^(?:0?7\d{9}|0\d{6,})$/', $grouped)) {
            return $grouped;
        }

        // أرقام هاتفٍ أو رمزٍ تُقرأ رقماً رقماً: آحادٌ بلا «و» بينها، و«دبل/تربل» تكرّر ما بعدها
        $digits = count(array_filter($run, fn (array $w) => $w['digit'] || $w['kind'] === 'repeat')) === count($run)
            && count(array_filter($run, fn (array $w) => $w['joined'])) === 0;

        if ($digits && count($run) >= 2) {
            $spelled = '';
            $repeat = 1;
            foreach ($run as $word) {
                if ($word['kind'] === 'repeat') {
                    $repeat = $word['value'];

                    continue;
                }
                $spelled .= str_repeat((string) $word['value'], $repeat);
                $repeat = 1;
            }

            return $spelled;
        }

        $total = 0;
        $current = 0;
        $half = false;
        $last = 0;
        foreach ($run as $word) {
            switch ($word['kind']) {
                case 'thousand':
                    $multiplier = $word['value'] > 2_000 ? $word['value'] : 1_000;
                    $count = $word['value'] === 2_000 ? 2 : max(1, $current);
                    $total += $count * $multiplier;
                    $current = 0;
                    $last = $multiplier;
                    break;
                case 'hundred':
                    // «ثلاث مية» ثلاثمئة، و«مية» وحدها مئة
                    $current = $current > 0 && $current < 10 && $word['value'] === 100 ? $current * 100 : $current + $word['value'];
                    break;
                case 'half':
                    $half = true;
                    break;
                case 'repeat':
                    break;
                default:
                    $current += $word['value'];
            }
        }

        // «ونص» نصف ما قبله: بعد الألف خمسمئة، وبعد المليون نصف مليون
        $value = $total + $current + ($half && $last > 0 && $current === 0 ? intdiv($last, 2) : 0);

        if ($value >= 1_000) {
            $thousands = $value / 1_000;

            return rtrim(rtrim(number_format($thousands, 2, '.', ''), '0'), '.').' الف';
        }

        return (string) $value;
    }

    /**
     * السلسلة مقاطع كما يُقال الهاتف: كلّ مقطعٍ عددٌ («اربعمية وعشرة» 410، «سبعة وسبعين» 77، «صفر» 0)،
     * والمقاطع تُلصق. «ثلاث تساعات» 999، و«دبل سبعة» 77. وما فيه ألفٌ أو نصفٌ ليس هاتفاً (null).
     *
     * @param list<array{word: string, value: int, joined: bool, digit: bool, kind: string}> $run
     */
    private static function grouped(array $run): ?string
    {
        /** @var list<array{text?: string, words?: list<array>}> $groups */
        $groups = [];
        $repeat = null;

        foreach ($run as $word) {
            if (in_array($word['kind'], ['thousand', 'half'], true)) {
                return null;
            }
            if ($word['kind'] === 'repeat') {
                $repeat = $word['value'];

                continue;
            }
            if ($word['kind'] === 'plural') {
                // «ثلاث تساعات»: العدد قبلها مقطعٌ من رقمٍ واحد يصير مرّات
                $last = end($groups);
                $times = $last !== false && isset($last['words']) && count($last['words']) === 1
                    && $last['words'][0]['kind'] === 'unit' && $last['words'][0]['value'] >= 2 ? $last['words'][0]['value'] : null;
                if ($times !== null) {
                    array_pop($groups);
                }
                $groups[] = ['text' => str_repeat((string) $word['value'], $times ?? 1)];

                continue;
            }
            if ($repeat !== null && $word['kind'] === 'unit') {
                $groups[] = ['text' => str_repeat((string) $word['value'], $repeat)];
                $repeat = null;

                continue;
            }
            $repeat = null;

            $last = end($groups);
            $continues = $last !== false && isset($last['words']) && ($word['joined']
                // «ثلاث مية»: آحادٌ ثم «مية» مضاعفُها
                || ($word['kind'] === 'hundred' && $word['value'] === 100 && count($last['words']) === 1 && $last['words'][0]['kind'] === 'unit'));

            if ($continues) {
                $groups[array_key_last($groups)]['words'][] = $word;
            } else {
                $groups[] = ['words' => [$word]];
            }
        }

        $spelled = '';
        foreach ($groups as $group) {
            $spelled .= $group['text'] ?? (string) self::sum($group['words']);
        }

        return $spelled === '' ? null : $spelled;
    }

    /** @param list<array{word: string, value: int, joined: bool, digit: bool, kind: string}> $words */
    private static function sum(array $words): int
    {
        $value = 0;
        foreach ($words as $word) {
            $value = $word['kind'] === 'hundred' && $word['value'] === 100 && $value > 0 && $value < 10
                ? $value * 100
                : $value + $word['value'];
        }

        return $value;
    }
}

<?php

namespace App\Support;

/**
 * «رمز الاستلام» المطبوع على الورقة التي تصل التاجر مع ماله أو مع رواجعه (docs/plan/69).
 *
 * لا يظهر في البوابة ولا في التطبيق: من يمسحه يثبت أنّ الورقة بيده — فلا يؤكّد استلام
 * دفعةٍ أو راجعٍ لم يصله. عشرة أحرفٍ من أبجديةٍ بلا ما يشتبه (O/0 وI/1)، نحو خمسين بتّاً:
 * لا يُخمَّن، ويُكتب باليد إن تعذّرت الكاميرا.
 */
final class ReceiptCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 10;

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** كما يُطبع: خمسةٌ وخمسة، فيُقرأ ويُكتب بلا خطأ */
    public static function format(string $code): string
    {
        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    /**
     * ما مُسح أو كُتب إلى الرمز نفسه: رابط الورقة كاملاً (/rc/…)، أو الرمز بشرطته أو بلا
     * شرطة، بأحرفٍ صغيرة أو كبيرة. وما ليس رمزاً يعود null.
     */
    public static function normalize(?string $scanned): ?string
    {
        $text = trim((string) $scanned);

        if (preg_match('~/rc/([A-Za-z0-9-]+)~', $text, $m)) {
            $text = $m[1];
        }

        $code = strtoupper(preg_replace('/[\s-]+/', '', $text));

        return preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) ? $code : null;
    }

    public static function matches(?string $stored, ?string $scanned): bool
    {
        $code = self::normalize($scanned);

        return $stored !== null && $code !== null && hash_equals($stored, $code);
    }
}

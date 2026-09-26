<?php

namespace App\Support;

/**
 * أسماء المستخدمين — بها يُدخَل النظام، لا بالهاتف.
 *
 * صيغةٌ واحدة تُحفَظ ويُبحث بها ويُحسب عليها حدّ المحاولات: حروفٌ لاتينية
 * صغيرة وأرقام و«.» و«_» و«-» (مثل ali.salam). MySQL (utf8mb4_unicode_ci)
 * ترى «ALI» و«ａｌｉ» و«ali » اسماً واحداً مع «ali»، فلو وصلت القاعدةَ كما
 * كُتبت لكان لكل صيغةٍ حدُّ محاولاتٍ جديد على الحساب نفسه — كما كان في
 * الهاتف (Phone). والحروف العربية ليست فيها: «أحمد» و«احمد» و«إحمد» يراها
 * الناس اسماً واحداً ولا تراها القاعدة كذلك.
 *
 * ولكل حسابٍ بلا اسمٍ مختار رقمُ هاتفه اسماً (User::booted)، فالأرقام العربية
 * تُقرأ لاتينية كما في الهاتف.
 */
class Username
{
    /** ٣ إلى ٣٢ حرفاً، أوّلها وآخرها حرفٌ أو رقم. */
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]{1,30}[a-z0-9]$/';

    public const RULE_MESSAGE = 'اسم المستخدم: حروفٌ إنجليزية وأرقام و«.» و«_» و«-»، من ٣ إلى ٣٢، أوّله وآخره حرفٌ أو رقم.';

    /** الصيغة المحفوظة: بلا مسافات حولها، وأرقامٌ لاتينية، وحروفٌ صغيرة. */
    public static function canonical(?string $raw): string
    {
        return strtolower(Phone::latinDigits(trim((string) $raw)));
    }

    /** الاسم بصيغته المحفوظة، أو null إن لم يكن اسماً صالحاً — فلا يصل القاعدة. */
    public static function normalise(?string $raw): ?string
    {
        $name = static::canonical($raw);

        return preg_match(static::PATTERN, $name) ? $name : null;
    }
}

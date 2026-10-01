<?php

namespace App\Http\Middleware;

use App\Support\Phone;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * الأرقام كما تُكتب في العراق تُقبل كما تُحسب.
 *
 * الحقل الرقميّ يُكتب «60 000» بفاصلٍ كل ثلاث خانات (number-inputs.js)، أو بأرقامٍ
 * عربية «٦٠٬٠٠٠» من لوحة مفاتيح الهاتف. والنموذج يُرسله خاناتٍ إنجليزية — وهذا
 * لما يصل بغير ذلك (نموذجٌ قبل تحميل الصفحة، أو مُرسَلٌ من خارجها): قيمةٌ كلّها
 * أرقامٌ وفواصل تصير أرقاماً وحدها، والكسر يبقى كسراً («٢٫٥» ← 2.5).
 *
 * وحقول الهاتف (والواتساب وهاتف الشكاوى) تصير 07 وتسعة أرقام إن كانت رقماً عراقياً
 * بأيّ صيغة (‎+964 …، 00964 …، بلا الصفر) — وما لا يصير يُترك لقاعدة التحقّق تقول لماذا.
 *
 * وما فيه حرفٌ لا يُمَسّ — عنوانٌ أو اسمٌ أو ملاحظة — ولا كلمة مرور ولو كانت أرقاماً.
 */
class NormaliseDigits extends TransformsRequest
{
    protected function transform($key, $value)
    {
        if (! is_string($value) || $value === '' || str_contains((string) $key, 'password')) {
            return $value;
        }

        $latin = strtr(Phone::latinDigits($value), ['٫' => '.', '٬' => ',']);

        if (preg_match('/(phone|whatsapp|complaints)/', (string) $key)) {
            return Phone::normalise($latin) ?? $value;
        }

        $latin = trim($latin);

        // تجميع آلافٍ صحيح لا غير: خاناتٌ ثلاثٌ بعد كل فاصل («1 000 000»، «60,000»).
        // فوصولاتٌ ملصوقةٌ بمسافاتٍ أو أسطر («180089 180090») لا تُدمَج رقماً واحداً
        if (preg_match('/^-?\d{1,3}([ ,\x{00A0}\x{202F}]\d{3})+(\.\d+)?$/u', $latin)) {
            return preg_replace('/[ ,\x{00A0}\x{202F}]/u', '', $latin);
        }

        // رقمٌ بأرقامٍ عربية: خاناتٌ إنجليزية. والنصّ فيه أرقامٌ عربية يبقى كما كُتب
        if ($latin !== trim($value) && preg_match('/^-?\d+(\.\d+)?$/', $latin)) {
            return $latin;
        }

        return $value;
    }
}

<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * فحوص بنيوية على القوالب — أخطاء لا يكشفها اختبار وظيفيّ لأن الصفحة
 * تُفتح وتعمل، لكنّ المستخدم يصطدم بها.
 *
 * step ليس تلميحاً لأسهم الزيادة بل قاعدة تحقّق يفرضها المتصفّح، وهي
 * تُقاس من min لا من الصفر. مع min="1" و step="250" تصير القيم
 * المقبولة ١ ثم ٢٥١ ثم ٥٠١، فيُرفض ٧٥٬٠٠٠ بلا رسالة يفهمها أحد. ومع
 * min="0" يُرفض سعر شائع مثل ٢٤٬٩٠٠ لأنه ليس من مضاعفات ٢٥٠، ووزن
 * مثل ١٧٥٠ غراماً لأنه ليس من مضاعفات ١٠٠.
 *
 * والراحة المرجوّة من أسهم الزيادة لا تساوي رفض قيمة صحيحة يكتبها
 * المستخدم بيده.
 */
class ViewGuardsTest extends TestCase
{
    public function test_no_number_field_rejects_valid_values_through_its_step(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<input[^>]*type="number"[^>]*>/s', $file->getContents(), $matches);

            foreach ($matches[0] as $input) {
                if (! preg_match('/step="([^"]*)"/', $input, $step)) {
                    continue;
                }

                // الكسور مقصودة أحياناً (نسبة مئوية)، أمّا خطوة صحيحة
                // غير 1 فترفض قيماً صحيحة. وخطوة مُدرَجة من متغيّر لا
                // يمكن التحقّق منها هنا، فتُعامَل معاملة المشبوه.
                if ($step[1] === '1' || (is_numeric($step[1]) && (float) $step[1] < 1)) {
                    continue;
                }

                preg_match('/name="([^"]+)"/', $input, $name);
                $offenders[] = $file->getRelativePathname().' → '.($name[1] ?? '?').' (step='.$step[1].')';
            }
        }

        $this->assertSame([], $offenders, "حقول تمنع قيماً صحيحة:\n".implode("\n", $offenders));
    }

    /**
     * صيغة diffForHumans المختصرة بلا ترجمة في ar_IQ، فتسقط إلى
     * الإنجليزية: «منذ 5d» و«منذ 1w» وسط شاشة عربية. والصيغة الكاملة
     * مترجَمة صحيحةً، فلا داعي للمختصرة أصلاً.
     */
    public function test_no_view_uses_the_untranslated_short_time_format(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains($file->getContents(), 'diffForHumans(short')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, "صيغة وقت مختصرة بلا ترجمة:\n".implode("\n", $offenders));
    }

    /**
     * العدد والمعدود بقاعدة لا بسَلسَلة.
     *
     * «{{ $n }} شحنة» صحيحةٌ فوق العشرة وحدها: «5 شحنة» و«1 شحنة» و«2 يوم»
     * أوّل ما يلاحظه القارئ العربي. وكانت في أحد عشر موضعاً، منها سطرٌ في
     * الفاتورة التي تدفعها الشركة. فكل رقمٍ متغيّرٍ يسبق معدوداً يمرّ
     * بـ App\Support\Arabic.
     */
    public function test_no_counted_noun_follows_a_raw_number(): void
    {
        $nouns = 'شحنة|شحنات|يوم|أيام|يوماً|تاجر|تجّار|كيس|أكياس|مندوب|مناديب|طرد|طرود';
        // نهاية طباعة رقمٍ متغيّر: }} في القالب، أو {$x} / $x في نصٍّ PHP
        $pattern = '/(\}\}|\{\$[^}]+\}|\$[a-z_]+(?:->[a-z_]+)*)\s*('.$nouns.')(?![\p{Arabic}])/u';

        $offenders = [];

        foreach ([resource_path('views'), app_path()] as $root) {
            foreach (File::allFiles($root) as $file) {
                foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
                    if (str_contains($line, 'Arabic::') || preg_match('/^\s*(\/\/|\*|\||\{\{--)/', $line)) {
                        continue;
                    }

                    if (preg_match($pattern, $line)) {
                        $offenders[] = $file->getRelativePathname().':'.($n + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "رقمٌ متغيّر قبل معدودٍ بلا Arabic:\n".implode("\n", $offenders));
    }
}

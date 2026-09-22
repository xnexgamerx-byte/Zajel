<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * فحص بنيوي على الحقول الرقمية.
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
class NumberInputsTest extends TestCase
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
}

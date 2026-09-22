<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * فحص بنيويّ على شكل الاستعلامات.
 *
 * الخطأ هنا لا يُرى في شاشة ولا يُسقط اختباراً: كل شيء يعمل على مئة
 * صفّ، ويتهاوى على مئة ألف. وقياسه يحتاج بيانات لا تكون في الاختبارات،
 * فيُحرَس الشكل بدل الزمن.
 */
class QueryShapeTest extends TestCase
{
    /**
     * whereDate تلفّ العمود بدالّة فيسقط فهرسه ويُمسح الجدول.
     * قيست على ١٨٠ ألف شحنة: ٤٠٧ مللي ثانية مقابل ٣ بمدى.
     */
    public function test_no_query_wraps_a_date_column_in_a_function(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $body = $file->getContents();

            // التعليق الشارح مستثنى: هو يشرح لماذا لا تُستعمل
            $code = preg_replace('/\/\*.*?\*\/|\/\/[^\n]*|\*[^\n]*/s', '', $body);

            if (str_contains($code, '->whereDate(')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "استعلامات تُلغي الفهرس بـ whereDate — استعمل whereOnDate أو whereFromDate:\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * «ليس ضمن» على الحالة لا يقفز في الفهرس، و«ضمن» يقفز.
     * قيست: ٢٤٢ مللي ثانية مقابل ٧.
     */
    public function test_no_query_filters_open_shipments_by_excluding_terminal_ones(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match("/whereNotIn\(\s*'status'/", $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "استعمل whereIn بـ ShipmentStatus::openValues() بدل استثناء النهائية:\n"
            .implode("\n", $offenders),
        );
    }
}

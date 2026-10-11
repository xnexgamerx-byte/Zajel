<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * قراءة صورٍ كثيرة مرّةً واحدة في تطبيق التاجر (docs/plan/63): كلّ صورةٍ طلبٌ مستقلّ إلى القارئ نفسه،
 * والتطبيق يرسل منها بقدر نوى الخادم معاً — فتُقرأ ثلاثون لقطة في ثوانٍ على خادمٍ بثماني نوى.
 */
final class BatchReading
{
    public const DEFAULT_MAX = 30;

    public const LIMIT = 50;

    /** أكثر الصور في الدفعة الواحدة: من «بيانات الشركة»، وثلاثون افتراضاً */
    public static function max(?Company $company): int
    {
        $max = (int) ($company?->setting('reading.batch_max') ?? self::DEFAULT_MAX);

        return max(1, min(self::LIMIT, $max ?: self::DEFAULT_MAX));
    }

    /**
     * كم صورةً تُقرأ معاً: نوى الخادم (القارئ المجّاني نواةٌ لكلّ صورة)، بين اثنتين وثمانٍ — وتُضبط
     * بـ ZAJEL_OCR_PARALLEL إن شُورك الخادم مع غيره.
     */
    public static function parallel(): int
    {
        $configured = (int) config('zajel.ocr.parallel');
        $cores = $configured ?: Cache::rememberForever('zajel.cpu.cores', function () {
            try {
                return (int) trim(Process::timeout(5)->run(['nproc'])->output()) ?: 2;
            } catch (\Throwable) {
                return 2;
            }
        });

        return max(2, min(8, $cores));
    }
}

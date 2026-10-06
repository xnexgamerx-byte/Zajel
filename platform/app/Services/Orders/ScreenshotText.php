<?php

namespace App\Services\Orders;

use GdImage;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * نصّ لقطة شاشة محادثة (واتساب، ماسنجر) بقراءةٍ على الخادم نفسه (Tesseract): لا يخرج
 * منها شيء، ولا تُحفظ الصورة.
 *
 * تُقرأ سطراً سطراً لا صفحةً واحدة: قراءة الصفحة كاملةً كانت تُسقط أسطراً بعينها
 * («الاسم: علي حسين»)، والسطر وحده يُقرأ. فتُهيَّأ الصورة رماديّة، ويُقلب الوضع الداكن،
 * وتُجمع الصفوف التي فيها حبرٌ أسطراً تُقصّ على حبرها، وتُقرأ كلّها في عمليةٍ واحدة.
 * وما رجع منها فارغاً يُعاد بقراءة السطر الخام.
 *
 * والأرقام العربية الشرقية (٠-٩) لا يعرفها نموذج العربية المجاني فتُقرأ خطأً: يُقال ذلك
 * في الشاشة، والنصّ المنسوخ من الرسالة يُقرأ بها كاملةً.
 */
final class ScreenshotText
{
    /** أكبر صورة تُفتح: لقطة الهاتف نحو ٢٫٦ مليون نقطة، وصورة الكاميرا ١٢ مليوناً */
    public const MAX_PIXELS = 16_000_000;

    /** عرضٌ تُردّ إليه الصورة: أعرض منه يُصغَّر، وأضيق من نصفه يُكبَّر */
    private const WIDTH = 1200;

    /** أسطرٌ تُقرأ من الصورة الواحدة: لقطةٌ طويلة بثلاث شاشاتٍ أو نحوها، لا محادثةٌ كاملة */
    private const MAX_LINES = 120;

    public function available(): bool
    {
        return Cache::remember('zajel.ocr.available', now()->addHour(), function () {
            try {
                $result = Process::timeout(10)->run([config('zajel.ocr.binary'), '--list-langs']);
            } catch (\Throwable) {
                return false;
            }

            return $result->successful() && preg_match('/^ara$/m', $result->output().$result->errorOutput()) === 1;
        });
    }

    /** أسطر الصورة من أعلاها إلى أسفلها */
    public function read(string $path): string
    {
        $size = @getimagesize($path);

        if ($size === false) {
            throw new UnreadableImage('الملف ليس صورة.');
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new UnreadableImage('الصورة كبيرة جداً: أرسل لقطة الشاشة نفسها.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if (! $image instanceof GdImage) {
            throw new UnreadableImage('تعذّر فتح الصورة.');
        }

        $dir = storage_path('app/private/ocr/'.Str::random(16));
        File::ensureDirectoryExists($dir);

        try {
            return $this->lines($this->prepare($image), $dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** رماديّة بعرضٍ قريبٍ من لقطة الهاتف، والداكن مقلوب: حبرٌ داكن على فاتح */
    private function prepare(GdImage $image): GdImage
    {
        // صورةٌ بلوحة ألوان (PNG-8): imagecolorat يُرجع رقم اللون في اللوحة لا اللون نفسه
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $width = imagesx($image);

        if ($width > self::WIDTH * 1.4 || $width < self::WIDTH / 2) {
            $image = imagescale($image, self::WIDTH, -1, IMG_BICUBIC);
        }

        // والشفّاف على أبيض: لونه الخفيّ أسود غالباً فيُعدّ حبراً
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        $image = $flat;

        imagefilter($image, IMG_FILTER_GRAYSCALE);

        if ($this->brightness($image) < 110) {
            imagefilter($image, IMG_FILTER_NEGATE);
        }

        return $image;
    }

    private function brightness(GdImage $image): float
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $sum = $n = 0;

        for ($y = 0; $y < $h; $y += 9) {
            for ($x = 0; $x < $w; $x += 9) {
                $sum += imagecolorat($image, $x, $y) & 0xFF;
                $n++;
            }
        }

        return $n ? $sum / $n : 255;
    }

    /** عتبة الحبر من توزيع الإضاءة (Otsu): تصلح للّقطة وللصورة بإضاءةٍ غير مستوية */
    private function threshold(GdImage $image): int
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $histogram = array_fill(0, 256, 0);

        for ($y = 0; $y < $h; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                $histogram[imagecolorat($image, $x, $y) & 0xFF]++;
            }
        }

        $total = array_sum($histogram);
        $sum = 0;
        foreach ($histogram as $value => $count) {
            $sum += $value * $count;
        }

        [$best, $chosen, $weight, $below] = [0.0, 128, 0, 0];
        foreach ($histogram as $value => $count) {
            $weight += $count;
            if ($weight === 0 || $weight === $total) {
                continue;
            }
            $below += $value * $count;
            $meanBelow = $below / $weight;
            $meanAbove = ($sum - $below) / ($total - $weight);
            $between = $weight * ($total - $weight) * ($meanBelow - $meanAbove) ** 2;
            if ($between > $best) {
                [$best, $chosen] = [$between, $value];
            }
        }

        // حبر الكتابة أغمق من أيّ فقاعة: لا تنزل العتبة إلى ألوان الفقاعات الفاتحة
        return min(max($chosen, 90), 190);
    }

    private function lines(GdImage $image, string $dir): string
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $limit = $this->threshold($image);
        $ink = fn (int $x, int $y) => (imagecolorat($image, $x, $y) & 0xFF) < $limit;

        // عمودٌ حبرٌ من أعلى الصورة إلى أسفلها (إطار الهاتف، شريطٌ جانبيّ) ليس كتابة: لا يُعدّ،
        // وإلّا صارت الصفحة كلّها سطراً واحداً
        $columns = [];
        for ($x = 0; $x < $w; $x += 2) {
            [$dark, $seen] = [0, 0];
            for ($y = 0; $y < $h; $y += 4, $seen++) {
                $dark += (int) $ink($x, $y);
            }
            if ($dark < $seen * 0.8) {
                $columns[] = $x;
            }
        }

        // الصفوف التي فيها حبر، والفجوات الصغيرة (نقاط الحروف وهمزاتها) تُضمّ إلى سطرها
        $rows = array_fill(0, $h, 0);
        for ($y = 0; $y < $h; $y++) {
            foreach ($columns as $x) {
                if ($ink($x, $y)) {
                    $rows[$y]++;
                }
            }
        }

        $gapMax = max(4, intdiv($w, 135));
        $bands = [];
        $start = $end = null;
        $gap = 0;
        for ($y = 0; $y < $h; $y++) {
            if ($rows[$y] > 1) {
                $start ??= $y;
                $end = $y;
                $gap = 0;
            } elseif ($start !== null && ++$gap > $gapMax) {
                $bands[] = [$start, $end];
                $start = null;
            }
        }
        if ($start !== null) {
            $bands[] = [$start, $end];
        }
        $bands = array_slice($bands, 0, self::MAX_LINES * 2, true);

        // كل سطرٍ مقصوصٌ على حبره بهامشٍ أبيض؛ وشريطٌ ملوّنٌ بنصٍّ فاتح (رأس المحادثة) يُقلب
        $crops = [];
        foreach ($bands as $i => [$top, $bottom]) {
            $height = $bottom - $top + 1;
            if ($height < $w / 90 || $height > $w / 6) {
                continue;   // نقطةٌ شاردة، أو صورةٌ وملصق
            }

            [$left, $right, $dots] = [$w, 0, 0];
            for ($y = $top; $y <= $bottom; $y++) {
                foreach ($columns as $x) {
                    if ($ink($x, $y)) {
                        [$left, $right] = [min($left, $x), max($right, $x)];
                        $dots++;
                    }
                }
            }

            $width = $right - $left + 2;
            if ($width < 8) {
                continue;
            }

            $crop = imagecreatetruecolor($width + 32, $height + 32);
            imagefill($crop, 0, 0, imagecolorallocate($crop, 255, 255, 255));
            imagecopy($crop, $image, 16, 16, $left, $top, $width, $height);
            if ($dots * 2 / ($width * $height) > 0.55) {
                imagefilter($crop, IMG_FILTER_NEGATE);
            }

            imagepng($crop, $file = "{$dir}/{$i}.png");
            $crops[$i] = ['file' => $file, 'width' => $width];

            if (count($crops) === self::MAX_LINES) {
                break;
            }
        }

        // لا أسطر تُفصل (صورةٌ بإطارٍ داكن، أو صورة كاميرا): الصفحة كاملةً قراءةٌ واحدة
        if ($crops === []) {
            imagepng($image, $file = "{$dir}/page.png");

            return implode("\n", array_filter(array_map('trim', explode("\n", $this->ocr([$file], $dir, 4, raw: true)[0]))));
        }

        $texts = array_combine(array_keys($crops), $this->ocr(array_column($crops, 'file'), $dir, 7));

        // سطرٌ عريض رجع فارغاً: قراءته الخام تلتقطه
        $empty = array_filter($crops, fn (array $crop, int $i) => mb_strlen(preg_replace('/\s+/u', '', $texts[$i])) < 3
            && $crop['width'] > $w / 9, ARRAY_FILTER_USE_BOTH);
        if ($empty !== []) {
            foreach (array_combine(array_keys($empty), $this->ocr(array_column($empty, 'file'), $dir, 13)) as $i => $text) {
                $texts[$i] = $text;
            }
        }

        return implode("\n", array_filter($texts, fn (string $text) => $text !== ''));
    }

    /**
     * @param list<string> $files
     * @param bool $raw أسطر الصفحة كما هي، لا سطراً واحداً لكل صورة
     * @return list<string> نصّ كل صورة بترتيبها
     */
    private function ocr(array $files, string $dir, int $mode, bool $raw = false): array
    {
        File::put($list = "{$dir}/list-{$mode}.txt", implode("\n", $files)."\n");

        try {
            $result = Process::timeout(60)->run([config('zajel.ocr.binary'), $list, 'stdout', '-l', 'ara', '--psm', (string) $mode]);
        } catch (ProcessTimedOutException) {
            throw new UnreadableImage('طالت قراءة الصورة — أرسل لقطة شاشةٍ أقصر.');
        }

        if (! $result->successful()) {
            throw new UnreadableImage('تعذّرت قراءة الصورة.');
        }

        // صفحةٌ لكل صورة يفصلها \f، وعلامات الاتّجاه الخفيّة تُحذف
        $pages = explode("\f", rtrim($result->output(), "\f\n "));
        $pages = array_map(fn (string $page) => trim((string) preg_replace($raw ? '/[\x{200E}\x{200F}\x{202A}-\x{202E}]+/u'
            : '/[\x{200E}\x{200F}\x{202A}-\x{202E}\s]+/u', $raw ? '' : ' ', $page)), $pages);

        return array_pad(array_slice($pages, 0, count($files)), count($files), '');
    }
}

<?php

namespace App\Services\Orders;

use App\Support\Phone;
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
        $segments = $this->segments($image);

        // لا أسطر تُفصل (صورة كاميرا، أو صفحةٌ كلّها حبر): الصفحة كاملةً قراءةٌ واحدة
        if ($segments === []) {
            imagepng($image, $file = "{$dir}/page.png");

            return implode("\n", array_filter(array_map(fn (string $line) => $this->tidy($line),
                explode("\n", $this->ocr([$file], $dir, 4, raw: true)[0]))));
        }

        // كل مقطعٍ مقصوصٌ على حبره بهامشٍ أبيض؛ وشريطٌ ملوّنٌ بنصٍّ فاتح (رأس المحادثة) يُقلب
        foreach ($segments as $i => $segment) {
            $crop = imagecreatetruecolor($segment['w'] + 32, $segment['h'] + 32);
            imagefill($crop, 0, 0, imagecolorallocate($crop, 255, 255, 255));
            imagecopy($crop, $image, 16, 16, $segment['x'], $segment['y'], $segment['w'], $segment['h']);
            if ($segment['invert']) {
                imagefilter($crop, IMG_FILTER_NEGATE);
            }
            imagepng($crop, $segments[$i]['file'] = "{$dir}/{$i}.png");
        }

        // السطر الواحد يُقرأ سطراً، والمقطع العالي (اسمٌ وتحته سطر، كرأس المحادثة) كتلةً.
        // ونموذج الإنجليزية للأرقام: العربيّ يقرأ الرقم وحده في فقاعته حروفاً
        $read = function (string $lang) use ($segments, $dir): array {
            $texts = [];
            foreach ([7 => false, 6 => true] as $mode => $tall) {
                $group = array_filter($segments, fn (array $segment) => $segment['tall'] === $tall);
                if ($group !== []) {
                    $texts += array_combine(array_keys($group), $this->ocr(array_column($group, 'file'), $dir, $mode, $lang, raw: $tall));
                }
            }
            ksort($texts);

            return $texts;
        };
        $texts = $read('ara');
        $latin = $read('eng');

        // سطرٌ عريض رجع فارغاً: قراءته الخام تلتقطه
        $w = imagesx($image);
        $empty = array_filter($segments, fn (array $segment, int $i) => ! $segment['tall']
            && mb_strlen((string) preg_replace('/\s+/u', '', $texts[$i])) < 3 && $segment['w'] > $w / 9, ARRAY_FILTER_USE_BOTH);
        if ($empty !== []) {
            $texts = array_replace($texts, array_combine(array_keys($empty), $this->ocr(array_column($empty, 'file'), $dir, 13)));
        }

        $lines = [];
        foreach ($texts as $i => $text) {
            foreach (explode("\n", $this->withDigits($text, $latin[$i])) as $line) {
                if (($line = $this->tidy($line)) !== '') {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * مقاطع الكتابة من أعلى الصورة إلى أسفلها: صفوفٌ فيها حبرٌ تُجمع أشرطة، وكلّ شريطٍ يُفصل
     * عند الفراغ العريض — أزرار رأس المحادثة عن اسم الزبون، وأيقونات الردّ عن الفقاعة.
     *
     * @return array<int, array{x: int, y: int, w: int, h: int, tall: bool, invert: bool}>
     */
    private function segments(GdImage $image): array
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

        $segments = [];
        foreach (array_slice($bands, 0, self::MAX_LINES * 2) as [$top, $bottom]) {
            $height = $bottom - $top + 1;
            if ($height < $w / 90 || $height > $w / 6) {
                continue;   // نقطةٌ شاردة، أو صورةٌ وملصق
            }

            // حبر كل عمودٍ في الشريط، وما بين الكلمات أضيق من سطرٍ ونصف
            $inked = [];
            foreach ($columns as $x) {
                for ($y = $top; $y <= $bottom; $y++) {
                    if ($ink($x, $y)) {
                        $inked[] = $x;
                        break;
                    }
                }
            }
            $wide = max(24, (int) round($height * 1.5));
            $runs = [];
            foreach ($inked as $x) {
                if ($runs !== [] && $x - $runs[array_key_last($runs)][1] <= $wide) {
                    $runs[array_key_last($runs)][1] = $x;
                } else {
                    $runs[] = [$x, $x];
                }
            }

            foreach ($runs as [$left, $right]) {
                $width = $right - $left + 2;
                if ($width < 8) {
                    continue;
                }

                // المقطع مقصوصٌ على حبره من أعلى وأسفل أيضاً
                [$y0, $y1, $dots] = [$bottom, $top, 0];
                for ($y = $top; $y <= $bottom; $y++) {
                    for ($x = $left; $x <= $right; $x += 2) {
                        if ($ink($x, $y)) {
                            [$y0, $y1] = [min($y0, $y), max($y1, $y)];
                            $dots++;
                        }
                    }
                }
                if ($dots < 4) {
                    continue;
                }

                $segments[] = ['x' => $left, 'y' => $y0, 'w' => $width, 'h' => $y1 - $y0 + 1,
                    'invert' => $dots * 2 / ($width * ($y1 - $y0 + 1)) > 0.55];
            }

            if (count($segments) >= self::MAX_LINES) {
                break;
            }
        }

        // أعلى من سطرٍ ونصف من الأسطر المعتادة: أكثر من سطر، يُقرأ كتلة
        $heights = array_column($segments, 'h');
        sort($heights);
        $usual = $heights[intdiv(count($heights), 2)] ?? 0;

        return array_map(fn (array $segment) => $segment + ['tall' => count($segments) > 2 && $segment['h'] > $usual * 1.6],
            array_slice($segments, 0, self::MAX_LINES));
    }

    /**
     * الأرقام من قراءة الإنجليزية حيث العربية أخطأتها: سطرٌ بلا كلمةٍ عربية (رقم الهاتف وحده
     * في فقاعته) يُؤخذ منها كلّه، وفي غيره يُضاف رقم الهاتف إن لم تقرأه العربية.
     */
    private function withDigits(string $arabic, string $latin): string
    {
        $digits = fn (string $text) => (string) preg_replace('/\D/', '', $text);
        $runs = preg_match_all('/\+?\d[\d \-:.,]*\d/', $latin, $m) ? $m[0] : [];

        // رقمٌ بمسافاتٍ في سطرٍ عربيّ يُرسم معكوس الأجزاء («4567 123 0750»): يُقبل بالترتيب الذي هو هاتف
        $phone = function (string $run): ?string {
            $parts = preg_split('/[ \-]+/', $run) ?: [];
            foreach ([$parts, array_reverse($parts)] as $order) {
                if (Phone::normalise(implode('', $order)) !== null) {
                    return implode(' ', $order);
                }
            }

            return null;
        };

        if (! preg_match('/\p{Arabic}{2}/u', $arabic) && $runs !== []
            && strlen($digits($latin)) >= max(2, strlen($digits($arabic)))) {
            return implode(' ', array_map(fn (string $run) => $phone($run) ?? $run, $runs));
        }

        foreach ($runs as $run) {
            $found = $phone($run);
            if ($found !== null && ! str_contains($digits($arabic), $digits($found))) {
                $arabic .= ' '.$found;
            }
        }

        return $arabic;
    }

    /** بلا رموزٍ شاردة من الأيقونات والصور: ما لا حرف فيه ولا رقم، وحرفٌ برقمٍ ملتصقين («9ه)») */
    private function tidy(string $line): string
    {
        $tokens = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_map(fn (string $token) => trim($token, '()[]{}<>|*#"\'`~^_'), $tokens);
        $tokens = array_map(fn (string $token) => (string) preg_replace('/^[©®«»•·“”‘’]+|[©®«»•·“”‘’]+$/u', '', $token), $tokens);

        return implode(' ', array_filter($tokens, fn (string $token) => preg_match('/[\p{L}\d]/u', $token) === 1
            && preg_match('/^(?=\S*\d)(?=\S*\p{L})[\p{L}\d]{1,2}$/u', $token) === 0));
    }

    /**
     * @param list<string> $files
     * @param bool $raw أسطر الصفحة كما هي، لا سطراً واحداً لكل صورة
     * @return list<string> نصّ كل صورة بترتيبها
     */
    private function ocr(array $files, string $dir, int $mode, string $lang = 'ara', bool $raw = false): array
    {
        File::put($list = "{$dir}/list-{$lang}-{$mode}.txt", implode("\n", $files)."\n");

        try {
            $result = Process::timeout(60)->run([config('zajel.ocr.binary'), $list, 'stdout', '-l', $lang, '--psm', (string) $mode]);
        } catch (ProcessTimedOutException) {
            throw new UnreadableImage('طالت قراءة الصورة — أرسل لقطة شاشةٍ أقصر.');
        }

        if (! $result->successful()) {
            throw new UnreadableImage('تعذّرت قراءة الصورة.');
        }

        // صفحةٌ لكل صورة يفصلها \f، وعلامات الاتّجاه الخفيّة تُحذف
        $pages = explode("\f", rtrim($result->output(), "\f\n "));
        $pages = array_map(fn (string $page) => trim((string) preg_replace($raw ? ['/[\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '/\n\s*\n/u']
            : ['/[\x{200E}\x{200F}\x{202A}-\x{202E}\s]+/u'], $raw ? ['', "\n"] : [' '], $page)), $pages);

        return array_pad(array_slice($pages, 0, count($files)), count($files), '');
    }
}

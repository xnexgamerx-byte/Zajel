<?php

namespace App\Services\Orders;

use GdImage;

/**
 * الأرقام العربية (٠١٢٣٤٥٦٧٨٩) في لقطة الشاشة: نموذج العربية في Tesseract لا يعرفها، فتُقرأ هنا.
 *
 * كلّ رقمٍ منها قطعةٌ واحدة متّصلة لا تلتصق بجارها، فيُفصل كلّ رقمٍ وحده ويُقارن شكله المصغَّر
 * (شبكة ٨×١٢ مع نسبة عرضه إلى طوله) بأشكال الأرقام في خطوطٍ عربيةٍ شائعة (indic-digits.php).
 * والصفر نقطةٌ صغيرة في منتصف السطر: يُعرف بحجمه وموضعه لا بشكله.
 *
 * يُقبل الرقم حين تكون قطع السطر كلّها أرقاماً («٣٠٠٠٠٠» وحده في فقاعته)، أو أرقاماً متتاليةً
 * كثيرة بين كلمات (هاتفٌ في جملة). فحرفٌ منفصلٌ يشبه رقماً («ا» و«١»، «ه» و«٥») لا يُقرأ رقماً.
 */
final class IndicDigits
{
    public const COLS = 8;

    public const ROWS = 12;

    /** أبعد مسافةٍ يُقبل بها شكلٌ رقماً */
    private const MATCH = 6.5;

    /** @var list<array{0: string, 1: list<float>}>|null [الرقم، ملامحه] */
    private static ?array $templates = null;

    /**
     * الأرقام في سطرٍ مقصوص (كتابةٌ داكنة على فاتح).
     *
     * @param bool $priced في السطر كلمة سعرٍ («الف»، «بسعر»): يُقبل فيه عددٌ قصير («٣٠ الف»)
     * @return array{whole: bool, runs: list<array{left: int, right: int, digits: string}>}
     */
    public function read(GdImage $crop, bool $priced = false): array
    {
        [$ink, $w, $h] = $this->binary($crop);
        $parts = $this->components($ink, $w, $h);
        if ($parts === []) {
            return ['whole' => false, 'runs' => []];
        }

        // طول السطر: أطول القطع. وقطعٌ صغيرةٌ جداً (غبار) لا تُعدّ
        $tall = max(array_column($parts, 'h'));
        $parts = array_values(array_filter($parts, fn (array $part) => $part['h'] >= $tall * 0.12 || $part['w'] >= $tall * 0.12));
        usort($parts, fn (array $a, array $b) => $a['x'] <=> $b['x']);
        foreach ($parts as $i => $part) {
            $parts[$i]['digit'] = $this->classify($part, $tall);
        }

        // أرقامٌ متتالية بلا حرفٍ بينها، وفجوةٌ أوسع من نصف رقمٍ تفصل مجموعاتها («٠٧٧١ ٢٣٤»)
        $groups = [];
        $group = [];
        foreach ($parts as $part) {
            $joins = $group !== [] && $part['x'] - ($group[array_key_last($group)]['x'] + $group[array_key_last($group)]['w']) <= $tall * 1.2;
            if ($part['digit'] !== null && ($group === [] || $joins)) {
                $group[] = $part;
            } else {
                if ($group !== []) {
                    $groups[] = $group;
                }
                $group = $part['digit'] !== null ? [$part] : [];
            }
        }
        if ($group !== []) {
            $groups[] = $group;
        }

        $runs = [];
        $counted = 0;
        foreach ($groups as $group) {
            // الصفر نقطةٌ في وسط الأرقام لا فوقها ولا تحتها (نقاط الحروف)
            $tallOnes = array_filter($group, fn (array $part) => $part['digit'] !== '0');
            if ($tallOnes === []) {
                continue;
            }
            [$top, $bottom] = [min(array_column($tallOnes, 'y')), max(array_map(fn (array $part) => $part['y'] + $part['h'], $tallOnes))];
            $band = max(1, $bottom - $top);
            $group = array_values(array_filter($group, function (array $part) use ($top, $band) {
                $middle = ($part['y'] + $part['h'] / 2 - $top) / $band;

                return $part['digit'] !== '0' || ($middle >= 0.25 && $middle <= 0.9);
            }));

            // مسافةٌ بين مجموعتين أوسع كثيراً من المعتاد بين رقمين
            $gaps = [];
            foreach ($group as $i => $part) {
                $gaps[$i] = $i > 0 ? $part['x'] - ($group[$i - 1]['x'] + $group[$i - 1]['w']) : 0;
            }
            $usual = $gaps;
            array_shift($usual);
            sort($usual);
            $usual = $usual === [] ? 0 : $usual[intdiv(count($usual), 2)];
            $digits = '';
            foreach ($group as $i => $part) {
                if ($i > 0 && $gaps[$i] > max($tall * 0.35, $usual * 2.2)) {
                    $digits .= ' ';
                }
                $digits .= $part['digit'];
            }
            $counted += count($group);
            $runs[] = ['left' => $group[0]['x'], 'right' => $group[array_key_last($group)]['x'] + $group[array_key_last($group)]['w'],
                'digits' => $digits, 'count' => count($group), 'nonzero' => count($tallOnes)];
        }

        $whole = $runs !== [] && $counted === count($parts) && $counted >= 2;

        // عددٌ في جملة: أربعة أرقامٍ فأكثر (هاتف، «٢٥٠٠٠»)، أو رقمان بجانب كلمة السعر
        return [
            'whole' => $whole,
            'runs'  => array_values(array_map(fn (array $run) => ['left' => $run['left'], 'right' => $run['right'], 'digits' => $run['digits']],
                array_filter($runs, fn (array $run) => $whole
                    || ($run['nonzero'] >= 2 && $run['count'] >= 4) || ($priced && $run['count'] >= 2))))
        ];
    }

    /**
     * ملامح الشكل: نسبة الحبر في كلّ خليةٍ من شبكة ٨×١٢، ثم لوغاريتم عرضه على طوله.
     *
     * @param array<int, bool> $ink نقاط الحبر بمفتاح y*$stride+x
     * @return list<float>
     */
    public static function features(array $ink, int $stride, int $x0, int $y0, int $w, int $h): array
    {
        $cells = [];
        for ($row = 0; $row < self::ROWS; $row++) {
            [$top, $bottom] = [$y0 + intdiv($row * $h, self::ROWS), $y0 + max(intdiv(($row + 1) * $h, self::ROWS), intdiv($row * $h, self::ROWS) + 1)];
            for ($col = 0; $col < self::COLS; $col++) {
                [$left, $right] = [$x0 + intdiv($col * $w, self::COLS), $x0 + max(intdiv(($col + 1) * $w, self::COLS), intdiv($col * $w, self::COLS) + 1)];
                [$dark, $all] = [0, 0];
                for ($y = $top; $y < $bottom; $y++) {
                    for ($x = $left; $x < $right; $x++) {
                        $dark += isset($ink[$y * $stride + $x]) ? 1 : 0;
                        $all++;
                    }
                }
                $cells[] = $all ? $dark / $all : 0.0;
            }
        }
        $cells[] = log(max($w, 1) / max($h, 1));

        return $cells;
    }

    /** @param array{x: int, y: int, w: int, h: int, cy: float, features: list<float>} $part */
    private function classify(array $part, int $tall): ?string
    {
        // الصفر: نقطةٌ (معيّنٌ أو دائرةٌ صغيرة) أقصر من نصف السطر
        if ($part['h'] <= $tall * 0.5 && $part['w'] <= $tall * 0.6 && $part['w'] >= $part['h'] * 0.5 && $part['h'] >= $tall * 0.12) {
            return '0';
        }
        // الرقم أطول من نصف السطر وأضيق من طوله؛ والكلمة الموصولة أعرض
        if ($part['h'] < $tall * 0.6 || $part['w'] > $part['h'] * 1.15) {
            return null;
        }

        // الواحد خطٌّ قائمٌ مصمت
        if ($part['w'] <= $part['h'] * 0.45 && array_sum(array_slice($part['features'], 0, self::COLS * self::ROWS)) / (self::COLS * self::ROWS) >= 0.45) {
            return '1';
        }

        $templates = self::$templates ??= array_map(fn (array $t) => [$t[0], [...array_map(fn (string $c) => (int) $c / 9, str_split($t[1])), (float) $t[2]]],
            require __DIR__.'/indic-digits.php');
        [$best, $digit] = [INF, null];
        foreach ($templates as [$candidate, $features]) {
            $distance = 4 * ($features[self::COLS * self::ROWS] - $part['features'][self::COLS * self::ROWS]) ** 2;
            foreach ($features as $i => $value) {
                if ($i < self::COLS * self::ROWS && ($distance += ($value - $part['features'][$i]) ** 2) >= $best) {
                    continue 2;
                }
            }
            if ($distance < $best) {
                [$best, $digit] = [$distance, $candidate];
            }
        }

        return $best <= self::MATCH ? $digit : null;
    }

    /** @return array{0: array<int, true>, 1: int, 2: int} نقاط الحبر بعتبة Otsu للمقطع */
    private function binary(GdImage $crop): array
    {
        [$w, $h] = [imagesx($crop), imagesy($crop)];
        $values = [];
        $histogram = array_fill(0, 256, 0);
        // العتبة من داخل المقطع وحده: هامشه الأبيض حول فقاعةٍ رمادية يُفسد قسمتها
        $margin = min(16, intdiv($w, 4), intdiv($h, 4));
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $values[$y * $w + $x] = $value = imagecolorat($crop, $x, $y) & 0xFF;
                if ($x >= $margin && $x < $w - $margin && $y >= $margin && $y < $h - $margin) {
                    $histogram[$value]++;
                }
            }
        }

        $total = array_sum($histogram);
        $sum = 0;
        foreach ($histogram as $value => $count) {
            $sum += $value * $count;
        }
        [$best, $limit, $weight, $below] = [0.0, 128, 0, 0];
        foreach ($histogram as $value => $count) {
            $weight += $count;
            if ($weight === 0 || $weight === $total) {
                continue;
            }
            $below += $value * $count;
            $between = $weight * ($total - $weight) * ($below / $weight - ($sum - $below) / ($total - $weight)) ** 2;
            if ($between > $best) {
                [$best, $limit] = [$between, $value];
            }
        }

        $ink = [];
        foreach ($values as $i => $value) {
            if ($value <= $limit) {
                $ink[$i] = true;
            }
        }

        return [$ink, $w, $h];
    }

    /**
     * القطع المتّصلة (ثمانية جيران) بحدودها وملامحها.
     *
     * @param array<int, true> $ink
     * @return list<array{x: int, y: int, w: int, h: int, features: list<float>}>
     */
    private function components(array $ink, int $w, int $h): array
    {
        $seen = [];
        $parts = [];
        foreach ($ink as $start => $_) {
            if (isset($seen[$start])) {
                continue;
            }
            $stack = [$start];
            $seen[$start] = true;
            [$x0, $y0, $x1, $y1, $count] = [$w, $h, 0, 0, 0];
            while ($stack !== []) {
                $i = array_pop($stack);
                [$x, $y] = [$i % $w, intdiv($i, $w)];
                [$x0, $y0, $x1, $y1] = [min($x0, $x), min($y0, $y), max($x1, $x), max($y1, $y)];
                $count++;
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        [$nx, $ny] = [$x + $dx, $y + $dy];
                        $j = $ny * $w + $nx;
                        if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h && isset($ink[$j]) && ! isset($seen[$j])) {
                            $seen[$j] = true;
                            $stack[] = $j;
                        }
                    }
                }
            }
            if ($count < 4) {
                continue;
            }
            $parts[] = ['x' => $x0, 'y' => $y0, 'w' => $x1 - $x0 + 1, 'h' => $y1 - $y0 + 1,
                'features' => self::features($ink, $w, $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1)];
        }

        return $parts;
    }
}

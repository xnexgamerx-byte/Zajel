<?php

namespace App\Support;

use App\Models\Company;

/**
 * مظهر نظام الشركة: لون التمييز الذي تقوم عليه كل شاشة — الأزرار والروابط والقائمة
 * الحالية والتوهّج أعلى الصفحة (docs/plan/35). يختاره صاحب المنصّة لكل شركة.
 *
 * الشاشات كلّها تكتب درجات primary (bg-primary-600، text-primary-700…)، ودرجاتها
 * متغيّرات على :root (palette.js)؛ فالمظهر يبدّل المتغيّرات وحدها، ولا تتغيّر شاشة.
 *
 * وكل لوحةٍ بإضاءةٍ تطابق المرجانيّ درجةً درجة (بالسطوع الذي يقيس به WCAG): فتباين
 * النصّ الأبيض على زرٍّ ٦٠٠، والرابط ٧٠٠ على الأبيض، كما في المرجانيّ (٤٫٦ و٥٫٩).
 * و«من لون الشعار» تُشتقّ بالطريقة نفسها من لون الشركة، فلا يأتي زرٌّ لا يُقرأ.
 */
final class Theme
{
    public const DEFAULT = 'wahaj';

    /** يُشتقّ من لون شعار الشركة (primary_color) */
    public const FROM_LOGO = 'logo';

    /** بترتيب العرض في لوحة المنصّة */
    public const NAMES = [
        'wahaj'  => 'مرجانيّ «وهج»',
        'ruby'   => 'عنّابيّ',
        'amber'  => 'كهرمانيّ',
        'green'  => 'أخضر',
        'teal'   => 'فيروزيّ',
        'blue'   => 'أزرق',
        'indigo' => 'نيليّ',
        'violet' => 'بنفسجيّ',
        'slate'  => 'رماديّ',
        'logo'   => 'من لون الشعار',
    ];

    public const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    private const PRESETS = [
        'wahaj' => [
            50 => 'oklch(0.975 0.014 24)', 100 => 'oklch(0.945 0.032 24)', 200 => 'oklch(0.895 0.066 24)', 300 => 'oklch(0.820 0.115 24)', 400 => 'oklch(0.735 0.165 25)', 500 => 'oklch(0.665 0.198 26)', 600 => 'oklch(0.585 0.205 27)', 700 => 'oklch(0.525 0.185 27)', 800 => 'oklch(0.455 0.155 27)', 900 => 'oklch(0.395 0.125 27)', 950 => 'oklch(0.265 0.085 27)',
        ],
        'ruby' => [
            50 => 'oklch(0.974 0.013 8)', 100 => 'oklch(0.943 0.029 8)', 200 => 'oklch(0.889 0.058 8)', 300 => 'oklch(0.813 0.106 8)', 400 => 'oklch(0.735 0.164 8)', 500 => 'oklch(0.667 0.198 8)', 600 => 'oklch(0.587 0.205 8)', 700 => 'oklch(0.527 0.185 8)', 800 => 'oklch(0.456 0.155 8)', 900 => 'oklch(0.396 0.125 8)', 950 => 'oklch(0.266 0.085 8)',
        ],
        'amber' => [
            50 => 'oklch(0.973 0.011 62)', 100 => 'oklch(0.941 0.026 62)', 200 => 'oklch(0.885 0.053 62)', 300 => 'oklch(0.805 0.092 62)', 400 => 'oklch(0.723 0.132 62)', 500 => 'oklch(0.651 0.145 62)', 600 => 'oklch(0.569 0.127 62)', 700 => 'oklch(0.511 0.114 62)', 800 => 'oklch(0.443 0.099 62)', 900 => 'oklch(0.386 0.086 62)', 950 => 'oklch(0.259 0.058 62)',
        ],
        'green' => [
            50 => 'oklch(0.971 0.011 150)', 100 => 'oklch(0.936 0.026 150)', 200 => 'oklch(0.876 0.053 150)', 300 => 'oklch(0.789 0.092 150)', 400 => 'oklch(0.700 0.132 150)', 500 => 'oklch(0.624 0.158 150)', 600 => 'oklch(0.545 0.146 150)', 700 => 'oklch(0.489 0.131 150)', 800 => 'oklch(0.424 0.113 150)', 900 => 'oklch(0.369 0.099 150)', 950 => 'oklch(0.248 0.067 150)',
        ],
        'teal' => [
            50 => 'oklch(0.971 0.010 190)', 100 => 'oklch(0.937 0.022 190)', 200 => 'oklch(0.876 0.046 190)', 300 => 'oklch(0.790 0.081 190)', 400 => 'oklch(0.700 0.115 190)', 500 => 'oklch(0.629 0.105 190)', 600 => 'oklch(0.549 0.092 190)', 700 => 'oklch(0.493 0.083 190)', 800 => 'oklch(0.428 0.072 190)', 900 => 'oklch(0.373 0.063 190)', 950 => 'oklch(0.250 0.042 190)',
        ],
        'blue' => [
            50 => 'oklch(0.972 0.013 256)', 100 => 'oklch(0.939 0.029 256)', 200 => 'oklch(0.881 0.057 256)', 300 => 'oklch(0.799 0.100 256)', 400 => 'oklch(0.715 0.146 256)', 500 => 'oklch(0.645 0.187 256)', 600 => 'oklch(0.565 0.187 256)', 700 => 'oklch(0.507 0.168 256)', 800 => 'oklch(0.440 0.146 256)', 900 => 'oklch(0.383 0.125 256)', 950 => 'oklch(0.257 0.085 256)',
        ],
        'indigo' => [
            50 => 'oklch(0.973 0.012 277)', 100 => 'oklch(0.940 0.028 277)', 200 => 'oklch(0.884 0.055 277)', 300 => 'oklch(0.804 0.097 277)', 400 => 'oklch(0.722 0.141 277)', 500 => 'oklch(0.653 0.181 277)', 600 => 'oklch(0.576 0.205 277)', 700 => 'oklch(0.517 0.185 277)', 800 => 'oklch(0.448 0.155 277)', 900 => 'oklch(0.389 0.125 277)', 950 => 'oklch(0.261 0.085 277)',
        ],
        'violet' => [
            50 => 'oklch(0.974 0.014 303)', 100 => 'oklch(0.942 0.032 303)', 200 => 'oklch(0.888 0.065 303)', 300 => 'oklch(0.810 0.113 303)', 400 => 'oklch(0.731 0.165 303)', 500 => 'oklch(0.663 0.198 303)', 600 => 'oklch(0.584 0.205 303)', 700 => 'oklch(0.524 0.185 303)', 800 => 'oklch(0.454 0.155 303)', 900 => 'oklch(0.394 0.125 303)', 950 => 'oklch(0.264 0.085 303)',
        ],
        'slate' => [
            50 => 'oklch(0.973 0.002 255)', 100 => 'oklch(0.939 0.005 255)', 200 => 'oklch(0.882 0.011 255)', 300 => 'oklch(0.799 0.018 255)', 400 => 'oklch(0.714 0.026 255)', 500 => 'oklch(0.641 0.032 255)', 600 => 'oklch(0.560 0.033 255)', 700 => 'oklch(0.502 0.030 255)', 800 => 'oklch(0.436 0.025 255)', 900 => 'oklch(0.380 0.020 255)', 950 => 'oklch(0.255 0.014 255)',
        ],
    ];

    /** سطوع درجات المرجانيّ (WCAG): عليه تُبنى كل لوحة */
    private const LUMINANCE = [
        50 => 0.92005, 100 => 0.82874, 200 => 0.68637, 300 => 0.51087, 400 => 0.36413, 500 => 0.26395,
        600 => 0.17599, 700 => 0.1271, 800 => 0.08314, 900 => 0.05494, 950 => 0.01656,
    ];

    /** تشبّع درجات المرجانيّ: لوحة الشعار بنسبة تشبّع لونه إلى تشبّع المرجانيّ ٦٠٠ */
    private const CHROMA = [
        50 => 0.014, 100 => 0.032, 200 => 0.066, 300 => 0.115, 400 => 0.165, 500 => 0.198,
        600 => 0.205, 700 => 0.185, 800 => 0.155, 900 => 0.125, 950 => 0.085,
    ];

    /** @var array<string, array<int, string>> لوحات الشعارات المشتقّة في هذه العملية */
    private static array $derived = [];

    /** @param array<int, string> $shades */
    private function __construct(public readonly string $key, public readonly array $shades) {}

    public static function for(Company $company): self
    {
        return self::make((string) $company->setting('theme', self::DEFAULT), $company->primary_color);
    }

    /** المظهر بمفتاحه، ومفتاحٌ مجهول (أو شعارٌ بلا لون) مرجانيّ «وهج» */
    public static function make(string $key, ?string $logo = null): self
    {
        if ($key === self::FROM_LOGO && is_string($logo) && preg_match('/^#[0-9A-Fa-f]{6}$/', $logo)) {
            return new self($key, self::$derived[strtoupper($logo)] ??= self::derive($logo));
        }

        return isset(self::PRESETS[$key]) ? new self($key, self::PRESETS[$key]) : new self(self::DEFAULT, self::PRESETS[self::DEFAULT]);
    }

    public function name(): string
    {
        return self::NAMES[$this->key];
    }

    public function isDefault(): bool
    {
        return $this->key === self::DEFAULT;
    }

    /** متغيّرات :root التي تبدّل درجات primary، أو لا شيء للمرجانيّ (هو في app.css) */
    public function css(): string
    {
        if ($this->isDefault()) {
            return '';
        }

        $vars = '';
        foreach ($this->shades as $step => $value) {
            $vars .= "--color-primary-{$step}: {$value}; ";
        }

        return $vars;
    }

    /** تباين WCAG بين درجتين من اللوحة، والثانية null للأبيض */
    public function contrast(int $step, ?int $on = null): float
    {
        $of = function (?int $step): float {
            if ($step === null) {
                return 1.0;
            }
            sscanf($this->shades[$step], 'oklch(%f %f %f)', $l, $c, $hue);

            return self::luminance($l, $c, $hue);
        };
        [$a, $b] = [$of($step), $of($on)];

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * لوحةٌ من لون: لونه (hue) ونسبة تشبّعه، وإضاءةٌ تطابق سطوع المرجانيّ درجةً درجة —
     * فلونٌ فاتح جداً للشعار (أصفر) يصير زرّه ذهبياً غامقاً يُقرأ عليه الأبيض.
     *
     * @return array<int, string>
     */
    public static function derive(string $hex): array
    {
        [, $chroma, $hue] = self::toOklch($hex);

        // الرماديّ بلا لون: تشبّعٌ قليل يبقيه رمادياً، والصارخ لا يتجاوز المرجانيّ كثيراً
        $ratio = min(1.1, max(0.12, $chroma / self::CHROMA[600]));

        $shades = [];
        foreach (self::STEPS as $step) {
            [$low, $high] = [0.0, 1.0];
            for ($i = 0; $i < 32; $i++) {
                $mid = ($low + $high) / 2;
                $c = min(self::CHROMA[$step] * $ratio, self::maxChroma($mid, $hue) * 0.97);
                self::luminance($mid, $c, $hue) < self::LUMINANCE[$step] ? $low = $mid : $high = $mid;
            }
            $l = ($low + $high) / 2;
            $c = min(self::CHROMA[$step] * $ratio, self::maxChroma($l, $hue) * 0.97);
            $shades[$step] = sprintf('oklch(%.3f %.3f %.1f)', $l, $c, $hue);
        }

        return $shades;
    }

    /** @return array{0: float, 1: float, 2: float} [الإضاءة، التشبّع، اللون بالدرجات] */
    private static function toOklch(string $hex): array
    {
        $linear = array_map(function (string $pair) {
            $v = hexdec($pair) / 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));
        [$r, $g, $b] = $linear;

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $hue = rad2deg(atan2($bb, $a));

        return [$lightness, sqrt($a * $a + $bb * $bb), $hue < 0 ? $hue + 360 : $hue];
    }

    /** @return array{0: float, 1: float, 2: float} RGB خطّيّ، قد يخرج عن ٠–١ خارج نطاق الشاشة */
    private static function linear(float $l, float $c, float $hue): array
    {
        [$a, $b] = [$c * cos(deg2rad($hue)), $c * sin(deg2rad($hue))];
        $l_ = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m_ = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s_ = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_,
            -1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_,
            -0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.7076147010 * $s_,
        ];
    }

    /** أعلى تشبّعٍ تعرضه الشاشة بهذه الإضاءة وهذا اللون */
    private static function maxChroma(float $l, float $hue): float
    {
        [$low, $high] = [0.0, 0.4];
        for ($i = 0; $i < 24; $i++) {
            $mid = ($low + $high) / 2;
            $inside = true;
            foreach (self::linear($l, $mid, $hue) as $v) {
                $inside = $inside && $v >= -0.0001 && $v <= 1.0001;
            }
            $inside ? $low = $mid : $high = $mid;
        }

        return $low;
    }

    /** سطوع WCAG */
    private static function luminance(float $l, float $c, float $hue): float
    {
        [$r, $g, $b] = array_map(fn (float $v) => min(1.0, max(0.0, $v)), self::linear($l, $c, $hue));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}

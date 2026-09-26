/**
 * لوحة «وهج»: المرجانيّ للفعل والعنصر الحاليّ، ورماديٌّ دافئ للنصّ والمحايدات.
 *
 * ألوان الحالة — الأخضر والأحمر والجمليّ والأزرق التقنيّ — من لوحة «نظام تصميم
 * الإمارات» (@aegov/design-system — TDRA، رخصة MIT) كما هي: معناها مألوف،
 * ويُقرأ من الحزمة نفسها لا منسوخاً. والمرجانيّ والرماديّ يحلّان محلّ ذهبيّها
 * وأسودها المزرقّ بالأسماء نفسها (primary وaeblack)، فتتبع كل شاشةٍ كتبت
 * bg-primary-600 أو text-aeblack-800 بلا تعديل.
 *
 * كل درجة تصير متغيّراً على :root (--color-primary-600…) وأداةً في Tailwind.
 * نأخذ الألوان وحدها: قاعدة الحزمة كُتبت لمواقع المحتوى الحكومية.
 */
import plugin from 'tailwindcss/plugin';
import aegov from '@aegov/design-system/src/theme/colors.js';

/*
| المرجانيّ: ٥٠٠ لون الصورة المرجعية للمساحات الكبيرة، و٦٠٠ للأزرار (نصٌّ أبيض
| عليه بتباين ٤٫٦ — فوق حدّ AA)، و٧٠٠ للروابط والنصّ الملوّن على الأبيض.
*/
const coral = {
    50:  'oklch(0.975 0.014 24)',
    100: 'oklch(0.945 0.032 24)',
    200: 'oklch(0.895 0.066 24)',
    300: 'oklch(0.820 0.115 24)',
    400: 'oklch(0.735 0.165 25)',
    500: 'oklch(0.665 0.198 26)',
    600: 'oklch(0.585 0.205 27)',
    700: 'oklch(0.525 0.185 27)',
    800: 'oklch(0.455 0.155 27)',
    900: 'oklch(0.395 0.125 27)',
    950: 'oklch(0.265 0.085 27)',
};

/* رماديٌّ دافئ يجاور المرجانيّ؛ ٥٠٠ أخفّ نصٍّ يُقرأ على خلفية الصفحة (٤٫٨) */
const warmGray = {
    50:  'oklch(0.978 0.002 85)',
    100: 'oklch(0.940 0.003 85)',
    200: 'oklch(0.885 0.004 85)',
    300: 'oklch(0.790 0.005 85)',
    400: 'oklch(0.640 0.006 85)',
    500: 'oklch(0.520 0.006 85)',
    600: 'oklch(0.450 0.006 85)',
    700: 'oklch(0.380 0.005 85)',
    800: 'oklch(0.285 0.004 85)',
    900: 'oklch(0.220 0.003 85)',
    950: 'oklch(0.160 0.002 85)',
};

const palette = { ...aegov, primary: coral, aeblack: warmGray };

const variables = {};
const colors = {};

for (const [name, shades] of Object.entries(palette)) {
    colors[name] = {};

    for (const [shade, value] of Object.entries(shades)) {
        const key = shade === 'DEFAULT' ? `--color-${name}` : `--color-${name}-${shade}`;

        variables[key] = value;
        colors[name][shade] = `var(${key})`;
    }
}

export default plugin(({ addBase }) => addBase({ ':root': variables }), {
    theme: { extend: { colors } },
});

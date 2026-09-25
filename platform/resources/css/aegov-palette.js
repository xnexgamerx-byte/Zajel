/**
 * لوحة ألوان «نظام تصميم الإمارات» (@aegov/design-system — TDRA، رخصة MIT).
 *
 * تُقرأ من الحزمة نفسها لا منسوخةً، فتتحدّث بتحديثها: كل درجة تصير متغيّراً
 * على :root (--color-primary-600، --color-aeblack-800…) وأداةً في Tailwind
 * (bg-primary-600، text-aeblack-800…).
 *
 * نأخذ الألوان وحدها. مُلحَق الحزمة يضيف معها قاعدةً كُتبت لمواقع المحتوى
 * الحكومية — عناوين بحجم ٦٢ بكسل، وهامش ٣٢ تحت كل فقرة، وخطّ تحت كل رابط —
 * تقلب أكثر من مئة شاشة بيانات لو دخلت كما هي. المكوّنات مكتوبة على
 * مواصفاته في app.css.
 */
import plugin from 'tailwindcss/plugin';
import palette from '@aegov/design-system/src/theme/colors.js';

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

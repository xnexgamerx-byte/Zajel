/**
 * الأرقام كما تُقرأ: المبالغ تُكتب بفاصلٍ كل ثلاث خانات (60 000)، والهاتف ١١ رقماً.
 *
 * «60000» و«600000» يُخلَط بينهما بنظرة؛ «60 000» و«600 000» لا. فكل حقلٍ رقميّ
 * صحيح (input[type=number] بلا كسور، أو data-money) يصير نصّاً يُجمَّع وهو يُكتب،
 * والمؤشّر يبقى بعد الرقم الذي كُتب. والأرقام العربية (٠-٩) تصير إنجليزية في الحال.
 * وعند الإرسال يعود الرقم خانةً خانة بلا فواصل — والخادم يقبل الفاصل أيضاً
 * (NormaliseDigits) إن أُرسل نموذجٌ بلا هذا.
 *
 * والهاتف: أرقامٌ فقط، ١١ خانةً لا أكثر، وتنبيهٌ بالعربية إن نقص أو لم يبدأ بـ 07.
 */

const ARABIC = '٠١٢٣٤٥٦٧٨٩';
const PERSIAN = '۰۱۲۳۴۵۶۷۸۹';

/** الأرقام العربية والفارسية إلى إنجليزية. */
export const latinDigits = (text) => String(text ?? '')
    .replace(/[٠-٩]/g, (d) => String(ARABIC.indexOf(d)))
    .replace(/[۰-۹]/g, (d) => String(PERSIAN.indexOf(d)));

/** قيمة حقلٍ رقميّ كما تُحسب: «60 000» أو «٦٠٬٠٠٠» هي 60000. */
export const numberValue = (input) => {
    const raw = latinDigits(input?.value ?? '').trim();
    const negative = raw.startsWith('-');
    const digits = raw.replace(/\D/g, '');

    return digits === '' ? 0 : (negative ? -1 : 1) * Number(digits);
};

const group = (digits) => digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

/** يُعيد تجميع الحقل ويُبقي المؤشّر بعد الخانة نفسها. */
function regroup(input) {
    const value = latinDigits(input.value);
    const signed = input.dataset.signed !== undefined;
    const negative = signed && value.trim().startsWith('-');
    const caret = input.selectionStart ?? value.length;
    const digitsBefore = value.slice(0, caret).replace(/\D/g, '').length;

    const digits = value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const formatted = (negative ? '-' : '') + group(digits);

    if (formatted === input.value) return;

    input.value = formatted;

    if (document.activeElement !== input) return;

    let pos = negative ? 1 : 0;
    for (let seen = 0; pos < formatted.length && seen < digitsBefore; pos++) {
        if (/\d/.test(formatted[pos])) seen++;
    }
    input.setSelectionRange(pos, pos);
}

/** حقلٌ رقميّ صحيح: يُجمَّع. والكسريّ (نسبةٌ مئوية) يبقى كما هو. */
function isGroupable(input) {
    if (input.dataset.plain !== undefined || input.dataset.grouped !== undefined) return false;
    if (input.dataset.money !== undefined) return true;
    if (input.type !== 'number') return false;

    const step = input.getAttribute('step');
    return !step || /^\d+$/.test(step);
}

function enhanceNumber(input) {
    input.dataset.grouped = '';

    if (input.type === 'number') {
        // الحدّ الأدنى السالب يعني أن الحقل يقبل الإشارة (تسوية جردٍ بالنقص)
        if (Number(input.getAttribute('min')) < 0) input.dataset.signed = '';
        input.type = 'text';
    }

    input.inputMode = 'numeric';
    input.autocomplete = 'off';
    input.dir = 'ltr';

    regroup(input);
    input.addEventListener('input', () => regroup(input));
}

const PHONE_MESSAGE = 'رقم الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً';

function checkPhone(input) {
    const value = input.value;
    const ok = value === '' ? !input.required : /^07\d{9}$/.test(value);

    input.setCustomValidity(ok ? '' : (value === '' ? 'اكتب رقم الهاتف' : PHONE_MESSAGE));

    return ok;
}

function enhancePhone(input) {
    input.dataset.phoneInput = '';
    input.inputMode = 'numeric';
    input.maxLength = 11;
    input.dir = 'ltr';
    input.autocomplete = input.autocomplete || 'off';
    if (!input.placeholder) input.placeholder = '07xxxxxxxxx';

    // في جدول الإدخال السريع لا مكان لسطرٍ تحت الخانة: تنبيه المتصفّح وحده
    const hint = document.createElement('p');
    hint.className = 'field-error hidden';
    hint.textContent = PHONE_MESSAGE;
    if (!input.closest('table, [data-quick-form]')) input.insertAdjacentElement('afterend', hint);

    const clean = () => {
        // ‎+964 أو 00964 ملصوقاً: يُرَدّ إلى 07…
        let digits = latinDigits(input.value).replace(/\D/g, '');
        if (digits.startsWith('00964')) digits = digits.slice(2);
        if (digits.startsWith('964')) digits = '0' + digits.slice(3);
        if (digits.length === 10 && digits.startsWith('7')) digits = '0' + digits;
        digits = digits.slice(0, 11);

        if (digits !== input.value) input.value = digits;
    };

    const show = () => hint.classList.toggle('hidden', input.value === '' || checkPhone(input));

    input.addEventListener('input', () => {
        clean();
        checkPhone(input);
        // الخطأ يظهر حين يكتمل ما كُتب أو يُترك الحقل، لا مع أوّل رقم
        if (input.value.length >= 11) show();
        else hint.classList.add('hidden');
    });
    input.addEventListener('blur', show);
    input.addEventListener('invalid', show);

    clean();
    checkPhone(input);
}

/**
 * حقول الهاتف في النماذج: الهاتف وواتساب الدعم وهاتف الشكاوى — كلّها 07 وتسعة أرقام.
 * وما ليس رقماً كاملاً (آخر أربعة أرقام في صفحة التتبّع) يحمل data-plain.
 */
const isPhone = (input) => input.matches(
    'input[type="tel"], input[name*="phone"], input[name*="whatsapp"], input[name*="complaints"]',
) && input.dataset.phoneInput === undefined && input.dataset.plain === undefined;

export function initNumberInputs(root = document) {
    for (const input of root.querySelectorAll('input')) {
        if (isPhone(input)) {
            enhancePhone(input);
        } else if (isGroupable(input)) {
            enhanceNumber(input);
        }
    }
}

// الإرسال: الأرقام خانةً خانة، قبل أيّ معالجٍ آخر للنموذج
document.addEventListener('submit', (event) => {
    for (const input of event.target.querySelectorAll('input[data-grouped]')) {
        const value = latinDigits(input.value);
        const negative = input.dataset.signed !== undefined && value.trim().startsWith('-');
        const digits = value.replace(/\D/g, '');
        input.value = digits === '' ? '' : (negative ? '-' : '') + digits;
    }
}, true);

// إرسالٌ أوقفه معالجٌ آخر (تأكيدٌ أُلغي): تعود الفواصل
document.addEventListener('submit', (event) => {
    if (!event.defaultPrevented) return;
    for (const input of event.target.querySelectorAll('input[data-grouped]')) regroup(input);
});

// العودة بزرّ الرجوع تُعيد الصفحة من ذاكرة المتصفّح بأرقامٍ مُرسَلة بلا فواصل
window.addEventListener('pageshow', () => {
    for (const input of document.querySelectorAll('input[data-grouped]')) regroup(input);
});

/**
 * سلوكٌ صغير يعلنه HTML بسماتٍ لا بمقابض onclick مضمَّنة.
 *
 * سياسة المحتوى تمنع كل سكربتٍ مضمَّن في الصفحة (لا 'unsafe-inline' في
 * SecurityHeaders): سكربتٌ يُحقَن في اسم تاجرٍ أو ملاحظة شحنةٍ لا يعمل ولو
 * أفلت يوماً من التهريب. فما كان مقبضاً في الصفحة صار سمةً هنا — وكلٌّ
 * مفوَّضٌ من document، يعمل لما يُضاف إلى الصفحة لاحقاً كما لما رُسم معها.
 *
 * تحمّله app.js، وتحمّله وحدها صفحاتُ الطباعة والأخطاء.
 */

import { shipments } from './arabic';
import { numberValue } from './number-inputs';

/** «متأكّد؟» قبل فعلٍ لا يُتراجَع عنه: data-confirm على الزرّ أو الرابط أو النموذج */
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('button[data-confirm], a[data-confirm], input[type="submit"][data-confirm]');

    if (trigger && !window.confirm(trigger.dataset.confirm)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    // اطبع · رجوع
    if (target.closest('[data-print]')) window.print();
    if (target.closest('[data-history-back]')) history.back();

    // نافذةٌ تُفتح وتُغلق باسمها: data-dialog-open="id" / data-dialog-close="id"
    const opener = target.closest('[data-dialog-open]');
    if (opener) document.getElementById(opener.dataset.dialogOpen)?.showModal();

    const closer = target.closest('[data-dialog-close]');
    if (closer) document.getElementById(closer.dataset.dialogClose)?.close();
});

document.addEventListener('change', (event) => {
    const field = event.target;
    if (!(field instanceof HTMLElement)) return;

    // اختيارٌ يُرسل نموذجه في الحال (المحافظة في «أجور المناطق»، الاتجاه في الأرشيف)
    if (field.hasAttribute('data-submit-on-change') && field.form) {
        field.form.requestSubmit();
    }

    // «الكل» في جدول: data-check-all-in ما يحيط به، وdata-check-all-of ما يُحدَّد فيه
    if (field.dataset.checkAllIn) {
        const scope = field.closest(field.dataset.checkAllIn);
        const selector = field.dataset.checkAllOf || 'tbody input[type="checkbox"]';
        for (const box of scope?.querySelectorAll(selector) ?? []) box.checked = field.checked;
    }

    // اسم الملف المختار مكان «اختر ملفاً»
    if (field instanceof HTMLInputElement && field.type === 'file' && field.dataset.fileLabel !== undefined) {
        const name = field.closest('label')?.querySelector('[data-file-name]');
        if (name) name.textContent = field.files[0]?.name ?? field.dataset.fileLabel;
    }

    // حقلٌ يظهر بقيمة اختيارٍ في نموذجه: data-show-when="accept" data-show-target="[data-agreed]"
    if (field.dataset.showWhen !== undefined) {
        const target = (field.closest('form') ?? document).querySelector(field.dataset.showTarget);
        if (target) target.hidden = field.value !== field.dataset.showWhen;
    }

    // وحقلٌ يلزم بها: data-require-when="forfeit" data-require-target="#deposit-reason"
    if (field.dataset.requireWhen !== undefined) {
        const target = document.querySelector(field.dataset.requireTarget);
        if (target) target.required = field.value === field.dataset.requireWhen;
    }
});

/**
 * حقلٌ يرفضه المتصفّح داخل «تفاصيل» مطويّة يفتحها قبل أن يُطلب تصحيحه: الحقل
 * المخفيّ لا يُركَّز عليه، فيُمنع الإرسال بلا رسالةٍ تُرى.
 */
document.addEventListener('invalid', (event) => {
    const field = event.target;
    if (field instanceof Element) field.closest('details:not([open])')?.setAttribute('open', '');
}, true);

/** لونٌ يُعاين قبل الحفظ: data-css-var="--company" */
document.addEventListener('input', (event) => {
    const field = event.target;

    if (field instanceof HTMLInputElement && field.dataset.cssVar) {
        document.documentElement.style.setProperty(field.dataset.cssVar, field.value);
    }
});

/*
 * كشف رقم الزبون (x-phone) واحداً في كل مرّة: أي كشف جديد يُخفي سابقه، ويعود
 * الرقم مخفيّاً بعد نصف دقيقة. الحماية هنا ليست تقنية — الرقم في الصفحة على
 * أي حال — بل جعل النسخ الجَماعيّ عملاً مقصوداً يُرى.
 */
let revealedPhone = null;
let revealTimer = null;

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-phone-reveal]') : null;
    if (!button) return;

    const cell = button.closest('[data-phone]').querySelector('[data-phone-value]');

    if (revealedPhone && revealedPhone !== cell) {
        revealedPhone.textContent = revealedPhone.dataset.masked;
    }

    clearTimeout(revealTimer);

    if (cell.textContent === cell.dataset.real) {
        cell.textContent = cell.dataset.masked;
        revealedPhone = null;

        return;
    }

    cell.textContent = cell.dataset.real;
    revealedPhone = cell;
    revealTimer = setTimeout(() => {
        cell.textContent = cell.dataset.masked;
        revealedPhone = null;
    }, 30000);
});

/*
 * تلميح علامات الرسوم: data-tip على العمود أو الشريط، سطراً في كل سطر. يُكتب
 * نصّاً لا HTML، ويظهر بالمرور وبالتركيز — وما فيه مكتوبٌ في جدول الرسم أيضاً،
 * فلا يُحجب رقمٌ خلف التحويم.
 */
let chartTip = null;

const showChartTip = (mark) => {
    if (!chartTip) {
        chartTip = document.createElement('div');
        chartTip.className = 'chart-tip';
        chartTip.setAttribute('role', 'tooltip');
        document.body.append(chartTip);
    }

    chartTip.textContent = mark.dataset.tip;
    chartTip.hidden = false;

    // فوق العلامة في وسطها، ولا يخرج من الشاشة يميناً ولا يساراً
    const box = mark.getBoundingClientRect();
    const left = Math.min(Math.max(8, box.left + box.width / 2 - chartTip.offsetWidth / 2), window.innerWidth - chartTip.offsetWidth - 8);
    const above = box.top - chartTip.offsetHeight - 8;
    chartTip.style.left = `${left + window.scrollX}px`;
    chartTip.style.top = `${(above < 8 ? box.bottom + 8 : above) + window.scrollY}px`;
};

const hideChartTip = () => {
    if (chartTip) chartTip.hidden = true;
};

document.addEventListener('pointerover', (event) => {
    const mark = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
    mark ? showChartTip(mark) : hideChartTip();
});

document.addEventListener('focusin', (event) => {
    const mark = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
    mark ? showChartTip(mark) : hideChartTip();
});

document.addEventListener('focusout', hideChartTip);
window.addEventListener('scroll', hideChartTip, { passive: true });

/** «ادفع» في المصروفات: زرٌّ في كل صفّ ونافذةٌ واحدة مشتركة تحمل بياناته */
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-pay-expense]') : null;
    if (!button) return;

    const dialog = document.getElementById('pay-expense');
    document.getElementById('pay-expense-form').action = button.dataset.url;
    dialog.querySelector('[data-pay-number]').textContent = button.dataset.number;
    dialog.querySelector('[data-pay-amount]').textContent = button.dataset.amount;
    dialog.querySelector('[data-pay-description]').textContent = button.dataset.description;
    dialog.showModal();
});

/**
 * المندوب ينتظر جواب الكول سنتر عند الباب (docs/plan/30): data-ticket-poll يحمل رابط
 * حال طلبه، فتسأل الصفحة كل ١٥ ثانية، وتُحدَّث وحدها حين يتغيّر — اعتُمد أو رُفض.
 */
const ticketPoll = document.querySelector('[data-ticket-poll]');

if (ticketPoll) {
    const waiting = ticketPoll.dataset.ticketStatus;
    const timer = window.setInterval(async () => {
        try {
            const response = await fetch(ticketPoll.dataset.ticketPoll, { headers: { Accept: 'application/json' } });

            if (!response.ok) return;

            const { status } = await response.json();

            if (status !== waiting) {
                window.clearInterval(timer);
                window.location.reload();
            }
        } catch {
            // الشبكة انقطعت في الشارع: يُسأل في الدورة القادمة
        }
    }, 15000);
}

/**
 * ما حُدِّد في جدولٍ يظهر أسفل الشاشة بعدده وما يُعمل به (الكشف المسودّة): data-picked-bar
 * باسم النموذج الذي تُرسَل إليه الصفوف (input[form=…]).
 * - data-picked-net: مجموع data-net للمحدَّد، ويُزاد عليه حقل data-picked-add (الخصومات).
 * - الزرّ يسأل بـdata-confirm-some، أو بـdata-confirm-all إن حُدِّد كل ما في الكشف
 *   (data-picked-total)، و{count} و{net} فيهما يُملآن.
 * - data-picked-when / data-picked-unless في الصفحة: ما يظهر مع التحديد وما يختفي به.
 * - «إلغاء التحديد» data-picked-clear يُفرغه.
 */
const pickedBars = [...document.querySelectorAll('[data-picked-bar]')];
const pickedBoxes = (bar) => [...document.querySelectorAll(`input[type="checkbox"][form="${bar.dataset.pickedBar}"]`)];
const amount = new Intl.NumberFormat('en-US');

const refreshPicked = () => {
    for (const bar of pickedBars) {
        const form = bar.dataset.pickedBar;
        const boxes = pickedBoxes(bar);
        const picked = boxes.filter((box) => box.checked);
        const count = picked.length;
        const all = count > 0 && count >= Number(bar.dataset.pickedTotal || boxes.length);
        const extra = bar.dataset.pickedAdd ? numberValue(document.getElementById(bar.dataset.pickedAdd)) : 0;
        const net = picked.reduce((sum, box) => sum + Number(box.dataset.net || 0), 0) + extra;

        bar.hidden = count === 0;
        bar.querySelector('[data-picked-count]').textContent =
            shipments(count) + (all && bar.dataset.pickedAll ? ` — ${bar.dataset.pickedAll}` : '');

        const total = bar.querySelector('[data-picked-net]');
        if (total) total.textContent = amount.format(net);

        for (const button of bar.querySelectorAll('[data-confirm-some]')) {
            const text = all && button.dataset.confirmAll ? button.dataset.confirmAll : button.dataset.confirmSome;
            button.dataset.confirm = text.replace('{count}', shipments(count)).replace('{net}', amount.format(net));
        }

        // تحديد الكل كإقفال الكشف كلّه: لا يتبدّل له شيء
        for (const el of document.querySelectorAll(`[data-picked-when="${form}"]`)) el.hidden = count === 0 || all;
        for (const el of document.querySelectorAll(`[data-picked-unless="${form}"]`)) el.hidden = count > 0 && !all;
    }
};

if (pickedBars.length > 0) {
    // بعد «الكل» في رأس الجدول: مقبضه مسجَّلٌ قبل هذا فيُحدِّد أوّلاً. و«input» للخصومات وهي تُكتب
    document.addEventListener('change', refreshPicked);
    document.addEventListener('input', refreshPicked);

    document.addEventListener('click', (event) => {
        const bar = event.target instanceof Element ? event.target.closest('[data-picked-clear]')?.closest('[data-picked-bar]') : null;
        if (!bar) return;

        const boxes = pickedBoxes(bar);
        for (const box of boxes) box.checked = false;
        for (const all of new Set(boxes.map((box) => box.closest('table')?.querySelector('[data-check-all-in]')))) {
            if (all) all.checked = false;
        }
        refreshPicked();
    });

    refreshPicked();
}

/**
 * «نسخ رسالة التاجر» (docs/plan/41): data-copy-text يُنسخ كما هو، والزرّ يقول «نُسخت»
 * لحظةً. وفي صفحةٍ بلا حافظةٍ (غير آمنة) يُحدَّد النصّ في خانةٍ مؤقّتة فيُنسخ يدوياً.
 */
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-text]');
    if (!button) return;

    const text = button.dataset.copyText;
    let copied = false;

    try {
        await navigator.clipboard.writeText(text);
        copied = true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.append(area);
        area.select();
        copied = document.execCommand('copy');
        area.remove();
    }

    const label = button.dataset.label ?? button.textContent;
    button.dataset.label = label;
    button.textContent = copied ? '✓ نُسخت — الصقها' : 'تعذّر النسخ';
    window.setTimeout(() => { button.textContent = label; }, 2000);
});

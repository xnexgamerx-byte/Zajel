/**
 * شريط التحديث من القائمة (_bulk_bar): اختيار صفوفٍ — أو «الكل» — ثم حالةٌ
 * جديدة لها، بلا دخول كل شحنة.
 *
 * على كل حالةٍ في القائمة المنسدلة عدد ما سيتحرّك إليها من المختار (من حال
 * كل صفّ، أو من عدّ البحث كلّه في «الكل»)، فلا يُضغط «تحديث» على عمى. وما
 * لا يتحرّك منها يُعطَّل، والسؤال الأخير قبل الإرسال يقول كم ومن أين.
 */
export function initBulkBar(bar) {
    const rows = [...document.querySelectorAll('[data-row-select]')];
    // «الكل» في رأس الجدول، وبديله على الهاتف حيث الرأس مخفيّ
    const selectAlls = [...document.querySelectorAll('[data-select-all]')];
    const counter = bar.querySelector('[data-bulk-count]');
    const scope = bar.querySelector('[data-bulk-scope]');
    const allFields = bar.querySelector('[data-bulk-all]');
    const allToggle = bar.querySelector('[data-bulk-all-toggle]');
    const status = bar.querySelector('[data-bulk-status]');
    const reason = bar.querySelector('select[name="failure_reason_id"]');
    const note = bar.querySelector('input[name="note"]');
    const print = bar.querySelector('[data-bulk-print]');
    const conditionals = [...bar.querySelectorAll('[data-bulk-when]')];

    const total = Number(bar.dataset.total || 0);
    const max = Number(bar.dataset.max || 0);
    const sources = JSON.parse(bar.dataset.sources || '{}');
    const counts = JSON.parse(bar.dataset.counts || '{}');
    const number = new Intl.NumberFormat('en-US');

    // «الكل»: كل ما يطابق البحث، لا الصفحة وحدها
    let everything = false;

    /** «شحنة واحدة، شحنتان، 3 شحنات، 11 شحنة» — كما يكتبها الخادم (Arabic::shipments) */
    const shipments = (n) => {
        if (n === 1) return 'شحنة واحدة';
        if (n === 2) return 'شحنتان';
        return `${number.format(n)} ${n >= 3 && n <= 10 ? 'شحنات' : 'شحنة'}`;
    };

    const checked = () => rows.filter((row) => row.checked);

    /** عدد المختار في كل حال: من الصفوف، أو من عدّ البحث كلّه */
    const tally = () => {
        if (everything) return counts;

        const byStatus = {};
        for (const row of checked()) byStatus[row.dataset.status] = (byStatus[row.dataset.status] || 0) + 1;
        return byStatus;
    };

    const movable = (target, byStatus = tally()) =>
        (sources[target] || []).reduce((sum, from) => sum + (byStatus[from] || 0), 0);

    const chosenCount = () => (everything ? total : checked().length);

    const refreshFields = () => {
        const value = status?.value ?? '';

        for (const field of conditionals) {
            const on = field.dataset.bulkWhen.split(' ').includes(value);
            field.hidden = !on;
            field.disabled = !on;
            if (field.tagName === 'SELECT') field.required = on;
        }

        // سببٌ يطلب ملاحظةً يجعلها لازمة، كما في صفحة الشحنة
        if (note) {
            const needsNote = value === 'failed_attempt' && reason?.selectedOptions[0]?.dataset.requiresNote === '1';
            note.required = needsNote;
            note.placeholder = needsNote ? 'ملاحظة (لازمة لهذا السبب)' : 'ملاحظة (اختيارية)';
        }
    };

    const refresh = () => {
        const chosen = chosenCount();

        counter.textContent = number.format(chosen);
        if (scope) scope.hidden = !everything;
        bar.hidden = chosen === 0;
        if (allFields) allFields.disabled = !everything;
        if (allToggle) allToggle.hidden = everything || rows.length === 0 || checked().length < rows.length;
        // الطباعة للصفحة المعروضة: في «الكل» لا تُوهم بطباعة ما لم يُعرض
        if (print) print.hidden = everything;

        for (const selectAll of selectAlls) {
            const on = checked().length;
            selectAll.checked = on > 0 && on === rows.length;
            selectAll.indeterminate = on > 0 && on < rows.length;
        }

        if (status) {
            const byStatus = tally();

            for (const option of status.options) {
                if (!option.value) continue;
                const n = movable(option.value, byStatus);
                option.textContent = `${option.dataset.label} — ${number.format(n)}`;
                option.disabled = n === 0;
            }

            if (status.selectedOptions[0]?.disabled) status.value = '';
        }

        refreshFields();
    };

    const choosePage = (on) => {
        everything = false;
        for (const row of rows) row.checked = on;
        refresh();
    };

    for (const row of rows) {
        row.addEventListener('change', () => {
            // صفٌّ أُزيل من «الكل» يعيد الاختيار إلى الصفحة
            if (everything && !row.checked) everything = false;
            refresh();
        });
    }

    for (const selectAll of selectAlls) {
        selectAll.addEventListener('change', () => choosePage(selectAll.checked));
    }

    allToggle?.addEventListener('click', () => {
        everything = true;
        for (const row of rows) row.checked = true;
        refresh();
    });

    bar.querySelector('[data-bulk-clear]')?.addEventListener('click', () => choosePage(false));

    status?.addEventListener('change', refreshFields);
    reason?.addEventListener('change', refreshFields);

    print?.addEventListener('click', () => {
        const url = new URL(print.dataset.bulkPrint, location.href);
        for (const row of checked()) url.searchParams.append('ids[]', row.value);
        window.open(url, '_blank', 'noopener');
    });

    /*
     * «تحديث الكل» فوق الجدول: كل نتائج البحث بضغطة — الصفحة وحدها إن كانت
     * تحملها كلّها — ثم الحالة الجديدة.
     */
    for (const button of document.querySelectorAll('[data-bulk-everything]')) {
        button.addEventListener('click', () => {
            for (const row of rows) row.checked = true;
            everything = total > rows.length;
            refresh();
            status?.focus();
        });
    }

    bar.addEventListener('submit', (event) => {
        const option = status?.selectedOptions[0];
        if (!option?.value) return;

        const chosen = chosenCount();
        const n = movable(option.value);

        if (everything && total > max) {
            event.preventDefault();
            window.alert(`في البحث ${shipments(total)}، والحدّ ${number.format(max)} في المرّة — اختر يوماً أو مندوباً ثم أعد.`);
            return;
        }

        const lines = [`تحديث ${shipments(n)} إلى «${option.dataset.label}»؟`];
        if (option.value === 'delivered') lines.push('يُسجَّل لكلٍّ منها مبلغها كاملاً بذمّة مندوبها.');
        if (n < chosen) lines.push(`وتُتخطّى ${shipments(chosen - n)} لا يصحّ نقلها، ويُقال لماذا.`);

        if (!window.confirm(lines.join('\n'))) {
            event.preventDefault();
            return;
        }

        // مرّةً واحدة: الضغطة الثانية لا تُرسل الدفعة ثانيةً وهي تُحفظ
        const submit = bar.querySelector('button[type="submit"]');
        submit.disabled = true;
        submit.textContent = 'جارٍ التحديث…';
    });

    refresh();
}

/**
 * «يوم» فوق الجدول: تاريخٌ يُختار فتُفتح القائمة بشحنات ذلك اليوم وحده.
 * الرابط جاهزٌ من الخادم بالفلاتر كلّها، وفيه __DAY__ مكان التاريخ.
 */
export function initDayPick(input) {
    input.addEventListener('change', () => {
        if (/^\d{4}-\d{2}-\d{2}$/.test(input.value)) {
            location.assign(input.dataset.dayPick.replaceAll('__DAY__', input.value));
        }
    });
}

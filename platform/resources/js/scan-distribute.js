import { beep } from './beep';

/**
 * «توزيع بالمسح» (docs/plan/50): كلّ وصلٍ يُمسح يُسأل عنه الخادم — لهذه المحافظة وحدها —
 * فيدخل الجدول، وتظهر منطقته في اللوحة بمندوبها المقترح. ثم «وزّع» يُرسل لكلّ وصلٍ
 * مندوبَ منطقته. الجدول في المتصفّح وحده حتى الحفظ.
 */
export function initScanDistribute(root) {
    const input = root.querySelector('[data-scan-input]');
    const message = root.querySelector('[data-scan-message]');
    const body = root.querySelector('[data-scan-rows]');
    const empty = root.querySelector('[data-scan-empty]');
    const count = root.querySelector('[data-scan-count]');
    const total = root.querySelector('[data-scan-total]');
    const form = root.querySelector('[data-distribute-form]');
    const areasBox = root.querySelector('[data-areas]');
    const areasEmpty = root.querySelector('[data-areas-empty]');
    const options = root.querySelector('[data-courier-options]');
    const rows = new Map();
    const areas = new Map();
    const money = new Intl.NumberFormat('en-US');
    const tones = { green: 'chip-ok', blue: 'chip-info', amber: 'chip-warn', red: 'chip-bad', gray: 'chip-mute', slate: 'chip-mute' };

    const say = (text, bad = false) => {
        message.textContent = text;
        message.className = 'mt-2 min-h-5 text-sm ' + (bad ? 'font-semibold text-bad-700' : 'text-ok-700');
        if (text) beep(!bad);
    };

    const refresh = () => {
        empty.hidden = rows.size > 0;
        areasEmpty.hidden = areas.size > 0;
        count.textContent = String(rows.size);
        total.textContent = money.format([...rows.values()].reduce((sum, row) => sum + row.amount, 0));
        [...body.querySelectorAll('tr[data-id]')].forEach((tr, i) => { tr.firstElementChild.textContent = i + 1; });
        form.querySelector('button[type="submit"]').disabled = rows.size === 0;
    };

    const cell = (text, className = '') => {
        const td = document.createElement('td');
        td.className = className;
        td.textContent = text ?? '—';
        return td;
    };

    /** لوحة المنطقة: اسمها وعدد وصولاتها ومندوبها */
    const areaFor = (data) => {
        const key = String(data.area_id);
        if (areas.has(key)) return areas.get(key);

        const box = document.createElement('div');
        box.className = 'rounded-xl border border-ink-200 p-3';
        const head = document.createElement('div');
        head.className = 'mb-2 flex items-center justify-between text-sm';
        const name = document.createElement('span');
        name.className = 'font-bold';
        name.textContent = data.area;
        const tally = document.createElement('span');
        tally.className = 'num text-ink-500';
        head.append(name, tally);

        const select = document.createElement('select');
        select.className = 'field-input';
        select.setAttribute('aria-label', `مندوب ${data.area}`);
        select.append(options.content.cloneNode(true));
        if (data.suggested) select.value = String(data.suggested);
        select.addEventListener('change', () => { box.classList.toggle('ring-2', !select.value); });

        box.append(head, select);
        areasBox.append(box);

        const area = { box, select, tally, ids: new Set() };
        areas.set(key, area);
        return area;
    };

    const tallyArea = (key) => {
        const area = areas.get(key);
        if (!area) return;
        if (area.ids.size === 0) {
            area.box.remove();
            areas.delete(key);
        } else {
            area.tally.textContent = `${area.ids.size} وصل`;
        }
    };

    const add = (data) => {
        const key = String(data.area_id);
        const area = areaFor(data);
        area.ids.add(data.id);

        const tr = document.createElement('tr');
        tr.dataset.id = data.id;

        const link = document.createElement('a');
        link.href = data.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'font-mono font-semibold text-[var(--brand)] hover:underline';
        link.textContent = data.number;
        const numberCell = cell('', 'whitespace-nowrap');
        numberCell.dir = 'ltr';
        numberCell.append(link);

        const status = document.createElement('span');
        status.className = 'chip ' + (tones[data.tone] ?? 'chip-mute');
        status.textContent = data.status;
        const statusCell = cell('', 'whitespace-nowrap');
        statusCell.append(status);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'text-bad-700 hover:underline';
        remove.textContent = 'احذف';
        remove.setAttribute('aria-label', `احذف ${data.number} من الجدول`);
        remove.addEventListener('click', () => {
            rows.delete(data.id);
            area.ids.delete(data.id);
            tr.remove();
            tallyArea(key);
            refresh();
            input.focus();
        });
        const removeCell = cell('', 'text-end');
        removeCell.append(remove);

        tr.append(
            cell('', 'num'), numberCell, cell(data.area, 'font-semibold'), cell(data.merchant), statusCell,
            cell(money.format(data.amount), 'num whitespace-nowrap'), cell(data.phone, 'num'), removeCell,
        );

        body.prepend(tr);
        rows.set(data.id, { ...data, key });
        tallyArea(key);
        refresh();
    };

    let busy = false;

    const scan = async () => {
        const number = input.value.trim();
        input.value = '';

        if (!number || busy) return;

        if ([...rows.values()].some((row) => row.number === number)) {
            say(`${number} في الجدول سلفاً.`, true);
            return;
        }

        busy = true;

        try {
            const url = new URL(root.dataset.lookup, location.href);
            url.searchParams.set('number', number);
            url.searchParams.set('governorate', root.dataset.governorate);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (!response.ok) {
                say(data.error ?? `تعذّر العثور على ${number}.`, true);
            } else if (rows.has(data.id)) {
                say(`${data.number} في الجدول سلفاً.`, true);
            } else {
                add(data);
                say(`أُضيف ${data.number} — ${data.area}.`);
            }
        } catch {
            say('انقطع الاتصال: امسحه ثانيةً.', true);
        } finally {
            busy = false;
            input.focus();
        }
    };

    // الماسح الضوئي يكتب الرقم ثم Enter: Enter هنا مسحٌ لا إرسالٌ لنموذج
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            scan();
        }
    });
    root.querySelector('[data-scan-add]')?.addEventListener('click', scan);

    root.querySelector('[data-scan-clear]')?.addEventListener('click', () => {
        rows.clear();
        areas.forEach((area) => area.box.remove());
        areas.clear();
        body.querySelectorAll('tr[data-id]').forEach((tr) => tr.remove());
        refresh();
        say('');
        input.focus();
    });

    // لكلّ وصلٍ مندوبُ منطقته: courier[رقم الشحنة] = رقم المندوب
    form.addEventListener('submit', (event) => {
        const missing = [...areas.values()].filter((area) => !area.select.value);
        if (missing.length) {
            event.preventDefault();
            missing.forEach((area) => area.box.classList.add('ring-2', 'ring-bad-200'));
            say('اختر مندوباً لكلّ منطقة قبل التوزيع.', true);
            return;
        }

        form.querySelectorAll('input[name^="courier["]').forEach((el) => el.remove());
        for (const row of rows.values()) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = `courier[${row.id}]`;
            hidden.value = areas.get(row.key).select.value;
            form.append(hidden);
        }
    });

    refresh();
}

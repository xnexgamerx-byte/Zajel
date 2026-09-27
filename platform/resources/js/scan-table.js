/**
 * جدول المسح: كل وصلٍ يُمسح يُسأل عنه الخادم ويُضاف صفّاً، ثم يُرسَل الجدول
 * كلّه بفعلٍ واحد. الجدول في المتصفّح وحده حتى الحفظ.
 */
export function initScanTable(root) {
    const input = root.querySelector('[data-scan-input]');
    const message = root.querySelector('[data-scan-message]');
    const body = root.querySelector('[data-scan-rows]');
    const empty = root.querySelector('[data-scan-empty]');
    const count = root.querySelector('[data-scan-count]');
    const total = root.querySelector('[data-scan-total]');
    const forms = [...root.querySelectorAll('[data-scan-form]')];
    const rows = new Map();
    const money = new Intl.NumberFormat('en-US');
    const tones = { green: 'chip-ok', blue: 'chip-info', amber: 'chip-warn', red: 'chip-bad', gray: 'chip-mute', slate: 'chip-mute' };

    const say = (text, bad = false) => {
        message.textContent = text;
        message.className = 'mt-2 min-h-5 text-sm ' + (bad ? 'font-semibold text-bad-700' : 'text-ok-700');
    };

    const refresh = () => {
        empty.hidden = rows.size > 0;
        count.textContent = String(rows.size);
        total.textContent = money.format([...rows.values()].reduce((sum, row) => sum + row.amount, 0));
        [...body.querySelectorAll('tr[data-id]')].forEach((tr, i) => { tr.firstElementChild.textContent = i + 1; });
        for (const form of forms) form.querySelector('button[type="submit"]').disabled = rows.size === 0;
    };

    const cell = (text, className = '') => {
        const td = document.createElement('td');
        td.className = className;
        td.textContent = text ?? '—';
        return td;
    };

    const add = (data) => {
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
            tr.remove();
            refresh();
            input.focus();
        });
        const removeCell = cell('', 'text-end');
        removeCell.append(remove);

        tr.append(
            cell('', 'num'), numberCell, cell(data.merchant), statusCell,
            cell(money.format(data.amount), 'num whitespace-nowrap'), cell(data.destination),
            cell(data.branch), cell(data.phone, 'num'), cell(data.bag, 'num'), removeCell,
        );

        body.prepend(tr);
        rows.set(data.id, data);
        refresh();
    };

    let busy = false;

    const scan = async () => {
        const number = input.value.trim();
        input.value = '';

        if (!number || busy) return;

        const known = [...rows.values()].find((row) => row.number === number);
        if (known) {
            say(`${number} في الجدول سلفاً.`, true);
            return;
        }

        busy = true;

        try {
            const url = new URL(root.dataset.lookup, location.href);
            url.searchParams.set('number', number);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (!response.ok) {
                say(data.error ?? `تعذّر العثور على ${number}.`, true);
            } else if (rows.has(data.id)) {
                say(`${data.number} في الجدول سلفاً.`, true);
            } else {
                add(data);
                say(`أُضيف ${data.number} — ${data.status}.`);
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
        body.querySelectorAll('tr[data-id]').forEach((tr) => tr.remove());
        refresh();
        say('');
        input.focus();
    });

    for (const form of forms) {
        form.addEventListener('submit', () => {
            form.querySelectorAll('input[name="shipment_ids[]"]').forEach((el) => el.remove());
            for (const id of rows.keys()) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'shipment_ids[]';
                hidden.value = id;
                form.append(hidden);
            }
        });
    }

    refresh();
}

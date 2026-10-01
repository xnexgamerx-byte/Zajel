import { initSearchableSelects } from './searchable-select';
import { initNumberInputs } from './number-inputs';

/**
 * الإدخال السريع: صفوفٌ تُضاف خمسةً خمسة حتى الثلاثين، ومنطقة كل صفٍّ تتبع
 * محافظته (أو محافظة الصفحة)، والمبلغ بالألف يُرى كاملاً تحته أثناء الكتابة.
 */
export function initQuickEntry(form) {
    const body = form.querySelector('[data-quick-rows]');
    const template = document.querySelector('[data-quick-template]');
    const counter = form.querySelector('[data-quick-count]');
    const header = form.querySelector('[data-quick-header-governorate]');
    const max = Number(form.dataset.max || 30);
    const cities = JSON.parse(document.getElementById('quick-cities')?.textContent || '{}');
    const money = new Intl.NumberFormat('en-US');
    let next = body.querySelectorAll('[data-quick-row]').length;

    const governorateOf = (row) => row.querySelector('[data-quick-governorate]')?.value ?? header?.value ?? '';

    const fillCities = (row) => {
        const select = row.querySelector('[data-quick-city]');
        if (!select) return;

        const keep = select.value || select.dataset.old || '';
        select.innerHTML = '';
        select.append(new Option('المنطقة', ''));

        for (const city of cities[governorateOf(row)] ?? []) {
            const option = new Option(city.name, city.id);
            option.selected = String(city.id) === String(keep);
            select.append(option);
        }

        select.dataset.old = '';
    };

    const preview = (row) => {
        const input = row.querySelector('[data-quick-amount]');
        const out = row.querySelector('[data-quick-preview]');
        if (!input || !out) return;

        const digits = input.value.trim()
            .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
            .replace('٫', '.')
            .replace(/٬/g, '');

        const value = Number(digits);
        out.textContent = digits === '' || Number.isNaN(value) ? '' : `= ${money.format(Math.round(value * 1000))} د.ع`;
        out.classList.toggle('text-bad-700', value >= 10000);
    };

    const wire = (row) => {
        fillCities(row);
        initSearchableSelects(row);
        // صفوف «أضف خمسة» من قالب: هاتفها ١١ رقماً كالصفوف الأولى
        initNumberInputs(row);
        preview(row);
        row.querySelector('[data-quick-governorate]')?.addEventListener('change', () => fillCities(row));
        row.querySelector('[data-quick-amount]')?.addEventListener('input', () => preview(row));
    };

    const renumber = () => {
        const rows = [...body.querySelectorAll('[data-quick-row]')];
        rows.forEach((row, i) => { row.querySelector('[data-quick-index]').textContent = i + 1; });
        counter.textContent = rows.length;
        form.querySelector('[data-quick-add]').disabled = rows.length >= max;
    };

    body.querySelectorAll('[data-quick-row]').forEach(wire);

    header?.addEventListener('change', () => body.querySelectorAll('[data-quick-row]').forEach(fillCities));

    form.querySelector('[data-quick-add]').addEventListener('click', () => {
        const room = max - body.querySelectorAll('[data-quick-row]').length;

        for (let n = 0; n < Math.min(5, room); n++) {
            const html = template.innerHTML.replaceAll('__I__', String(next++));
            body.insertAdjacentHTML('beforeend', html.trim());
            wire(body.lastElementChild);
        }

        renumber();
    });

    body.addEventListener('click', (event) => {
        const button = event.target.closest('[data-quick-remove]');
        if (!button) return;

        const rows = body.querySelectorAll('[data-quick-row]');
        const row = button.closest('[data-quick-row]');

        // الصفّ الأخير يُفرغ ولا يُحذف: جدولٌ بلا صفوف لا يُكتب فيه
        if (rows.length === 1) {
            row.querySelectorAll('input:not([type="checkbox"])').forEach((input) => { input.value = ''; });
            row.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = false; });
            preview(row);
            return;
        }

        row.remove();
        renumber();
    });

    renumber();
}

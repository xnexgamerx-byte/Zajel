import { beep } from './beep';

/**
 * مسح الوصولات في قائمةٍ معروضة (شاشات الراجع): كل وصلٍ يُمسح — بباركوده أو برمز
 * QR الذي عليه — يُسأل عنه الخادم؛ فإن كان من القائمة عُلِّم وصعد إلى أعلاها، وإلّا
 * قيل لماذا. والعدّاد «ممسوح كذا من كذا» يكشف ما لم يصل بعد.
 *
 * وبلا قائمةٍ تُعلَّم (data-open): تُفتح قائمة صاحب الطرد وهو معلَّمٌ فيها.
 */
export function initScanBox(root) {
    const input = root.querySelector('[data-scan-box-input]');
    const message = root.querySelector('[data-scan-box-message]');
    const count = root.querySelector('[data-scan-box-count]');
    const total = root.querySelector('[data-scan-box-total]');

    const boxes = () => [...document.querySelectorAll('input[type="checkbox"][name="shipment_ids[]"]')];
    const scanned = new Set(boxes().filter((box) => box.closest('tr')?.hasAttribute('data-scanned')).map((box) => box.value));

    const say = (text, bad = false) => {
        message.textContent = text;
        message.className = 'min-h-5 text-sm ' + (bad ? 'font-semibold text-bad-700' : 'text-ok-700');
        beep(!bad);
    };

    const refresh = () => {
        if (count) count.textContent = String(scanned.size);
        if (total) total.textContent = String(boxes().length);
    };

    const mark = (box) => {
        box.checked = true;
        scanned.add(box.value);

        const row = box.closest('tr');
        if (row) {
            row.dataset.scanned = '';
            row.classList.add('bg-ok-50');
            row.parentElement.prepend(row);
        }

        refresh();
    };

    let busy = false;

    const scan = async () => {
        const code = input.value.trim();
        input.value = '';

        if (!code || busy) return;

        busy = true;

        try {
            const url = new URL(root.dataset.lookup, location.href);
            url.searchParams.set('code', code);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (!response.ok) {
                say(data.error ?? `تعذّر العثور على ${code}.`, true);
                return;
            }

            if (root.dataset.open) {
                const open = new URL(root.dataset.open, location.href);
                open.searchParams.set('merchant_id', data.merchant_id);
                open.searchParams.set('scanned', data.id);
                location.href = open.toString();
                return;
            }

            const box = boxes().find((el) => el.value === String(data.id));

            if (!box) {
                say(`${data.number} ليس في هذه القائمة${data.courier ? ` — راجعٌ مع ${data.courier}` : ''}.`, true);
            } else if (scanned.has(box.value)) {
                say(`${data.number} ممسوحٌ سلفاً.`, true);
            } else {
                mark(box);
                say(`✓ ${data.number}${data.merchant ? ' — ' + data.merchant : ''}`);
            }
        } catch {
            say('انقطع الاتصال: امسحه ثانيةً.', true);
        } finally {
            busy = false;
            input.focus();
        }
    };

    // الماسح يكتب الرمز ثم Enter: Enter هنا مسحٌ لا إرسالٌ لنموذج
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            scan();
        }
    });
    root.querySelector('[data-scan-box-add]')?.addEventListener('click', scan);

    refresh();
}

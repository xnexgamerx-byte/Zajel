/**
 * «اقرأ الطلب من صورة أو رسالة» (components/order-reader، docs/plan/34).
 *
 * لقطة شاشةٍ تُختار أو تُلصق (Ctrl+V) أو تُسحب إلى البطاقة، أو نصّ رسالةٍ يُلصق: يُرسل إلى
 * الخادم فيُقرأ هناك، ويعود بما يملأ النموذج. المحافظة أوّلاً لتُبنى قائمة مناطقها ثم المنطقة،
 * والمبلغ يُجمَّع كما يُكتب. وما مُلئ يُضاء لحظةً ليُراجَع، وما لم يُعثر عليه يُقال.
 */

const NAMES = {
    recipient_name: 'الاسم',
    recipient_phone: 'الهاتف',
    recipient_phone_alt: 'الهاتف البديل',
    governorate_id: 'المحافظة',
    city_id: 'المنطقة',
    landmark: 'النقطة الدالّة',
    cod_amount: 'المبلغ',
    pieces_count: 'العدد',
    notes: 'الملاحظة',
};

const TONES = {
    info: 'bg-info-50 text-info-700',
    ok: 'bg-ok-50 text-ok-700',
    warn: 'bg-warn-50 text-warn-700',
    bad: 'bg-bad-50 text-bad-700',
};

/** يملأ الحقول بما قُرئ: المحافظة قبل المنطقة، وكلّ حقلٍ يُعلَم بتغيّره كما لو كُتب */
function fill(form, fields) {
    const filled = [];
    const order = ['governorate_id', 'city_id', ...Object.keys(fields).filter((n) => n !== 'governorate_id' && n !== 'city_id')];

    for (const name of order) {
        if (!(name in fields)) continue;

        const field = form.elements.namedItem(name);
        if (!(field instanceof HTMLElement) || field.disabled || field.type === 'hidden') continue;

        const value = String(fields[name]);

        if (field instanceof HTMLSelectElement && ![...field.options].some((option) => option.value === value)) continue;

        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));

        // حقلٌ في «خيارات إضافية» المطويّة يُفتح ليُرى
        const extras = field.closest('details');
        if (extras) extras.open = true;

        filled.push(name);

        const box = field.closest('div');
        const glow = ['rounded-xl', 'outline', 'outline-2', 'outline-offset-4', 'outline-info-200'];
        box?.classList.add(...glow);
        window.setTimeout(() => box?.classList.remove(...glow), 3500);
    }

    return filled;
}

export function initOrderReader(section) {
    const form = document.getElementById(section.dataset.form);
    if (!form) return;

    const status = section.querySelector('[data-order-status]');
    const lines = section.querySelector('[data-order-lines]');
    const linesText = section.querySelector('[data-order-lines-text]');
    const image = section.querySelector('[data-order-image]');
    const paste = section.querySelector('[data-order-paste]');
    const pasteOpen = section.querySelector('[data-order-paste-open]');
    const text = section.querySelector('[data-order-text]');
    const read = section.querySelector('[data-order-read]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const show = (message, tone) => {
        status.hidden = false;
        status.className = `mt-3 rounded-2xl px-4 py-3 text-sm ${TONES[tone]}`;
        status.textContent = message;
    };

    const report = (data) => {
        const filled = fill(form, data.fields ?? {});
        const found = data.found ?? {};
        const missing = data.missing ?? [];
        const parts = [];

        if (filled.length === 0) {
            show('لم يُعثر على شيءٍ من الطلب هنا — الصق الرسالة نصّاً، أو اكتب الحقول.', 'bad');
        } else {
            // بترتيب النموذج لا بترتيب الملء
            const read = Object.keys(NAMES).filter((name) => filled.includes(name));
            parts.push(`قُرئ: ${read.map((name) => (found[name] ? `${NAMES[name]} ${found[name]}` : NAMES[name])).join('، ')}.`);
            if (missing.length) parts.push(`لم يُعثر على: ${missing.join('، ')} — اكتبها.`);
            parts.push(...(data.warnings ?? []));

            const merchant = form.elements.namedItem('merchant_id');
            if (merchant instanceof HTMLSelectElement && merchant.value === '') parts.push('واختر التاجر.');

            parts.push('راجع الحقول قبل الحفظ.');
            show(parts.join(' '), missing.length ? 'warn' : 'ok');
        }

        linesText.textContent = (data.lines ?? []).join('\n');
        lines.hidden = !(data.lines ?? []).length;
    };

    const send = async (body, busy) => {
        show(busy, 'info');
        section.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(section.dataset.url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                credentials: 'same-origin',
                body,
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                report(data);
            } else if (response.status === 429) {
                show('قراءاتٌ كثيرة في دقيقة — انتظر قليلاً ثم أعد.', 'bad');
            } else if (response.status === 413) {
                show('الصورة كبيرة جداً — أرسل لقطة الشاشة نفسها.', 'bad');
            } else if (response.status === 419) {
                show('انتهت الجلسة — حدّث الصفحة ثم أعد.', 'bad');
            } else {
                // أوّل خطأ تحقّقٍ بنصّه، لا ملخّص Laravel بذيله الإنجليزي
                const first = Object.values(data.errors ?? {})[0]?.[0];
                show(first ?? data.message ?? 'تعذّرت القراءة — حاول مجدداً.', 'bad');
            }
        } catch {
            show('تعذّر الاتصال بالخادم — حاول مجدداً.', 'bad');
        } finally {
            section.removeAttribute('aria-busy');
        }
    };

    const upload = (file) => {
        if (!file) return;
        const body = new FormData();
        body.append('image', file);
        send(body, 'تُقرأ الصورة… ثوانٍ قليلة.');
    };

    image?.addEventListener('change', () => {
        upload(image.files[0]);
        image.value = '';
    });

    pasteOpen.addEventListener('click', () => {
        paste.hidden = !paste.hidden;
        pasteOpen.setAttribute('aria-expanded', String(!paste.hidden));
        if (!paste.hidden) text.focus();
    });

    read.addEventListener('click', () => {
        if (text.value.trim() === '') {
            text.focus();
            return;
        }
        const body = new FormData();
        body.append('text', text.value);
        send(body, 'تُقرأ الرسالة…');
    });

    if (!image) return;

    // صورةٌ في الحافظة (لقطة شاشة، أو «نسخ الصورة» من واتساب ويب): تُلصق في أيّ مكانٍ من الصفحة
    document.addEventListener('paste', (event) => {
        const item = [...(event.clipboardData?.items ?? [])].find((entry) => entry.type.startsWith('image/'));
        if (!item) return;
        event.preventDefault();
        upload(item.getAsFile());
    });

    const glow = ['ring-2', 'ring-primary-300'];
    section.addEventListener('dragover', (event) => {
        if (![...(event.dataTransfer?.items ?? [])].some((entry) => entry.kind === 'file')) return;
        event.preventDefault();
        section.classList.add(...glow);
    });
    section.addEventListener('dragleave', () => section.classList.remove(...glow));
    section.addEventListener('drop', (event) => {
        event.preventDefault();
        section.classList.remove(...glow);
        upload([...(event.dataTransfer?.files ?? [])].find((file) => file.type.startsWith('image/')));
    });
}

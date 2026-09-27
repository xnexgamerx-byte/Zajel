/**
 * قائمة منسدلة بالبحث: <select data-searchable>.
 *
 * لبغداد وحدها ٣٥٥ منطقة، منها ثلاثون تبدأ بـ«الدورة» — لا تُختار بالتمرير.
 * يُكتب جزء الاسم («دورة صحة»، «سيدية») فتظهر المطابقة، وأقربها أوّلاً.
 *
 * الـ<select> الأصليّ يبقى في النموذج وهو ما يُرسَل: مخفيٌّ تحت الحقل لا
 * محذوف، فيبقى «مطلوب» يعمل، ويبقى من يبني خياراته (المناطق تتبع المحافظة)
 * يبنيها كما كان — والحقل يتبعها.
 */

/** كما يطوي الخادم الأسماء (App\Support\Arabic::fold): الأعظمية = الاعظميه. */
export function fold(text) {
    return String(text ?? '')
        .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .replace(/ؤ/g, 'و')
        .replace(/ئ/g, 'ي')
        .replace(/[ً-ْـ]/g, '')
        .replace(/[/\\\-_*().,،]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

/** أقرب المطابقة أوّلاً: الاسم نفسه، ثم ما يبدأ بالكلمة، ثم ما يحويها. */
function rank(label, words) {
    const plain = label.replace(/^ال/, '');
    const first = words[0];

    if (label === words.join(' ') || plain === words.join(' ')) return 0;
    if (label.startsWith(first) || plain.startsWith(first)) return 1;
    if (label.split(' ').some((w) => w.startsWith(first) || w.replace(/^ال/, '').startsWith(first))) return 2;

    return 3;
}

let counter = 0;

function enhance(select) {
    const id = `ss-${++counter}`;
    const wrapper = document.createElement('div');
    wrapper.className = 'relative';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = select.className;
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', `${id}-list`);

    const label = select.id ? document.querySelector(`label[for="${select.id}"]`) : null;
    if (label) {
        label.id ||= `${id}-label`;
        input.setAttribute('aria-labelledby', label.id);
        label.addEventListener('click', (event) => {
            event.preventDefault();
            input.focus();
        });
    }

    const list = document.createElement('ul');
    list.id = `${id}-list`;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    list.className = 'absolute inset-x-0 top-full z-30 mt-1.5 max-h-72 overflow-y-auto overscroll-contain rounded-2xl '
        + 'bg-white p-1.5 text-sm shadow-lg ring-1 ring-aeblack-200';

    select.parentNode.insertBefore(wrapper, select);
    wrapper.append(input, select, list);

    // تحت الحقل بحجمه: يُرسَل ويُتحقَّق منه، ولا يُرى ولا يُلمس
    select.classList.add('pointer-events-none', 'absolute', 'inset-0', 'opacity-0');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    let matches = [];
    let active = -1;

    const options = () => [...select.options].filter((option) => option.value !== '');
    const placeholder = () => [...select.options].find((option) => option.value === '')?.textContent.trim() ?? '';
    const selectedText = () => (select.value === '' ? '' : select.selectedOptions[0]?.textContent.trim() ?? '');

    const sync = () => {
        input.value = selectedText();
        input.placeholder = placeholder();
        input.disabled = select.disabled;
    };

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    const highlight = (index) => {
        const items = list.querySelectorAll('[role="option"]');
        items[active]?.classList.remove('bg-primary-50', 'text-primary-800');

        active = index;
        const item = items[active];

        if (item) {
            item.classList.add('bg-primary-50', 'text-primary-800');
            input.setAttribute('aria-activedescendant', item.id);
            item.scrollIntoView({ block: 'nearest' });
        }
    };

    const render = () => {
        const words = fold(input.value === selectedText() ? '' : input.value).split(' ').filter(Boolean);
        const all = options();

        matches = words.length === 0
            ? all
            : all
                .map((option, order) => ({ option, order, label: fold(option.textContent) }))
                .filter(({ label }) => words.every((word) => label.includes(word)))
                .map((entry) => ({ ...entry, rank: rank(entry.label, words) }))
                .sort((a, b) => a.rank - b.rank || a.order - b.order)
                .map(({ option }) => option);

        list.replaceChildren();

        if (matches.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'px-3 py-2 text-ink-500';
            empty.textContent = all.length === 0 ? placeholder() : 'لا شيء بهذا الاسم';
            list.append(empty);
        }

        matches.forEach((option, index) => {
            const item = document.createElement('li');
            item.id = `${id}-${index}`;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(option.selected && option.value !== ''));
            item.className = 'cursor-pointer rounded-xl px-3 py-2 leading-6'
                + (option.selected ? ' font-semibold text-primary-700' : ' text-aeblack-900');
            item.textContent = option.textContent.trim();
            // mousedown لا click: قبل أن يفقد الحقل تركيزه فتُغلَق القائمة
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(option);
            });
            item.addEventListener('mousemove', () => active !== index && highlight(index));
            list.append(item);
        });

        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        active = -1;

        if (words.length > 0 && matches.length > 0) highlight(0);
    };

    const choose = (option) => {
        const changed = select.value !== option.value;
        select.value = option.value;
        sync();
        close();

        // على الهاتف تُطوى لوحة المفاتيح فيظهر النموذج كلّه
        if (window.matchMedia('(pointer: coarse)').matches) input.blur();

        if (changed) {
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    input.addEventListener('focus', () => {
        input.select();
        render();
    });

    input.addEventListener('click', () => list.hidden && render());
    input.addEventListener('input', render);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (list.hidden) render();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            highlight(Math.max(0, Math.min(matches.length - 1, active + step)));
        } else if (event.key === 'Enter' && !list.hidden) {
            // Enter يختار ولا يُرسل النموذج
            event.preventDefault();
            if (matches[active]) choose(matches[active]);
            else if (matches.length === 1) choose(matches[0]);
        } else if (event.key === 'Escape' && !list.hidden) {
            event.preventDefault();
            sync();
            close();
        }
    });

    input.addEventListener('blur', () => {
        // ما كُتب ولم يُختر لا يبقى: الحقل يعرض المختار فعلاً؛ والممسوح يُفرغ الاختيار
        if (input.value.trim() === '' && select.value !== '' && !select.required) {
            const empty = [...select.options].find((option) => option.value === '');
            if (empty) choose(empty);
        }

        sync();
        close();
    });

    // من يُعيد بناء الخيارات (المحافظة تغيّرت) أو يغيّر القيمة من خارج الحقل
    new MutationObserver(sync).observe(select, { childList: true, attributes: true, attributeFilter: ['disabled'] });
    select.addEventListener('change', sync);

    // «مطلوب» ولم يُختر: يُعلَّم الحقل الظاهر لا المخفيّ
    select.addEventListener('invalid', () => {
        input.setAttribute('aria-invalid', 'true');
        input.focus();
    });
    select.addEventListener('change', () => input.removeAttribute('aria-invalid'));
    select.addEventListener('focus', () => input.focus());

    sync();
}

export function initSearchableSelects(root = document) {
    for (const select of root.querySelectorAll('select[data-searchable]')) {
        if (!select.dataset.enhanced) {
            select.dataset.enhanced = '1';
            enhance(select);
        }
    }
}

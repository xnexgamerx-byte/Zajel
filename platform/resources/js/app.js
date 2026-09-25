/**
 * شاشة إنشاء الشحنة: ربط المناطق بالمحافظة + تسعير حيّ.
 *
 * بلا إطار عمل ثقيل — الصفحة تُرسَل من الخادم، وهذا كل ما تحتاجه.
 */

const form = document.getElementById('shipment-form');

// ربط المناطق بالمحافظة يخدم كل نموذج فيه عنوان: الشحنة والتاجر وغيرهما.
if (document.getElementById('cities-data')) {
    initCityLinking();
}

if (form) {
    initLiveQuote();
}

/** المناطق تتبع المحافظة المختارة. */
function initCityLinking() {
    const dataEl = document.getElementById('cities-data');
    const govSelect = document.getElementById('governorate_id');
    const citySelect = document.getElementById('city_id');

    if (!dataEl || !govSelect || !citySelect) return;

    const byGovernorate = JSON.parse(dataEl.textContent);
    const previous = citySelect.dataset.old;

    const refresh = () => {
        const cities = byGovernorate[govSelect.value] ?? [];

        citySelect.innerHTML = '';
        citySelect.append(new Option(cities.length ? 'اختر المنطقة' : 'اختر المحافظة أولاً', ''));

        for (const city of cities) {
            const option = new Option(city.name, city.id);
            option.selected = String(city.id) === String(previous);
            citySelect.append(option);
        }
    };

    govSelect.addEventListener('change', refresh);
    refresh();
}

/** يعرض الأجرة ومستحقّ التاجر قبل الحفظ. */
function initLiveQuote() {
    const url = form.dataset.quoteUrl;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const box = document.getElementById('quote-box');
    const warning = box?.querySelector('[data-quote-warning]');

    const fields = ['merchant_id', 'governorate_id', 'city_id', 'weight_grams',
                    'cod_amount', 'fees_paid_by', 'extra_fee', 'discount', 'delivery_fee'];

    const format = (n) => new Intl.NumberFormat('en-US').format(n) + ' د.ع';

    const render = (quote, manualFee) => {
        const deliveryFee = manualFee ?? quote.delivery_fee;
        const totalFees = Math.max(0, deliveryFee + quote.extra_fee + quote.cod_fee
            - Number(form.elements.discount?.value || 0));

        const paidByCustomer = form.elements.fees_paid_by?.value === 'customer';
        const cod = Number(form.elements.cod_amount?.value || 0);
        const merchantDue = paidByCustomer
            ? cod - quote.cod_fee + Number(form.elements.discount?.value || 0)
            : cod - totalFees;

        box.querySelector('[data-quote="delivery_fee"]').textContent = format(deliveryFee);
        box.querySelector('[data-quote="cod_fee"]').textContent = format(quote.cod_fee);
        box.querySelector('[data-quote="total_fees"]').textContent = format(totalFees);
        box.querySelector('[data-quote="merchant_due"]').textContent = format(merchantDue);

        warning?.classList.toggle('hidden', quote.matched || manualFee !== null);
    };

    let timer;

    const request = async () => {
        const merchantId = form.elements.merchant_id?.value;
        const governorateId = form.elements.governorate_id?.value;

        if (!merchantId || !governorateId) return;

        const manualRaw = form.elements.delivery_fee?.value;
        const manualFee = manualRaw === '' ? null : Number(manualRaw);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    merchant_id: Number(merchantId),
                    governorate_id: Number(governorateId),
                    city_id: form.elements.city_id?.value ? Number(form.elements.city_id.value) : null,
                    weight_grams: Number(form.elements.weight_grams?.value || 0),
                    cod_amount: Number(form.elements.cod_amount?.value || 0),
                    fees_paid_by: form.elements.fees_paid_by?.value || 'merchant',
                    extra_fee: Number(form.elements.extra_fee?.value || 0),
                    discount: Number(form.elements.discount?.value || 0),
                }),
            });

            if (!response.ok) return;

            render(await response.json(), manualFee);
        } catch {
            // فشل الشبكة لا يمنع الحفظ — التسعير يُعاد على الخادم عند الحفظ.
        }
    };

    for (const name of fields) {
        form.elements[name]?.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(request, 250);
        });
    }

    request();
}

/**
 * لوحة الإجراء في صفحة الشحنة: تُظهر فقط الحقول التي تخصّ الحالة المختارة.
 * data-when يحمل الحالات التي يظهر عندها الحقل.
 */
const statusForm = document.querySelector('[data-status-form]');

if (statusForm) {
    const select = statusForm.querySelector('#status');
    const conditionals = [...statusForm.querySelectorAll('[data-when]')];

    const refresh = () => {
        const value = select.value;

        for (const block of conditionals) {
            const shows = block.dataset.when.split(' ');
            block.hidden = !shows.includes(value);
        }
    };

    select.addEventListener('change', refresh);
    refresh();
}

/** شريط الإجراء الجماعي في قائمة الشحنات. */
const bulkBar = document.querySelector('[data-bulk-bar]');

if (bulkBar) {
    const rows = [...document.querySelectorAll('[data-row-select]')];
    const selectAll = document.querySelector('[data-select-all]');
    const counter = bulkBar.querySelector('[data-bulk-count]');

    const refresh = () => {
        const chosen = rows.filter((row) => row.checked).length;

        counter.textContent = chosen;
        bulkBar.hidden = chosen === 0;

        if (selectAll) {
            selectAll.checked = chosen > 0 && chosen === rows.length;
            selectAll.indeterminate = chosen > 0 && chosen < rows.length;
        }
    };

    for (const row of rows) row.addEventListener('change', refresh);

    selectAll?.addEventListener('change', () => {
        for (const row of rows) row.checked = selectAll.checked;
        refresh();
    });

    bulkBar.querySelector('[data-bulk-clear]')?.addEventListener('click', () => {
        for (const row of rows) row.checked = false;
        refresh();
    });

    refresh();
}

/** خانة اختيار تُظهر كتلة حقول (مثل حساب الدخول في نماذج التاجر والمندوب). */
for (const toggle of document.querySelectorAll('[data-toggle]')) {
    const target = document.getElementById(toggle.dataset.toggle);

    if (!target) continue;

    const refresh = () => { target.hidden = !toggle.checked; };

    toggle.addEventListener('change', refresh);
    refresh();
}

/**
 * شاشة المندوب: يلتقط الموقع عند فتح شحنة ويُرفقه بالتسجيل.
 *
 * إثبات أن المندوب كان عند الباب يحسم خلافاً بين تاجر ومندوب لا يحسمه
 * كلام. صامت عند الرفض: التسجيل أهم من الإحداثيات.
 */
const courierForm = document.querySelector('[data-courier-form]');

if (courierForm && navigator.geolocation) {
    const lat = courierForm.querySelector('[data-geo-lat]');
    const lng = courierForm.querySelector('[data-geo-lng]');

    navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
            lat.value = coords.latitude.toFixed(7);
            lng.value = coords.longitude.toFixed(7);
        },
        () => {},
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    );
}

/**
 * قوائم الشريط العلوي: واحدة مفتوحة في كل مرة.
 *
 * الزرّ يحمل حالته (aria-expanded) فيقرؤها قارئ الشاشة ويرسمها CSS. وعلى
 * الشاشة الواسعة تنسدل القائمة تحت عنوانها، فإن تجاوزت حافّة الشاشة
 * انفتحت نحو الداخل. وعلى الهاتف تنفتح في مكانها داخل الدرج.
 */
const menus = [...document.querySelectorAll('[data-menu]')].map((menu) => ({
    toggle: menu.querySelector('[data-menu-toggle]'),
    panel: menu.querySelector('[data-menu-panel]'),
}));

const closeMenus = (except = null) => {
    for (const menu of menus) {
        if (menu === except) continue;
        menu.panel.hidden = true;
        menu.panel.classList.remove('submenu-flip');
        menu.toggle.setAttribute('aria-expanded', 'false');
    }
};

for (const menu of menus) {
    menu.toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        const opening = menu.panel.hidden;

        closeMenus(menu);
        menu.panel.hidden = !opening;
        menu.toggle.setAttribute('aria-expanded', String(opening));

        if (opening) {
            const box = menu.panel.getBoundingClientRect();
            if (box.left < 8 || box.right > document.documentElement.clientWidth - 8) {
                menu.panel.classList.add('submenu-flip');
            }
        } else {
            menu.panel.classList.remove('submenu-flip');
        }
    });

    // نقرةٌ داخل القائمة المفتوحة لا تُغلقها؛ الرابط وحده ينقل
    menu.panel.addEventListener('click', (event) => event.stopPropagation());
}

if (menus.length) {
    document.addEventListener('click', () => closeMenus());

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        const open = menus.find((menu) => !menu.panel.hidden);
        closeMenus();
        open?.toggle.focus();
    });
}

/**
 * درج القوائم على الشاشات الصغيرة.
 *
 * زرّ القائمة يقلب data-open، والظهور يقرّره CSS (max-xl:hidden
 * max-xl:data-open:block). على الشاشة الواسعة الشريط ظاهرٌ دائماً فلا شيء
 * ينتظر السكربت ليظهر.
 */
const drawer = document.querySelector('[data-drawer]');

if (drawer) {
    const toggles = [...document.querySelectorAll('[data-drawer-toggle]')];

    const setOpen = (open) => {
        drawer.toggleAttribute('data-open', open);
        for (const toggle of toggles) toggle.setAttribute('aria-expanded', String(open));
    };

    for (const toggle of toggles) {
        toggle.addEventListener('click', () => setOpen(!drawer.hasAttribute('data-open')));
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });
}

/** زرّ البحث في أوّل الشريط: يضع المؤشّر في حقل البحث عن شحنة. */
for (const button of document.querySelectorAll('[data-focus]')) {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.focus);
        field?.focus();
        field?.select();
    });
}

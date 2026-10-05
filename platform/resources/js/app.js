/**
 * شاشة إنشاء الشحنة: ربط المناطق بالمحافظة + تسعير حيّ.
 *
 * بلا إطار عمل ثقيل — الصفحة تُرسَل من الخادم، وهذا كل ما تحتاجه.
 */

import { initSearchableSelects } from './searchable-select';
import { initScanTable } from './scan-table';
import { initScanBox } from './scan-box';
import { initQuickEntry } from './quick-entry';
import { initNumberInputs, numberValue } from './number-inputs';
import { initBulkBar, initDayPick } from './bulk-bar';
import { initDailyChart } from './daily-chart';
// السمات بدل المقابض المضمَّنة: data-confirm، data-print، data-dialog-open…
import './behaviors';

// المبالغ بفاصلٍ كل ثلاث خانات (60 000)، والهاتف ١١ رقماً — في كل نموذج
initNumberInputs();

const form = document.getElementById('shipment-form');

// ربط المناطق بالمحافظة يخدم كل نموذج فيه عنوان: الشحنة والتاجر وغيرهما.
if (document.getElementById('cities-data')) {
    initCityLinking();
}

// بعد ربط المناطق: الحقل يقرأ خياراتها الأولى
initSearchableSelects();

// «كل مراحل النقل» على الهاتف: المرحلة المختارة تُرى في صفّها المُمرَّر أفقياً.
// يُمرَّر الصفّ وحده — scrollIntoView كان يُنزل الصفحة كلّها إليها
const activeStage = document.querySelector('[data-stage-strip] [aria-current="page"]');
if (activeStage) {
    const row = activeStage.parentElement;
    const at = activeStage.getBoundingClientRect();
    const box = row.getBoundingClientRect();
    row.scrollLeft += at.left + at.width / 2 - (box.left + box.width / 2);
}

const dailyChart = document.getElementById('daily-chart');
if (dailyChart) initDailyChart(dailyChart);

const scanTable = document.querySelector('[data-scan-table]');
if (scanTable) initScanTable(scanTable);

// مسح الوصولات في شاشات الراجع: يُعلِّم الطرد في قائمتها أو يقول لماذا لا
const scanBox = document.querySelector('[data-scan-box]');
if (scanBox) initScanBox(scanBox);

const quickForm = document.querySelector('[data-quick-form]');
if (quickForm) initQuickEntry(quickForm);

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
        citySelect.append(new Option(
            citySelect.dataset.emptyLabel ?? (cities.length ? 'اختر المنطقة' : 'اختر المحافظة أولاً'), ''));

        for (const city of cities) {
            const option = new Option(city.name, city.id);
            option.selected = String(city.id) === String(previous);
            citySelect.append(option);
        }
    };

    govSelect.addEventListener('change', refresh);
    refresh();
}

/**
 * تنبيهٌ قبل الحفظ إن لم تطابق تسعيرةُ التاجر الوجهة.
 *
 * الأجرة تُحسب على الخادم عند الحفظ، والنموذج لا يعرضها؛ لكن إن لم تطابق قاعدةٌ
 * صارت صفراً بلا خبر. فيُسأل عنها والحقول تُملأ: يظهر التنبيه، وتنفتح «خيارات
 * إضافية» حيث تُكتب الأجرة يدوياً.
 */
function initLiveQuote() {
    const url = form.dataset.quoteUrl;
    const warning = form.querySelector('[data-quote-warning]');

    if (!url || !warning) return;

    const original = form.dataset.original ? JSON.parse(form.dataset.original) : null;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const extras = form.querySelector('[data-extras]');
    const fields = ['merchant_id', 'governorate_id', 'city_id', 'weight_grams', 'cod_amount', 'delivery_fee'];

    let timer;

    const request = async () => {
        const merchantId = form.elements.merchant_id?.value;
        const governorateId = form.elements.governorate_id?.value;

        if (!merchantId || !governorateId) {
            warning.hidden = true;
            return;
        }

        let manual = (form.elements.delivery_fee?.value ?? '').trim() !== '';

        // في التعديل: الأجرة الفارغة تبقى كما هي ما لم تتغيّر المحافظة أو المنطقة أو الوزن
        const rerouted = original && ['governorate_id', 'city_id', 'weight_grams']
            .some((name) => name === 'weight_grams'
                ? numberValue(form.elements[name]) !== Number(original[name] ?? 0)
                : String(form.elements[name]?.value ?? '') !== String(original[name] ?? ''));
        if (!manual && original && !rerouted) manual = true;

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
                    weight_grams: numberValue(form.elements.weight_grams),
                    cod_amount: numberValue(form.elements.cod_amount),
                }),
            });

            if (!response.ok) return;

            const quote = await response.json();
            const missing = !quote.matched && !manual;

            warning.hidden = !missing;
            if (missing && extras) extras.open = true;
        } catch {
            // فشل الشبكة لا يمنع الحفظ — التسعير يُعاد على الخادم عند الحفظ.
        }
    };

    for (const name of fields) {
        form.elements[name]?.addEventListener('change', () => {
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

/** شريط التحديث من القائمة: اختيارٌ، ثم حالةٌ جديدة بلا دخول كل شحنة (bulk-bar.js). */
const bulkBar = document.querySelector('[data-bulk-bar]');

if (bulkBar) {
    initBulkBar(bulkBar);
} else {
    // «الكلّ» في جدولٍ بلا شريط إجراء (تحت المراجعة): يحدّد صفوف نموذجه
    for (const all of document.querySelectorAll('[data-select-all]')) {
        const rows = [...(all.closest('form') ?? document).querySelectorAll('[data-row-select]')];
        all.addEventListener('change', () => rows.forEach((row) => { row.checked = all.checked; }));
    }
}

for (const input of document.querySelectorAll('[data-day-pick]')) initDayPick(input);

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
 * زرّ القائمة يقلب data-open، والظهور يقرّره CSS (max-lg:hidden
 * max-lg:data-open:block). على الشاشة الواسعة الشريط ظاهرٌ دائماً فلا شيء
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

/**
 * «الكلّ» في مجموعة مربّعات (قائمةٌ في شاشة المرتبة): يحدّد ما تحته أو يمحوه،
 * ويبدو نصف محدَّد حين يُحدَّد بعضها.
 */
for (const scope of document.querySelectorAll('[data-check-scope]')) {
    const all = scope.querySelector('[data-check-all]');
    const boxes = [...scope.querySelectorAll('input[type="checkbox"]:not([data-check-all])')];

    if (!all || boxes.length === 0) continue;

    const sync = () => {
        const on = boxes.filter((box) => box.checked).length;
        all.checked = on === boxes.length;
        all.indeterminate = on > 0 && on < boxes.length;
    };

    all.addEventListener('change', () => {
        for (const box of boxes) box.checked = all.checked;
        sync();
    });

    for (const box of boxes) box.addEventListener('change', sync);
    sync();
}

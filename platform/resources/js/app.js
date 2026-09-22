/**
 * شاشة إنشاء الشحنة: ربط المناطق بالمحافظة + تسعير حيّ.
 *
 * بلا إطار عمل ثقيل — الصفحة تُرسَل من الخادم، وهذا كل ما تحتاجه.
 */

const form = document.getElementById('shipment-form');

if (form) {
    initCityLinking();
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

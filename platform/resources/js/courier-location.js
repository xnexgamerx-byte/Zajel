/**
 * موقع هاتف المندوب (docs/plan/62): يُرسَل ما دامت شاشته مفتوحة — كلّ دقيقةٍ تقريباً، وفور عودته
 * إليها — حدّث شيئاً أو لم يحدّث، فـ«تتبّع المناديب» يُريه حيث هو لا حيث آخر شحنة.
 *
 * والشركة تُلزم به افتراضاً: الموقع مقفولٌ (رُفض الإذن، أو GPS مطفأ) فالشاشة مغطّاة بما يقول كيف
 * يُفتح، حتى يصل أوّل موقع. والمهلة وحدها (داخل بناية) لا تغطّيها: الهاتف يحاول ثانيةً.
 * والمتصفّح لا يرسل شيئاً والشاشة مغلقة — ذاك لتطبيق المندوب.
 */
export function initCourierLocation() {
    const source = document.querySelector('[data-courier-location]');

    if (!source) return;

    const url = source.dataset.url;
    const required = source.dataset.required === '1';
    const gate = document.querySelector('[data-location-gate]');
    const reason = gate?.querySelector('[data-location-reason]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const EVERY = 60_000;
    let sentAt = 0;
    let watch = null;

    const block = (text) => {
        if (!required || !gate) return;
        if (text && reason) reason.textContent = text;
        gate.hidden = false;
    };
    const open = () => {
        if (gate) gate.hidden = true;
    };

    const send = ({ coords }, force = false) => {
        open();
        if (!force && Date.now() - sentAt < EVERY) return;
        sentAt = Date.now();
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ lat: coords.latitude, lng: coords.longitude, accuracy: Math.round(coords.accuracy) }),
            keepalive: true,
            credentials: 'same-origin',
        }).catch(() => {});
    };

    const failed = (error) => {
        if (error.code === 1) {
            block('رفضتَ إذن الموقع لهذا الموقع. اسمح به من إعدادات المتصفّح، ثم أعد المحاولة.');
        } else if (error.code === 2) {
            block('الموقع (GPS) مطفأ أو لا يُلتقط. شغّله من أعلى شاشة الهاتف، ثم أعد المحاولة.');
        }
    };

    const options = { enableHighAccuracy: true, timeout: 20_000, maximumAge: 30_000 };

    const start = () => {
        if (!('geolocation' in navigator) || !window.isSecureContext) {
            block('هذا المتصفّح لا يعطي الموقع على هذا الاتصال. افتح الشاشة من رابط الشركة الآمن (https) بمتصفّح Chrome.');

            return;
        }
        if (watch !== null) navigator.geolocation.clearWatch(watch);
        watch = navigator.geolocation.watchPosition((p) => send(p), failed, options);
        navigator.geolocation.getCurrentPosition((p) => send(p, true), failed, options);
    };

    // إذنٌ رُفض سلفاً يُعرف بلا انتظار
    navigator.permissions?.query({ name: 'geolocation' }).then((status) => {
        if (status.state === 'denied') failed({ code: 1 });
        status.onchange = () => (status.state === 'denied' ? failed({ code: 1 }) : start());
    }).catch(() => {});

    gate?.querySelector('[data-location-retry]')?.addEventListener('click', start);

    // العودة إلى الشاشة: موقعٌ فوريّ، فقد يكون غاب طويلاً
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            navigator.geolocation?.getCurrentPosition((p) => send(p, true), failed, options);
        }
    });

    // والشاشة مفتوحةٌ ساكنة: watchPosition لا يُبلغ إلّا بالحركة، فيُطلب كلّ دقيقة
    setInterval(() => {
        if (document.visibilityState === 'visible') {
            navigator.geolocation?.getCurrentPosition((p) => send(p), () => {}, options);
        }
    }, EVERY);

    start();
}

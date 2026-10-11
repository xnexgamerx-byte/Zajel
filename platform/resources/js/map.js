/**
 * الخرائط (docs/plan/61): Leaflet وبلاطات OpenStreetMap، بلا مفتاح. العنصر [data-map] يحمل
 * المركز والتقريب والنقاط JSON في سماته (لا سكربت داخل الصفحة: CSP).
 *  - النقطة الدقيقة دائرةٌ ممتلئة بلون الشركة، والتقريبيّة حلقةٌ فارغة، ومن سكت طويلاً كهرمانيّة.
 *  - كلّ نقطةٍ تحمل اسمها ظاهراً، وسطراً عنها عند الضغط.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#d6453d';

for (const el of document.querySelectorAll('[data-map]')) {
    const center = JSON.parse(el.dataset.center || '[33.3152,44.3661]');
    const points = JSON.parse(el.dataset.points || '[]');
    // Leaflet يحسب المواضع يساراً إلى يمين: في صفحةٍ يمينية تنزاح التسميات عن نقاطها
    el.setAttribute('dir', 'ltr');
    const map = L.map(el, { scrollWheelZoom: false }).setView(center, Number(el.dataset.zoom) || 11);

    // خوادم OpenStreetMap تحجب البلاطات بلا Referer («Access blocked»)، وسياسة الموقع same-origin لا
    // ترسله لغيرها: البلاطات وحدها ترسل عنوان الموقع (النطاق فقط، بلا مسار الصفحة ولا رموزها)
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
        referrerPolicy: 'strict-origin-when-cross-origin',
    }).addTo(map);

    const layer = L.layerGroup().addTo(map);
    const draw = (list) => {
        layer.clearLayers();
        list.forEach((point, i) => {
            // التقريبيّون عند مركز المحافظة نفسه: يُفرَّقون قليلاً حتى لا يغطّي أحدهم الآخر
            const spread = point.exact ? [0, 0] : [Math.sin(i * 2.4) * 0.012, Math.cos(i * 2.4) * 0.012];
            const colour = point.stale ? '#d97706' : brand;
            const marker = L.circleMarker([point.lat + spread[0], point.lng + spread[1]], {
                radius: point.exact ? 9 : 8,
                color: colour,
                weight: 3,
                fillColor: colour,
                fillOpacity: point.exact ? 0.9 : 0.1,
            }).addTo(layer);

            if (point.name) {
                marker.bindTooltip(point.name, { permanent: true, direction: 'top', offset: [0, -8], className: 'map-label' });
            }
            if (point.note) {
                marker.bindPopup(`<b>${escape(point.name || '')}</b><br>${escape(point.note)}`);
            }
        });
    };
    draw(points);

    // المواقع تتجدّد وحدها (docs/plan/62): الصفحة نفسها تُقرأ كلّ دقيقة وتُعاد النقاط، بلا تحريك الخريطة
    const every = Number(el.dataset.refresh || 0);
    if (every > 0) {
        setInterval(async () => {
            if (document.visibilityState !== 'visible' || map._popup?.isOpen()) return;
            try {
                const html = await (await fetch(window.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })).text();
                const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-map]');
                if (fresh?.dataset.points) draw(JSON.parse(fresh.dataset.points));
            } catch {
                // الشبكة انقطعت: تبقى النقاط كما هي حتى المرّة القادمة
            }
        }, every * 1000);
    }

    if (el.dataset.fit !== undefined && points.length) {
        map.fitBounds(points.map((p) => [p.lat, p.lng]), { padding: [40, 40], maxZoom: 15 });
    }
}

function escape(text) {
    const div = document.createElement('div');
    div.textContent = text;

    return div.innerHTML;
}

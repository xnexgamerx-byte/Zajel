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

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    points.forEach((point, i) => {
        // التقريبيّون عند مركز المحافظة نفسه: يُفرَّقون قليلاً حتى لا يغطّي أحدهم الآخر
        const spread = point.exact ? [0, 0] : [Math.sin(i * 2.4) * 0.012, Math.cos(i * 2.4) * 0.012];
        const colour = point.stale ? '#d97706' : brand;
        const marker = L.circleMarker([point.lat + spread[0], point.lng + spread[1]], {
            radius: point.exact ? 9 : 8,
            color: colour,
            weight: 3,
            fillColor: colour,
            fillOpacity: point.exact ? 0.9 : 0.1,
        }).addTo(map);

        if (point.name) {
            marker.bindTooltip(point.name, { permanent: true, direction: 'top', offset: [0, -8], className: 'map-label' });
        }
        if (point.note) {
            marker.bindPopup(`<b>${escape(point.name || '')}</b><br>${escape(point.note)}`);
        }
    });

    if (el.dataset.fit !== undefined && points.length) {
        map.fitBounds(points.map((p) => [p.lat, p.lng]), { padding: [40, 40], maxZoom: 15 });
    }
}

function escape(text) {
    const div = document.createElement('div');
    div.textContent = text;

    return div.innerHTML;
}

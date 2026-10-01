/**
 * رسم «الحركة اليومية»: مؤشّرٌ يتبع الفأرة على الأيام، وبطاقةٌ بأرقام اليوم تحته.
 * البيانات من <script type="application/json" id="daily-data"> — لا سكربت مضمَّن.
 */
export function initDailyChart(chart) {
    const data = JSON.parse(document.getElementById('daily-data')?.textContent || '{}');
    const days = data.days ?? [];
    const series = data.series ?? [];
    const hit = document.getElementById('daily-hit');
    const cross = document.getElementById('daily-crosshair');
    const tip = document.getElementById('daily-tip');

    if (!hit || days.length === 0) return;

    const show = (event) => {
        const box = chart.getBoundingClientRect();
        const ratio = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
        // المحور مقلوب: الصفحة من اليمين لليسار والرسم من اليسار لليمين
        const index = Math.round((1 - ratio) * (days.length - 1));
        const day = days[index];
        if (!day) return;

        // الصيغة نفسها التي رُسم بها الخطّ، وإلّا سار المؤشّر في اتجاه والخطّ في آخر
        const x = 930 - (index / Math.max(1, days.length - 1)) * 910;
        cross.setAttribute('x1', x);
        cross.setAttribute('x2', x);
        cross.style.display = '';

        tip.replaceChildren();
        const head = document.createElement('div');
        head.className = 'num mb-1 font-semibold text-ink-900';
        head.textContent = day.day;
        tip.appendChild(head);

        for (const s of series) {
            const row = document.createElement('div');
            row.className = 'flex items-center gap-2';
            const key = document.createElement('span');
            key.style.cssText = `display:inline-block;width:14px;height:2px;border-radius:2px;background:${s.color}`;
            const value = document.createElement('span');
            value.className = 'num font-semibold text-ink-900';
            value.textContent = day[s.key];
            const name = document.createElement('span');
            name.className = 'text-ink-500';
            name.textContent = s.label;
            row.append(key, value, name);
            tip.appendChild(row);
        }

        tip.hidden = false;
        const left = Math.min(box.width - tip.offsetWidth - 8, Math.max(8, event.clientX - box.left + 12));
        tip.style.left = `${left}px`;
        tip.style.top = '8px';
    };

    hit.addEventListener('pointermove', show);
    hit.addEventListener('pointerleave', () => {
        cross.style.display = 'none';
        tip.hidden = true;
    });
}

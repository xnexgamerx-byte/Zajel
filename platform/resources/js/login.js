/*
 * شبكة النقاط خلف صفحات الدخول: نقاطٌ تسبح ببطء وتتّصل بخطوطٍ خافتة، وتبتعد
 * عن المؤشّر وتتوهّج قربه. من ملف التصميم كما هو (zajel-login-interactive.html).
 *
 * تتوقّف حين تُخفى الصفحة، وتُرسم مرّةً ساكنةً لمن طلب في جهازه حركةً أقلّ.
 */
(() => {
    const canvas = document.getElementById('network');

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');
    const root = document.documentElement;
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

    let w = 0, h = 0, dpr = 1, pts = [], mx = -1000, my = -1000, raf = 0;

    function resize() {
        dpr = Math.min(devicePixelRatio || 1, 1.5);
        w = innerWidth;
        h = innerHeight;
        canvas.width = w * dpr;
        canvas.height = h * dpr;
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        // نقاطٌ بقدر الشاشة: ٢٨ على الهاتف، و٧٢ على شاشةٍ كبيرة
        const count = Math.max(28, Math.min(72, Math.round(w * h / 14500)));

        pts = Array.from({ length: count }, () => ({
            x: Math.random() * w, y: Math.random() * h,
            ox: Math.random() * w, oy: Math.random() * h,
            vx: 0, vy: 0,
            r: Math.random() * 1.35 + .65,
            phase: Math.random() * 6.28,
        }));
    }

    function pointer(e) {
        mx = e.clientX;
        my = e.clientY;
        root.style.setProperty('--mx', mx + 'px');
        root.style.setProperty('--my', my + 'px');
    }

    addEventListener('pointermove', pointer, { passive: true });
    addEventListener('pointerleave', () => { mx = -1000; my = -1000; }, { passive: true });
    addEventListener('resize', resize, { passive: true });
    resize();

    function frame(t) {
        ctx.clearRect(0, 0, w, h);

        const reach = reduce ? 0 : 145;

        for (let i = 0; i < pts.length; i++) {
            const p = pts[i];

            p.ox += Math.sin(t * .00022 + p.phase) * .12;
            p.oy += Math.cos(t * .00018 + p.phase) * .12;

            const dx = p.x - mx, dy = p.y - my, dist = Math.hypot(dx, dy) || 1;

            if (dist < reach) {
                const force = (reach - dist) / reach * .32;
                p.vx += (dx / dist) * force;
                p.vy += (dy / dist) * force;
            }

            p.vx += (p.ox - p.x) * .0015;
            p.vy += (p.oy - p.y) * .0015;
            p.vx *= .94;
            p.vy *= .94;
            p.x += p.vx;
            p.y += p.vy;

            for (let j = i + 1; j < pts.length; j++) {
                const q = pts[j], x = p.x - q.x, y = p.y - q.y, d = Math.hypot(x, y);

                if (d < 125) {
                    ctx.strokeStyle = `rgba(163,177,211,${(1 - d / 125) * .12})`;
                    ctx.lineWidth = .7;
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                    ctx.lineTo(q.x, q.y);
                    ctx.stroke();
                }
            }

            const md = Math.hypot(p.x - mx, p.y - my), glow = md < 155 && !reduce;

            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r + (glow ? 1.1 : 0), 0, Math.PI * 2);
            ctx.fillStyle = glow ? `rgba(247,91,74,${.4 + (1 - md / 155) * .48})` : 'rgba(154,170,205,.4)';
            ctx.fill();
        }

        if (!reduce) {
            raf = requestAnimationFrame(frame);
        }
    }

    if (reduce) {
        frame(0);
    } else {
        raf = requestAnimationFrame(frame);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            cancelAnimationFrame(raf);
        } else if (!reduce) {
            raf = requestAnimationFrame(frame);
        }
    });
})();

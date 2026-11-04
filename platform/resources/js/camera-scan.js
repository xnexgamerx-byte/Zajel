import { beep } from './beep';

/**
 * المسح بكاميرا الهاتف (docs/plan/119): المندوب في الشارع بلا قارئ باركود، فكان يكتب رقم الوصل بيده.
 * زرّ «امسح بالكاميرا» بجانب كلّ خانة مسح يفتح الكاميرا الخلفية، فيقرأ باركود الوصل أو رمز QR الذي عليه
 * ويكتب ما قرأه في الخانة كما يكتبه قارئ الباركود — والخادم يقرؤه بـScanCode كما يقرأ القارئ.
 *
 * data-camera-scan="#خانة" على الزرّ، و data-camera-mode:
 * - fill (الافتراض): يكتب ما قرأه ويُغلق.
 * - submit: يكتب ويُرسل النموذج (البحث: تُفتح الشحنة).
 * - lines: يضيف سطراً لكلّ طردٍ ويبقى مفتوحاً حتى «تمّ» (استلام طرود التاجر).
 * - press: يكتب ويضغط الزرّ data-camera-press ويبقى مفتوحاً (مسح الوصولات في القوائم)، ورسالة الصفحة
 *   عن الممسوح (data-camera-status) تُعرض فوق الكاميرا: أقُبل أم لماذا رُفض.
 *
 * يقرأ بـBarcodeDetector حيث يوجد (Chrome على أندرويد)، وإلّا بـZXing يُحمَّل عند أوّل فتحٍ وحده.
 * والكاميرا تُطفأ حين يُغلق أو تُغادَر الصفحة.
 */

const FORMATS = ['qr_code', 'code_128'];

/** ما يقرأ صورةً من الفيديو: نصّ الرمز أو null */
let decoderPromise;

function decoder() {
    decoderPromise ??= (async () => {
        if ('BarcodeDetector' in window) {
            try {
                const supported = await window.BarcodeDetector.getSupportedFormats();
                const formats = FORMATS.filter((f) => supported.includes(f));
                if (formats.length === FORMATS.length) {
                    const detector = new window.BarcodeDetector({ formats });

                    return async (video) => (await detector.detect(video))[0]?.rawValue ?? null;
                }
            } catch {
                // ننزل إلى ZXing
            }
        }

        const zxing = await import('@zxing/library');
        const hints = new Map([
            [zxing.DecodeHintType.POSSIBLE_FORMATS, [zxing.BarcodeFormat.QR_CODE, zxing.BarcodeFormat.CODE_128]],
            [zxing.DecodeHintType.TRY_HARDER, true],
        ]);
        const reader = new zxing.MultiFormatReader();
        reader.setHints(hints);
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { willReadFrequently: true });

        return async (video) => {
            const scale = Math.min(1, 1280 / (video.videoWidth || 1280));
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            if (!canvas.width || !canvas.height) return null;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            try {
                const source = new zxing.HTMLCanvasElementLuminanceSource(canvas);
                return reader.decodeWithState(new zxing.BinaryBitmap(new zxing.HybridBinarizer(source))).getText();
            } catch {
                return null; // لا رمز في هذه الصورة
            } finally {
                reader.reset();
            }
        };
    })();

    return decoderPromise;
}

/** يستطيع المتصفّح فتح الكاميرا: HTTPS (أو localhost) وgetUserMedia */
export function cameraAvailable() {
    return window.isSecureContext && !!navigator.mediaDevices?.getUserMedia;
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
}

/** نافذة الكاميرا: تُبنى عند الفتح وتُزال عند الإغلاق */
function overlay(continuous) {
    const root = el('div', 'fixed inset-0 z-50 flex flex-col bg-black text-white');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'المسح بالكاميرا');
    root.dataset.cameraOverlay = '';

    const stage = el('div', 'relative min-h-0 flex-1 overflow-hidden');
    const video = el('video', 'absolute inset-0 size-full object-cover');
    video.setAttribute('playsinline', '');
    video.setAttribute('muted', '');
    video.muted = true;
    const frame = el('div', 'pointer-events-none absolute inset-x-8 top-1/2 h-48 -translate-y-1/2 rounded-2xl border-4 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]');
    stage.append(video, frame);

    const bar = el('div', 'space-y-3 p-4 pb-[max(1rem,env(safe-area-inset-bottom))]');
    const message = el('p', 'min-h-6 text-center text-base font-semibold', 'وجّه الكاميرا إلى باركود الوصل أو رمز QR');
    message.setAttribute('role', 'status');
    message.setAttribute('aria-live', 'polite');
    const count = el('p', 'hidden text-center text-sm text-white/80');
    const close = el('button', 'w-full rounded-xl bg-white px-4 py-3.5 text-base font-bold text-black active:bg-white/80',
        continuous ? 'تمّ' : 'إغلاق');
    close.type = 'button';
    close.dataset.cameraClose = '';
    bar.append(message, count, close);

    root.append(stage, bar);
    document.body.append(root);

    return { root, video, message, count, close };
}

function open(button) {
    const target = document.querySelector(button.dataset.cameraScan);
    if (!target) return;

    const mode = button.dataset.cameraMode || 'fill';
    const continuous = mode === 'lines' || mode === 'press';
    const ui = overlay(continuous);
    const seen = new Set(mode === 'lines' ? target.value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean) : []);

    let stream = null;
    let stopped = false;
    let pausedUntil = 0;
    let timer = null;

    const stop = () => {
        if (stopped) return;
        stopped = true;
        clearTimeout(timer);
        watch?.disconnect();
        stream?.getTracks().forEach((track) => track.stop());
        ui.root.remove();
        window.removeEventListener('pagehide', stop);
        document.removeEventListener('keydown', onKey);
        button.focus({ preventScroll: true });
    };
    const onKey = (event) => { if (event.key === 'Escape') stop(); };

    // رسالة الصفحة عن الممسوح، فوق الكاميرا التي تغطّيها
    const status = mode === 'press' && button.dataset.cameraStatus ? document.querySelector(button.dataset.cameraStatus) : null;
    const watch = status ? new MutationObserver(() => { if (status.textContent.trim()) say(status.textContent.trim()); }) : null;
    watch?.observe(status, { childList: true, characterData: true, subtree: true });

    ui.close.addEventListener('click', stop);
    window.addEventListener('pagehide', stop);
    document.addEventListener('keydown', onKey);

    const say = (text) => { ui.message.textContent = text; };

    const accept = (text) => {
        if (mode === 'lines') {
            if (seen.has(text)) {
                say('مُسح هذا الطرد سلفاً');
                beep(false);
                pausedUntil = Date.now() + 1200;
                return;
            }
            seen.add(text);
            target.value = [...target.value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean), text].join('\n');
            target.dispatchEvent(new Event('input', { bubbles: true }));
            beep(true);
            say('مُسح ✓ — امسح الطرد التالي');
            ui.count.textContent = `ممسوح ${seen.size}`;
            ui.count.classList.remove('hidden');
            pausedUntil = Date.now() + 1200;
            return;
        }

        target.value = text;
        target.dispatchEvent(new Event('input', { bubbles: true }));

        if (mode === 'press') {
            say('مُسح ✓ — امسح التالي');
            document.querySelector(button.dataset.cameraPress)?.click();
            pausedUntil = Date.now() + 1500;
            return;
        }

        beep(true);
        stop();
        if (mode === 'submit') target.form?.requestSubmit();
    };

    const tick = async (read) => {
        if (stopped) return;
        if (Date.now() >= pausedUntil && ui.video.readyState >= 2) {
            try {
                const text = (await read(ui.video))?.trim();
                if (text && !stopped) accept(text);
            } catch {
                // صورةٌ لم تُقرأ: التي بعدها
            }
        }
        timer = setTimeout(() => tick(read), 150);
    };

    (async () => {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
            if (stopped) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }
            ui.video.srcObject = stream;
            await ui.video.play();
            tick(await decoder());
        } catch (error) {
            say(error?.name === 'NotAllowedError' || error?.name === 'SecurityError'
                ? 'لم يُسمح بالكاميرا: اسمح للموقع بها من إعدادات المتصفّح ثم أعد المحاولة، أو اكتب الرقم.'
                : 'تعذّر فتح الكاميرا. اكتب رقم الوصل بيدك.');
            beep(false);
        }
    })();
}

export function initCameraScan() {
    const buttons = document.querySelectorAll('[data-camera-scan]');
    if (!buttons.length) return;

    if (!cameraAvailable()) {
        // بلا كاميرا (أو بلا HTTPS) لا زرّ يُضغط فلا يفعل شيئاً: الخانة تكفي
        buttons.forEach((button) => button.remove());
        return;
    }

    buttons.forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => open(button));
    });
}

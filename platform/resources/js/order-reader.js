/**
 * «اقرأ الطلب من صورة أو رسالة» (components/order-reader، docs/plan/34).
 *
 * لقطة شاشةٍ تُختار أو تُلصق (Ctrl+V) أو تُسحب إلى البطاقة، أو نصّ رسالةٍ يُلصق: يُرسل إلى
 * الخادم فيُقرأ هناك، ويعود بما يملأ النموذج. المحافظة أوّلاً لتُبنى قائمة مناطقها ثم المنطقة،
 * والمبلغ يُجمَّع كما يُكتب. وما مُلئ يُضاء لحظةً ليُراجَع، وما لم يُعثر عليه يُقال.
 *
 * وبالصوت (docs/plan/40): «تكلّم» يُسمِع المتصفّحَ الطلبَ بالعربية العراقية، والكلام يُكتب في
 * الخانة وهو يُقال، ثم يُرسل نصّاً «مسموعاً» (spoken) فيُعاد أسطراً وأرقاماً على الخادم.
 * ومتصفّحٌ لا يسمع يُدلّ على مايك لوحة المفاتيح داخل الخانة نفسها.
 */

/** دقيقتان على الأكثر: لا يبقى المايك مفتوحاً إن نُسي */
const LISTEN_LIMIT = 120_000;

const HEARING_ERRORS = {
    'not-allowed': 'اسمح للمتصفّح باستعمال المايك (من القفل بجانب العنوان) ثم اضغط «تكلّم».',
    'service-not-allowed': 'متصفّحك لا يسمح بالسماع هنا — اضغط مايك لوحة المفاتيح داخل الخانة وتكلّم.',
    'audio-capture': 'لا مايك يعمل في هذا الجهاز.',
    network: 'السماع يحتاج إنترنت — تأكّد من الاتصال ثم أعد.',
    'language-not-supported': 'متصفّحك لا يسمع العربية — اضغط مايك لوحة المفاتيح داخل الخانة وتكلّم.',
};

const NAMES = {
    recipient_name: 'الاسم',
    recipient_phone: 'الهاتف',
    recipient_phone_alt: 'الهاتف البديل',
    governorate_id: 'المحافظة',
    city_id: 'المنطقة',
    landmark: 'النقطة الدالّة',
    cod_amount: 'المبلغ',
    pieces_count: 'العدد',
    notes: 'الملاحظة',
};

const TONES = {
    info: 'bg-info-50 text-info-700',
    ok: 'bg-ok-50 text-ok-700',
    warn: 'bg-warn-50 text-warn-700',
    bad: 'bg-bad-50 text-bad-700',
};

/** يملأ الحقول بما قُرئ: المحافظة قبل المنطقة، وكلّ حقلٍ يُعلَم بتغيّره كما لو كُتب */
function fill(form, fields) {
    const filled = [];
    const order = ['governorate_id', 'city_id', ...Object.keys(fields).filter((n) => n !== 'governorate_id' && n !== 'city_id')];

    for (const name of order) {
        if (!(name in fields)) continue;

        const field = form.elements.namedItem(name);
        if (!(field instanceof HTMLElement) || field.disabled || field.type === 'hidden') continue;

        const value = String(fields[name]);

        if (field instanceof HTMLSelectElement && ![...field.options].some((option) => option.value === value)) continue;

        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));

        // حقلٌ في «خيارات إضافية» المطويّة يُفتح ليُرى
        const extras = field.closest('details');
        if (extras) extras.open = true;

        filled.push(name);

        const box = field.closest('div');
        const glow = ['rounded-xl', 'outline', 'outline-2', 'outline-offset-4', 'outline-info-200'];
        box?.classList.add(...glow);
        window.setTimeout(() => box?.classList.remove(...glow), 3500);
    }

    return filled;
}

export function initOrderReader(section) {
    const form = document.getElementById(section.dataset.form);
    if (!form) return;

    const status = section.querySelector('[data-order-status]');
    const lines = section.querySelector('[data-order-lines]');
    const linesText = section.querySelector('[data-order-lines-text]');
    const image = section.querySelector('[data-order-image]');
    const paste = section.querySelector('[data-order-paste]');
    const pasteOpen = section.querySelector('[data-order-paste-open]');
    const text = section.querySelector('[data-order-text]');
    const read = section.querySelector('[data-order-read]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const show = (message, tone) => {
        status.hidden = false;
        status.className = `mt-3 rounded-2xl px-4 py-3 text-sm ${TONES[tone]}`;
        status.textContent = message;
    };

    const report = (data) => {
        const filled = fill(form, data.fields ?? {});
        const found = data.found ?? {};
        const missing = data.missing ?? [];
        const parts = [];

        if (filled.length === 0) {
            show('لم يُعثر على شيءٍ من الطلب هنا — الصق الرسالة نصّاً، أو اكتب الحقول.', 'bad');
        } else {
            // بترتيب النموذج لا بترتيب الملء
            const read = Object.keys(NAMES).filter((name) => filled.includes(name));
            const by = data.engine === 'ai' ? 'قرأ الذكاء الاصطناعي' : 'قُرئ';
            parts.push(`${by}: ${read.map((name) => (found[name] ? `${NAMES[name]} ${found[name]}` : NAMES[name])).join('، ')}.`);
            if (missing.length) parts.push(`لم يُعثر على: ${missing.join('، ')} — اكتبها.`);
            parts.push(...(data.warnings ?? []));

            const merchant = form.elements.namedItem('merchant_id');
            if (merchant instanceof HTMLSelectElement && merchant.value === '') parts.push('واختر التاجر.');

            parts.push('راجع الحقول قبل الحفظ.');
            show(parts.join(' '), missing.length ? 'warn' : 'ok');
        }

        linesText.textContent = (data.lines ?? []).join('\n');
        lines.hidden = !(data.lines ?? []).length;
    };

    const send = async (body, busy, url = section.dataset.url, onRead = null) => {
        show(busy, 'info');
        section.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                credentials: 'same-origin',
                body,
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                onRead?.(data);
                report(data);
            } else if (response.status === 429) {
                show('قراءاتٌ كثيرة في دقيقة — انتظر قليلاً ثم أعد.', 'bad');
            } else if (response.status === 413) {
                show('الصورة كبيرة جداً — أرسل لقطة الشاشة نفسها.', 'bad');
            } else if (response.status === 419) {
                show('انتهت الجلسة — حدّث الصفحة ثم أعد.', 'bad');
            } else {
                // أوّل خطأ تحقّقٍ بنصّه، لا ملخّص Laravel بذيله الإنجليزي
                const first = Object.values(data.errors ?? {})[0]?.[0];
                show(first ?? data.message ?? 'تعذّرت القراءة — حاول مجدداً.', 'bad');
            }
        } catch {
            show('تعذّر الاتصال بالخادم — حاول مجدداً.', 'bad');
        } finally {
            section.removeAttribute('aria-busy');
        }
    };

    /*
    | صورة كاميرا الهاتف (٥–١٥ ميغابايت) تُصغَّر هنا قبل رفعها: أسرع على إنترنت الهاتف، ولا تتجاوز
    | حدّ الخادم (٦ ميغابايت). ٢٤٠٠ نقطة على الضلع الأطول تُبقي الأرقام مقروءة. ولقطة الشاشة
    | الصغيرة تُرسل كما هي.
    */
    const shrink = async (file) => {
        if (file.size <= 2_000_000 || !window.createImageBitmap) return file;

        try {
            const bitmap = await createImageBitmap(file);
            const scale = Math.min(1, 2400 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close?.();
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));

            return blob && blob.size < file.size ? new File([blob], 'order.jpg', { type: 'image/jpeg' }) : file;
        } catch {
            return file;
        }
    };

    const upload = async (file) => {
        if (!file) return;
        show('تُجهَّز الصورة…', 'info');
        const body = new FormData();
        body.append('image', await shrink(file));
        send(body, 'تُقرأ الصورة… ثوانٍ قليلة.');
    };

    image?.addEventListener('change', () => {
        upload(image.files[0]);
        image.value = '';
    });

    pasteOpen.addEventListener('click', () => {
        section.querySelector('[data-order-talk-hint]')?.setAttribute('hidden', '');
        paste.hidden = !paste.hidden;
        pasteOpen.setAttribute('aria-expanded', String(!paste.hidden));
        if (!paste.hidden) text.focus();
    });

    // النصّ في الخانة كلامٌ مسموع لا رسالةٌ ملصوقة: يُقرأ بقواعد الكلام
    let spoken = false;

    read.addEventListener('click', () => {
        if (text.value.trim() === '') {
            text.focus();
            return;
        }
        const body = new FormData();
        body.append('text', text.value);
        if (spoken) body.append('spoken', '1');
        send(body, spoken ? 'يُقرأ كلامك…' : 'تُقرأ الرسالة…');
    });

    text.addEventListener('paste', () => {
        spoken = false;
    });

    const talk = section.querySelector('[data-order-talk]');
    const talkLabel = section.querySelector('[data-order-talk-label]');
    const hint = section.querySelector('[data-order-talk-hint]');
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let listening = false;
    let stopAsked = false;
    let failed = false;
    let heard = '';
    let startedAt = 0;

    const setListening = (on) => {
        listening = on;
        talk.setAttribute('aria-pressed', String(on));
        talk.classList.toggle('btn-listening', on);
        talkLabel.textContent = on ? 'أوقف' : 'تكلّم';
    };

    const finish = () => {
        setListening(false);
        if (failed) return;
        if (text.value.trim() === '') {
            show('لم يُسمع كلام — اضغط «تكلّم» وأعد.', 'bad');
            return;
        }
        read.click();
    };

    // متصفّح الهاتف يكفّ عن السماع عند أوّل سكتة: يُعاد حتى يضغط التاجر «أوقف»
    const listen = () => {
        recognition = new Recognition();
        recognition.lang = 'ar-IQ';
        recognition.continuous = true;
        recognition.interimResults = true;

        recognition.onresult = (event) => {
            let interim = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                const result = event.results[i];
                if (result.isFinal) heard = `${heard} ${result[0].transcript}`.trim();
                else interim += result[0].transcript;
            }
            text.value = `${heard} ${interim}`.trim();
        };

        recognition.onerror = (event) => {
            if (event.error === 'no-speech' || event.error === 'aborted') return;
            failed = true;
            show(HEARING_ERRORS[event.error] ?? 'تعذّر السماع — اضغط «تكلّم» وأعد.', 'bad');
        };

        recognition.onend = () => {
            if (!stopAsked && !failed && Date.now() - startedAt < LISTEN_LIMIT) {
                try {
                    listen();
                    return;
                } catch {
                    // لا يُعاد: يُقرأ ما سُمع
                }
            }
            finish();
        };

        recognition.start();
    };

    /*
    | التسجيل حتى «أوقف» (docs/plan/40): حين يكون للخادم محرّك سماع، يُفتح المايك مرّةً ويبقى مفتوحاً —
    | لا ينقطع عند السكتة ولا يُصدر صوتاً كسماع المتصفّح في أندرويد — ثم يُرسَل التسجيل كلّه فيُقرأ.
    | ومؤشّر الصوت على الزرّ يقول إنه يسمع.
    */
    const listenUrl = section.dataset.listenUrl;
    const canRecord = Boolean(listenUrl && navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
    let recorder = null;
    let starting = false;
    let stream = null;
    let chunks = [];
    let clock = null;
    let level = null;

    const recordingType = () => ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']
        .find((type) => window.MediaRecorder.isTypeSupported?.(type)) ?? '';

    const elapsed = () => {
        const seconds = Math.floor((Date.now() - startedAt) / 1000);
        return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    };

    const stopRecording = () => {
        window.clearInterval(clock);
        level?.close?.();
        level = null;
        talk.style.removeProperty('--level');
        if (recorder?.state === 'recording') recorder.stop();
        stream?.getTracks().forEach((track) => track.stop());
        setListening(false);
    };

    // مستوى الصوت يكبّر حلقة الزرّ: يرى التاجر أنّ المايك يسمعه
    const meter = (source) => {
        const Context = window.AudioContext || window.webkitAudioContext;
        if (!Context) return;
        level = new Context();
        // سفاري يبدأ السياق موقوفاً إن لم يُنشأ في الضغطة نفسها: يُستأنف فيتحرّك المؤشّر
        level.resume?.().catch(() => {});
        const analyser = level.createAnalyser();
        analyser.fftSize = 512;
        level.createMediaStreamSource(source).connect(analyser);
        const samples = new Uint8Array(analyser.fftSize);
        const draw = () => {
            if (!level) return;
            analyser.getByteTimeDomainData(samples);
            const peak = samples.reduce((max, sample) => Math.max(max, Math.abs(sample - 128)), 0) / 128;
            talk.style.setProperty('--level', Math.min(1, peak * 2.5).toFixed(2));
            window.requestAnimationFrame(draw);
        };
        draw();
    };

    const sendRecording = () => {
        const type = recorder?.mimeType || chunks[0]?.type || 'audio/webm';
        const blob = new Blob(chunks, { type });
        chunks = [];
        if (blob.size < 2000) {
            show('لم يُسمع كلام — اضغط «تكلّم» وأعد.', 'bad');
            return;
        }
        const extension = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
        const body = new FormData();
        body.append('audio', blob, `order.${extension}`);
        send(body, 'يُقرأ كلامك… ثوانٍ قليلة.', listenUrl, (data) => {
            text.value = data.transcript ?? '';
            spoken = true;
        });
    };

    const startRecording = async () => {
        // ضغطةٌ ثانية والمتصفّح يسأل عن المايك لا تفتح تسجيلاً ثانياً
        if (starting) return;
        starting = true;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
            });
        } catch (error) {
            show(error?.name === 'NotAllowedError'
                ? 'اسمح للمتصفّح باستعمال المايك (من القفل بجانب العنوان) ثم اضغط «تكلّم».'
                : 'لا مايك يعمل في هذا الجهاز.', 'bad');
            return;
        } finally {
            starting = false;
        }

        const type = recordingType();
        recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
        chunks = [];
        recorder.ondataavailable = (event) => {
            if (event.data.size) chunks.push(event.data);
        };
        recorder.onstop = sendRecording;
        recorder.start(1000);

        startedAt = Date.now();
        text.value = '';
        setListening(true);
        meter(stream);
        hint.textContent = 'تكلّم براحتك وبالترتيب الذي تريد: الاسم، والرقم، والمحافظة والمنطقة وأقرب نقطة، والمبلغ. المايك يبقى مفتوحاً حتى تضغط «أوقف».';
        const tick = () => {
            show(`أسمعك… ${elapsed()} — اضغط «أوقف» حين تنتهي.`, 'info');
            if (Date.now() - startedAt >= LISTEN_LIMIT) stopRecording();
        };
        tick();
        clock = window.setInterval(tick, 500);
    };

    talk?.addEventListener('click', () => {
        paste.hidden = false;
        pasteOpen.setAttribute('aria-expanded', 'true');
        hint.hidden = false;
        spoken = true;

        if (canRecord) {
            if (listening) stopRecording();
            else startRecording();
            return;
        }

        if (!Recognition) {
            hint.textContent = 'اضغط زرّ المايك في لوحة مفاتيح هاتفك وتكلّم داخل الخانة: الاسم، ثم الرقم، ثم المحافظة والمنطقة وأقرب نقطة، ثم المبلغ. وبعدها «اقرأ الرسالة».';
            text.focus();
            return;
        }

        if (listening) {
            stopAsked = true;
            recognition?.stop();
            return;
        }

        heard = '';
        text.value = '';
        failed = false;
        stopAsked = false;
        startedAt = Date.now();
        hint.textContent = 'قل مثلاً: «الاسم علي حسين، الرقم صفر سبعة سبعة صفر…، بغداد الكرادة قرب الجامع، المبلغ خمسة وعشرين ألف». ثم اضغط «أوقف» فيُقرأ.';
        setListening(true);
        show('أسمعك… تكلّم بالطلب.', 'info');

        try {
            listen();
        } catch {
            failed = true;
            setListening(false);
            show('تعذّر السماع — اضغط «تكلّم» وأعد.', 'bad');
        }
    });

    if (!image) return;

    // صورةٌ في الحافظة (لقطة شاشة، أو «نسخ الصورة» من واتساب ويب): تُلصق في أيّ مكانٍ من الصفحة
    document.addEventListener('paste', (event) => {
        const item = [...(event.clipboardData?.items ?? [])].find((entry) => entry.type.startsWith('image/'));
        if (!item) return;
        event.preventDefault();
        upload(item.getAsFile());
    });

    const glow = ['ring-2', 'ring-primary-300'];
    section.addEventListener('dragover', (event) => {
        if (![...(event.dataTransfer?.items ?? [])].some((entry) => entry.kind === 'file')) return;
        event.preventDefault();
        section.classList.add(...glow);
    });
    section.addEventListener('dragleave', () => section.classList.remove(...glow));
    section.addEventListener('drop', (event) => {
        event.preventDefault();
        section.classList.remove(...glow);
        upload([...(event.dataTransfer?.files ?? [])].find((file) => file.type.startsWith('image/')));
    });
}

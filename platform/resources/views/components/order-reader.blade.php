@props([
    'url',            // مسار القراءة: shipments.read أو portal.shipments.read
    'form',           // id النموذج الذي تُملأ حقوله
    'listen' => null, // مسار السماع على الخادم: shipments.listen أو portal.shipments.listen (docs/plan/40)
])

@php
    // الذكاء الاصطناعي يقرأ الصورة والرسالة والكلام (docs/plan/40)؛ وبلا مفتاحه يقرأ القارئ المحلّي،
    // وبلا برنامج قراءة الصور على الخادم تبقى الرسالة الملصوقة وحدها
    $ai = app(\App\Services\Orders\AiOrderReader::class)->available();
    $images = $ai || app(\App\Services\Orders\ScreenshotText::class)->available();
    // «تكلّم» يسجّل حتى «أوقف» ويسمعه الخادم إن كان له محرّك سماع؛ وإلّا يسمع المتصفّح
    $listen = $listen && app(\App\Services\Orders\Speech\SpeechToText::class)->available() ? $listen : null;
@endphp

{{--
  «اقرأ الطلب من صورة أو رسالة» (docs/plan/34): لقطة شاشةٍ تُرفع أو تُلصق، أو نصّ الرسالة،
  فتُملأ الحقول وتُراجَع قبل الحفظ — لا يُحفظ شيءٌ هنا. order-reader.js
--}}
<section class="card mx-auto mb-4 max-w-3xl px-5 py-4 sm:px-7" data-order-reader data-url="{{ $url }}" data-form="{{ $form }}"
         @if ($listen) data-listen-url="{{ $listen }}" @endif
         aria-labelledby="order-reader-title">
    <div class="flex flex-wrap items-center gap-3">
        <span class="panel-head-icon"><x-icon name="chat" class="size-5"/></span>
        <div class="min-w-0 flex-1 basis-60">
            <h2 id="order-reader-title" class="font-heading text-base font-medium text-aeblack-950">
                @if ($ai)
                    اقرأ الطلب بالذكاء الاصطناعي
                @else
                    اقرأ الطلب من {{ $images ? 'صورة أو رسالة' : 'رسالة' }}
                @endif
            </h2>
            <p class="text-xs text-ink-500">
                {{ $images ? 'لقطة شاشة من واتساب أو ماسنجر، أو انسخ رسالة الزبون والصقها' : 'انسخ رسالة الزبون من واتساب أو ماسنجر والصقها' }}:
                تُملأ الحقول وتراجعها قبل الحفظ. أو اضغط «تكلّم» وقل الطلب بصوتك.
            </p>
        </div>
        {{-- على الهاتف تحت العنوان بعرض البطاقة: ثلاثة أعمدةٍ متساوية بأسماءٍ قصيرة --}}
        <div class="grid w-full {{ $images ? 'grid-cols-3' : 'grid-cols-2' }} gap-2 sm:flex sm:w-auto">
            @if ($images)
                <label class="btn-ghost min-w-0 cursor-pointer justify-center px-2 sm:px-4">
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" data-order-image>
                    <x-icon name="upload" class="size-4 shrink-0"/>
                    صورة
                </label>
            @endif
            <button type="button" class="btn-ghost min-w-0 justify-center px-2 sm:px-4" data-order-paste-open aria-expanded="false">
                <x-icon name="clipboard" class="size-4 shrink-0"/>
                <span class="sm:hidden">الصق</span><span class="max-sm:hidden">الصق رسالة</span>
            </button>
            {{-- الطلب بالصوت (docs/plan/40): يسمعه المتصفّح بالعربية ويقرؤه الخادم حقولاً --}}
            <button type="button" class="btn-ghost min-w-0 justify-center px-2 sm:px-4" data-order-talk aria-pressed="false">
                <x-icon name="mic" class="size-4 shrink-0"/>
                <span data-order-talk-label>تكلّم</span>
            </button>
        </div>
    </div>

    <div class="mt-3" data-order-paste hidden>
        <p class="mb-2 rounded-2xl bg-primary-50 px-4 py-2.5 text-sm leading-6 text-primary-900" data-order-talk-hint hidden></p>
        <label class="sr-only" for="order-reader-text">رسالة الزبون</label>
        <textarea id="order-reader-text" rows="4" class="field-input" data-order-text
                  placeholder="الصق رسالة الزبون هنا — الاسم والرقم والعنوان والسعر"></textarea>
        <button type="button" class="btn-primary mt-2" data-order-read>اقرأ الرسالة</button>
    </div>

    <div class="mt-3 rounded-2xl px-4 py-3 text-sm" data-order-status role="status" aria-live="polite" hidden></div>

    <details class="mt-2 text-xs text-ink-500" data-order-lines hidden>
        <summary class="cursor-pointer select-none">النص المقروء</summary>
        <pre class="mt-2 whitespace-pre-wrap rounded-xl bg-ink-50 p-3 font-sans text-sm leading-6 text-aeblack-800" data-order-lines-text></pre>
    </details>

    @if ($ai)
        <p class="mt-2 text-[11px] leading-5 text-ink-400">
            يقرأ الطلبَ الذكاءُ الاصطناعي ولا يُحفظ شيءٌ منه. تلصق الصورة أيضاً بـ Ctrl+V أو تسحبها إلى هنا،
            وراجع ما مُلئ قبل الحفظ.
        </p>
    @elseif ($images)
        <p class="mt-2 text-[11px] leading-5 text-ink-400">
            تُقرأ الصورة على خادم الشركة نفسه ولا تُحفظ. تلصقها أيضاً بـ Ctrl+V أو تسحبها إلى هنا،
            وإن أخطأت القراءة حرفاً فصحّحه قبل الحفظ.
        </p>
    @endif
</section>

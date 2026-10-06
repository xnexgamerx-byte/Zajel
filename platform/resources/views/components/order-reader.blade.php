@props([
    'url',            // مسار القراءة: shipments.read أو portal.shipments.read
    'form',           // id النموذج الذي تُملأ حقوله
])

@php
    // بلا البرنامج على الخادم تبقى قراءة الرسالة الملصوقة وحدها
    $images = app(\App\Services\Orders\ScreenshotText::class)->available();
@endphp

{{--
  «اقرأ الطلب من صورة أو رسالة» (docs/plan/34): لقطة شاشةٍ تُرفع أو تُلصق، أو نصّ الرسالة،
  فتُملأ الحقول وتُراجَع قبل الحفظ — لا يُحفظ شيءٌ هنا. order-reader.js
--}}
<section class="card mx-auto mb-4 max-w-3xl px-5 py-4 sm:px-7" data-order-reader data-url="{{ $url }}" data-form="{{ $form }}"
         aria-labelledby="order-reader-title">
    <div class="flex flex-wrap items-center gap-3">
        <span class="panel-head-icon"><x-icon name="chat" class="size-5"/></span>
        <div class="min-w-0 flex-1 basis-60">
            <h2 id="order-reader-title" class="font-heading text-base font-medium text-aeblack-950">اقرأ الطلب من {{ $images ? 'صورة أو رسالة' : 'رسالة' }}</h2>
            <p class="text-xs text-ink-500">
                {{ $images ? 'لقطة شاشة من واتساب أو ماسنجر، أو انسخ رسالة الزبون والصقها' : 'انسخ رسالة الزبون من واتساب أو ماسنجر والصقها' }}:
                تُملأ الحقول وتراجعها قبل الحفظ.
            </p>
        </div>
        {{-- على الهاتف تحت العنوان بعرض البطاقة --}}
        <div class="flex w-full gap-2 sm:w-auto">
            @if ($images)
                <label class="btn-ghost flex-1 cursor-pointer sm:flex-none">
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" data-order-image>
                    <x-icon name="upload" class="size-4"/>
                    صورة
                </label>
            @endif
            <button type="button" class="btn-ghost flex-1 sm:flex-none" data-order-paste-open aria-expanded="false">
                <x-icon name="clipboard" class="size-4"/>
                الصق رسالة
            </button>
        </div>
    </div>

    <div class="mt-3" data-order-paste hidden>
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

    @if ($images)
        <p class="mt-2 text-[11px] leading-5 text-ink-400">
            تُقرأ الصورة على خادم الشركة نفسه ولا تُحفظ. تلصقها أيضاً بـ Ctrl+V أو تسحبها إلى هنا،
            وإن أخطأت القراءة حرفاً فصحّحه قبل الحفظ.
        </p>
    @endif
</section>

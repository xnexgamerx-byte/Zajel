@props([
    'lookup',          // يُسأل عن الوصل الممسوح: هل هو من القائمة، وإلّا فلماذا
    'open' => null,    // بلا قائمةٍ تُعلَّم (تسليم الراجع قبل اختيار التاجر): تُفتح قائمة صاحب الطرد
    'hint' => 'امسح الوصل — الباركود أو رمز QR — فيُعلَّم في القائمة.',
    'only' => false,   // قائمةٌ مختارةٌ كلّها سلفاً: أوّل مسحةٍ تُبقي الممسوح وحده مختاراً
    'append' => null,  // خانةٌ يُضاف إليها ما ليس في القائمة (الإرسال لفرع)
    'param' => 'code', // اسم الرمز في سؤال الخادم
])

<section {{ $attributes->merge(['class' => 'card mb-4 p-5']) }} data-scan-box data-lookup="{{ $lookup }}" data-param="{{ $param }}"
         @if ($open) data-open="{{ $open }}" @endif @if ($only) data-only @endif @if ($append) data-append="{{ $append }}" @endif>
    <label class="field-label" for="scan-box-input">مسح الوصولات</label>
    <div class="flex gap-2">
        <input id="scan-box-input" class="field-input text-lg" autocomplete="off" autofocus inputmode="text"
               placeholder="امسح الباركود أو رمز QR، أو اكتب الرقم ثم Enter" data-scan-box-input>
        <button type="button" class="btn-primary" data-scan-box-add>أضِف</button>
    </div>
    <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
        <p class="min-h-5 text-sm text-ink-500" role="status" aria-live="polite" data-scan-box-message>{{ $hint }}</p>
        @unless ($open)
            <p class="text-sm text-ink-600">
                ممسوح <span class="num font-semibold" data-scan-box-count>0</span>
                من <span class="num" data-scan-box-total>0</span>
            </p>
        @endunless
    </div>
</section>

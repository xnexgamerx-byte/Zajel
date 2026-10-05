@props([
    'form',               // id النموذج: صفوفه input[form=…] تُرسَل إليه
    'total'    => null,   // عدد ما في الكشف كلّه: «الكل» حين يُحدَّد بقدره
    'all'      => null,   // ما يُقال حين يُحدَّد الكل
    'netLabel' => null,   // اسم مجموع data-net للمحدَّد («الواجب تسليمه»)، إن كان له مجموع
    'add'      => null,   // id حقلٍ يُزاد على المجموع (الخصومات)
])

{{-- ما حُدِّد يظهر أسفل الشاشة بعدده وما يُعمل به (behaviors.js: data-picked-bar) --}}
<div class="border-t border-ink-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur" hidden
     data-picked-bar="{{ $form }}" @if ($total !== null) data-picked-total="{{ $total }}" @endif
     @if ($all) data-picked-all="{{ $all }}" @endif @if ($add) data-picked-add="{{ $add }}" @endif>
    <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-x-4 gap-y-2">
        <span class="text-sm font-semibold">المحدَّد: <span data-picked-count></span></span>
        @if ($netLabel)
            <span class="text-sm text-ink-600">{{ $netLabel }}: <b class="num text-aeblack-950" data-picked-net dir="ltr"></b> د.ع</span>
        @endif
        <span class="ms-auto flex flex-wrap items-center gap-2">
            {{ $slot }}
            <button type="button" class="btn-ghost h-9 px-3 text-sm" data-picked-clear>إلغاء التحديد</button>
        </span>
    </div>
</div>

{{-- رقمٌ في بطاقة: عنوانه، وقيمته بالدينار، وسطرٌ يشرحه --}}
<div class="card p-4">
    <div class="text-xs font-medium text-ink-500">{{ $label }}</div>
    <div class="mt-1 text-2xl font-bold {{ $tone ?? 'text-ink-900' }}">
        <span class="num">{{ number_format($value) }}</span> <span class="text-sm font-medium text-ink-500">د.ع</span>
    </div>
    @isset($hint)<div class="mt-1 text-xs leading-5 text-ink-500">{{ $hint }}</div>@endisset
</div>

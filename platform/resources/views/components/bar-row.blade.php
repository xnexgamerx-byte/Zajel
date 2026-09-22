@props(['label', 'value', 'max', 'sub' => null, 'suffix' => null])

{{-- شريط تسلسليّ بلون واحد: المقارنة هنا حجمٌ لا هويّة، فلا داعي لألوان --}}
<div>
    <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
        <span class="min-w-0 truncate text-ink-700">
            {{ $label }}
            @if ($sub)
                <span class="text-xs text-ink-500">· {{ $sub }}</span>
            @endif
        </span>
        <span class="num shrink-0 font-semibold">
            {{ number_format($value) }}<span class="text-xs font-normal text-ink-500">{{ $suffix }}</span>
        </span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-ink-100">
        <div class="h-full rounded-full bg-[var(--brand)]"
             style="width: {{ $max > 0 ? max(2, round($value / $max * 100)) : 0 }}%"></div>
    </div>
</div>

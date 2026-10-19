{{-- الرسم ٣: المحصَّل نقداً في المدّة، وغير المحصَّل الآن بأماكنه الأربعة — شريطٌ واحد مكدَّس --}}
@php($total = max(1, collect($parts)->sum(fn ($p) => max(0, $p['value']))))
<div class="flex h-6 w-full overflow-hidden rounded-full bg-ink-100" role="img" aria-label="المحصَّل وغير المحصَّل">
    @foreach ($parts as $part)
        @if ($part['value'] > 0)
            <a @if ($part['href']) href="{{ $part['href'] }}" @endif title="{{ $part['label'] }}: {{ number_format($part['value']) }}"
               style="width: {{ round($part['value'] / $total * 100, 2) }}%; background: var({{ $part['color'] }})" class="block h-full border-e-2 border-white"></a>
        @endif
    @endforeach
</div>
<ul class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
    @foreach ($parts as $part)
        <li class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-2"><span class="inline-block size-3 rounded-sm" style="background: var({{ $part['color'] }})"></span>
                @if ($part['href'])<a href="{{ $part['href'] }}" class="hover:underline">{{ $part['label'] }}</a>@else{{ $part['label'] }}@endif
            </span>
            <span class="num font-semibold">{{ number_format($part['value']) }} <span class="text-xs font-normal text-ink-500">{{ round(max(0, $part['value']) / $total * 100) }}٪</span></span>
        </li>
    @endforeach
</ul>

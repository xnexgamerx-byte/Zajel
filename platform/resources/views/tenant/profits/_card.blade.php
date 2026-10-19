{{--
  بطاقةٌ من بطاقات اللوحة: حرفها وعنوانها وقيمتها، ومقارنتها بالمدّة السابقة (نصّاً وسهماً، لا
  لوناً وحده)، و«كيف حُسب»: المعادلة، وأجزاؤها كلٌّ برابطه إلى قيوده أو قائمته.
--}}
@php
    $unit ??= 'iqd';
    $prev ??= null;
    $change = $prev === null ? null : ($prev == 0 ? null : (int) round(($value - $prev) / abs($prev) * 100));
@endphp
<section class="card flex flex-col p-4">
    <div class="flex items-start justify-between gap-2">
        <h2 class="text-xs font-medium text-ink-500"><span class="chip chip-mute me-1">{{ $letter }}</span> {{ $label }}</h2>
        @if (! empty($now))<span class="text-[11px] text-ink-400">الآن</span>@endif
    </div>
    <div class="mt-2 text-2xl font-bold {{ $tone ?? 'text-ink-900' }}">
        <span class="num">{{ $value < 0 ? '−' : '' }}{{ number_format(abs($value)) }}</span>
        @if ($unit === 'iqd')<span class="text-sm font-medium text-ink-500">د.ع</span>@endif
    </div>
    @if ($prev !== null)
        <div class="mt-1 text-xs text-ink-500">
            @if ($change === null)
                المدّة السابقة <span class="num">{{ number_format($prev) }}</span>
            @else
                <span @class(['font-semibold', 'text-ok-700' => $change > 0 xor ! empty($inverse), 'text-bad-700' => $change < 0 xor ! empty($inverse)])>
                    {{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '■') }} <span class="num">{{ abs($change) }}٪</span>
                </span>
                عن المدّة السابقة (<span class="num">{{ number_format($prev) }}</span>)
            @endif
        </div>
    @endif
    @isset($hint)<p class="mt-1 text-xs leading-5 text-ink-500">{{ $hint }}</p>@endisset

    <details class="mt-auto pt-3 text-xs">
        <summary class="cursor-pointer font-semibold text-[var(--brand)]">كيف حُسب</summary>
        <p class="mt-2 rounded-lg bg-ink-50 p-2 leading-5 text-ink-700">{{ $formula }}</p>
        <ul class="mt-2 divide-y divide-ink-100">
            @foreach ($parts as $part)
                <li class="flex items-start justify-between gap-2 py-1.5">
                    <span>
                        @if (! empty($part['href']))<a href="{{ $part['href'] }}" class="text-[var(--brand)] hover:underline">{{ $part['label'] }}</a>@else{{ $part['label'] }}@endif
                        @if (! empty($part['note']))<span class="block text-ink-400">{{ $part['note'] }}</span>@endif
                    </span>
                    @if (array_key_exists('value', $part) && $part['value'] !== null)
                        <span class="num shrink-0 font-semibold">{{ $part['value'] ? ($part['sign'] ?? '') : '' }}{{ number_format($part['value']) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </details>
</section>

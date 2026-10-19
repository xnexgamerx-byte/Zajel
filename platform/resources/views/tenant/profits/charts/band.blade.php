{{-- الرصيد المتوقَّع: خطٌّ للمتوقَّع، ونطاقٌ مظلَّل بين الأدنى والأعلى، وخطّ الصفر ظاهرٌ إن عبره --}}
@php
    $n = max(1, count($days));
    $values = collect($days)->flatMap(fn ($d) => [$d['low'], $d['high'], $d['balance']])->push($start);
    $top = max(1, (int) $values->max());
    $bottom = min(0, (int) $values->min());
    $w = 900 / $n;
    $x = fn (int $i) => round(70 + $i * $w + $w / 2, 1);
    $y = fn (int $v) => round(20 + ($top - $v) / max(1, $top - $bottom) * 220, 1);
    $upper = collect($days)->values()->map(fn ($d, $i) => $x($i).','.$y($d['high']))->implode(' ');
    $lower = collect($days)->values()->reverse()->map(fn ($d, $i) => $x($i).','.$y($d['low']))->implode(' ');
@endphp
<svg viewBox="0 0 1000 270" class="w-full" style="height: 16rem" role="img" aria-label="الرصيد النقدي المتوقَّع أربعة عشر يوماً بنطاقه">
    @foreach ([0, 0.5, 1] as $step)
        @php($v = (int) round($bottom + ($top - $bottom) * $step))
        <line x1="70" y1="{{ $y($v) }}" x2="970" y2="{{ $y($v) }}" stroke="var(--color-ink-200)" stroke-width="1"></line>
        <text x="62" y="{{ $y($v) + 4 }}" font-size="11" fill="var(--color-ink-500)" text-anchor="end">{{ number_format($v) }}</text>
    @endforeach
    @if ($bottom < 0)<line x1="70" y1="{{ $y(0) }}" x2="970" y2="{{ $y(0) }}" stroke="var(--color-bad-700)" stroke-width="1" stroke-dasharray="4 3"></line>@endif
    <polygon points="{{ $upper }} {{ $lower }}" fill="var(--color-profit-200)" opacity="0.55"></polygon>
    <polyline points="{{ collect($days)->values()->map(fn ($d, $i) => $x($i).','.$y($d['balance']))->implode(' ') }}" fill="none" stroke="var(--color-profit-700)" stroke-width="2.5"></polyline>
    @foreach (array_values($days) as $i => $d)
        <g>
            <title>{{ $d['date'] }} — المتوقَّع {{ number_format($d['balance']) }} (بين {{ number_format($d['low']) }} و{{ number_format($d['high']) }})</title>
            <rect x="{{ $x($i) - $w / 2 }}" y="20" width="{{ round($w, 1) }}" height="220" fill="transparent"></rect>
            <circle cx="{{ $x($i) }}" cy="{{ $y($d['balance']) }}" r="3" fill="var(--color-profit-700)" stroke="white" stroke-width="1.5"></circle>
        </g>
        <text x="{{ $x($i) }}" y="262" font-size="11" fill="var(--color-ink-500)" text-anchor="middle">{{ $d['label'] }}</text>
    @endforeach
</svg>
<ul class="mt-2 flex flex-wrap gap-4 text-xs text-ink-600" aria-label="مفتاح الرسم">
    <li class="flex items-center gap-1"><span class="inline-block h-0.5 w-4" style="background: var(--color-profit-700)"></span> الرصيد المتوقَّع</li>
    <li class="flex items-center gap-1"><span class="inline-block size-3 rounded-sm" style="background: var(--color-profit-200)"></span> النطاق: الأدنى والأعلى</li>
</ul>

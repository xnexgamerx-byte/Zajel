{{--
  الرسم ١: الإيراد والتكلفة عموداً لكل يوم (أو أسبوع)، والصافي خطّاً، وصافي المدّة السابقة
  بالموضع نفسه خطّاً شبحياً. كل عمودٍ رابطٌ إلى قيود يومه، وتلميحه نصٌّ لا لون.
--}}
@php
    $n = max(1, count($series));
    $values = collect($series)->flatMap(fn ($b) => [$b['revenue'], $b['costs'], $b['net'], $b['previous']]);
    $top = max(1, (int) $values->max());
    $bottom = min(0, (int) $values->min());
    $y = fn (int $v) => round(20 + ($top - $v) / max(1, $top - $bottom) * 240, 1);
    $w = 900 / $n;
    $x = fn (int $i) => round(70 + $i * $w, 1);
    $points = fn (string $key) => collect($series)->values()->map(fn ($b, $i) => ($x($i) + $w / 2).','.$y((int) $b[$key]))->implode(' ');
@endphp
<svg viewBox="0 0 1000 300" class="w-full" style="height: 18rem" role="img" aria-label="الإيراد والتكلفة وصافي الربح في المدّة">
    @foreach ([0, 0.25, 0.5, 0.75, 1] as $step)
        @php($v = (int) round($bottom + ($top - $bottom) * $step))
        <line x1="70" y1="{{ $y($v) }}" x2="970" y2="{{ $y($v) }}" stroke="var(--color-ink-200)" stroke-width="1"></line>
        <text x="62" y="{{ $y($v) + 4 }}" font-size="11" fill="var(--color-ink-500)" text-anchor="end">{{ number_format($v) }}</text>
    @endforeach
    <line x1="70" y1="{{ $y(0) }}" x2="970" y2="{{ $y(0) }}" stroke="var(--color-ink-400)" stroke-width="1"></line>

    @foreach (array_values($series) as $i => $b)
        @php($bar = max(2, $w * 0.32))
        <a @if ($link) href="{{ $link($b['from'], $b['to']) }}" @endif>
            <title>{{ $b['label'] }} — إيراد {{ number_format($b['revenue']) }}، تكلفة {{ number_format($b['costs']) }}، صافٍ {{ number_format($b['net']) }}، والمدّة السابقة {{ number_format($b['previous']) }}</title>
            <rect x="{{ $x($i) }}" y="20" width="{{ round($w, 1) }}" height="240" fill="transparent"></rect>
            <rect x="{{ $x($i) + $w * 0.16 }}" y="{{ min($y($b['revenue']), $y(0)) }}" width="{{ $bar }}" height="{{ max(0.5, abs($y(0) - $y($b['revenue']))) }}" rx="2" fill="var(--color-info-700)"></rect>
            <rect x="{{ $x($i) + $w * 0.16 + $bar + 2 }}" y="{{ min($y($b['costs']), $y(0)) }}" width="{{ $bar }}" height="{{ max(0.5, abs($y(0) - $y($b['costs']))) }}" rx="2" fill="var(--color-bad-200)"></rect>
        </a>
    @endforeach

    <polyline points="{{ $points('previous') }}" fill="none" stroke="var(--color-ink-400)" stroke-width="2" stroke-dasharray="5 4"></polyline>
    <polyline points="{{ $points('net') }}" fill="none" stroke="var(--color-profit-700)" stroke-width="2.5" stroke-linejoin="round"></polyline>
    @foreach (array_values($series) as $i => $b)
        <circle cx="{{ $x($i) + $w / 2 }}" cy="{{ $y((int) $b['net']) }}" r="{{ $n > 40 ? 2 : 3.5 }}" fill="var(--color-profit-700)" stroke="white" stroke-width="1.5"></circle>
    @endforeach

    @foreach (array_values($series) as $i => $b)
        @if ($n <= 16 || $i % (int) ceil($n / 12) === 0)
            <text x="{{ $x($i) + $w / 2 }}" y="282" font-size="11" fill="var(--color-ink-500)" text-anchor="middle">{{ $b['label'] }}</text>
        @endif
    @endforeach
</svg>
<ul class="mt-2 flex flex-wrap gap-4 text-xs text-ink-600" aria-label="مفتاح الرسم">
    <li class="flex items-center gap-1"><span class="inline-block size-3 rounded-sm" style="background: var(--color-info-700)"></span> الإيراد</li>
    <li class="flex items-center gap-1"><span class="inline-block size-3 rounded-sm" style="background: var(--color-bad-200)"></span> التكلفة</li>
    <li class="flex items-center gap-1"><span class="inline-block h-0.5 w-4" style="background: var(--color-profit-700)"></span> صافي الربح</li>
    <li class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 border-t-2 border-dashed border-ink-400"></span> صافي المدّة السابقة</li>
</ul>

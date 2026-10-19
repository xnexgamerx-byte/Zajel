{{-- الرسم ٤: النقد المعدود في الوجبات المعتمدة يوماً بيوم؛ كل يومٍ رابطٌ إلى وجباته --}}
@php
    $n = max(1, count($days));
    $top = max(1, (int) collect($days)->max('cash'));
    $w = 900 / $n;
    $x = fn (int $i) => round(70 + $i * $w + $w / 2, 1);
    $y = fn (int $v) => round(20 + ($top - $v) / $top * 200, 1);
@endphp
<svg viewBox="0 0 1000 250" class="w-full" style="height: 14rem" role="img" aria-label="التحصيلات اليومية من الوجبات المعتمدة">
    @foreach ([0, 0.5, 1] as $step)
        @php($v = (int) round($top * $step))
        <line x1="70" y1="{{ $y($v) }}" x2="970" y2="{{ $y($v) }}" stroke="var(--color-ink-200)" stroke-width="1"></line>
        <text x="62" y="{{ $y($v) + 4 }}" font-size="11" fill="var(--color-ink-500)" text-anchor="end">{{ number_format($v) }}</text>
    @endforeach
    <polyline points="{{ collect($days)->values()->map(fn ($d, $i) => $x($i).','.$y($d['cash']))->implode(' ') }}"
              fill="none" stroke="var(--color-ok-700)" stroke-width="2.5" stroke-linejoin="round"></polyline>
    @foreach (array_values($days) as $i => $d)
        <a href="{{ $link($d['day']) }}">
            <title>{{ $d['label'] }} — {{ number_format($d['cash']) }} د.ع من {{ \App\Support\Arabic::count($d['batches'], ['وجبة واحدة', 'وجبتين', 'وجبات', 'وجبة']) }}</title>
            <rect x="{{ $x($i) - $w / 2 }}" y="20" width="{{ round($w, 1) }}" height="200" fill="transparent"></rect>
            @if ($d['cash'] > 0)<circle cx="{{ $x($i) }}" cy="{{ $y($d['cash']) }}" r="3.5" fill="var(--color-ok-700)" stroke="white" stroke-width="1.5"></circle>@endif
        </a>
        @if ($n <= 16 || $i % (int) ceil($n / 12) === 0)
            <text x="{{ $x($i) }}" y="240" font-size="11" fill="var(--color-ink-500)" text-anchor="middle">{{ $d['label'] }}</text>
        @endif
    @endforeach
</svg>

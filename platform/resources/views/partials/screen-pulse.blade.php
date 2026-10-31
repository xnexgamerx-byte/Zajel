{{--
  نبض الشاشة (docs/plan/101) في «بطاقات فاتحة» و«زجاجيّ»: رسمة القسم واسمه، وعدّاداته، ورسم أيّامه الأربعة
  عشر واليوم ملوّن. يُطوى بزرّه ويبقى مطويّاً لهذا القسم على هذا الجهاز (app.js).
--}}
@php
    $series = collect($pulse['series']);
    $top = max(1, (int) $series->max('count'));
@endphp
<section class="pulse" data-pulse="{{ $menu }}" aria-label="{{ $pulse['title'] }}">
    <div class="pulse-art" aria-hidden="true">
        <svg viewBox="0 0 120 96">
            <ellipse cx="60" cy="86" rx="46" ry="6" class="pulse-shadow"/>
            @switch($pulse['art'])
                @case('truck')
                    <rect x="14" y="30" width="58" height="40" rx="6" class="pulse-a"/>
                    <path d="M72 42h18l12 14v14H72z" class="pulse-b"/>
                    <path d="M78 47h10l7 8H78z" class="pulse-glass"/>
                    <circle cx="32" cy="72" r="9" class="pulse-dark"/><circle cx="32" cy="72" r="3.5" class="pulse-light"/>
                    <circle cx="88" cy="72" r="9" class="pulse-dark"/><circle cx="88" cy="72" r="3.5" class="pulse-light"/>
                    <path d="M4 40h12M0 50h14M6 60h10" class="pulse-line"/>
                    @break
                @case('undo')
                    <path d="M30 40l30-14 30 14v30L60 84 30 70z" class="pulse-b"/>
                    <path d="M30 40l30 14 30-14M60 54v30" class="pulse-stroke"/>
                    <path d="M78 22a22 22 0 1 0 8 26" class="pulse-arrow"/><path d="M86 14v12H74" class="pulse-arrow"/>
                    @break
                @case('wallet')
                    <rect x="18" y="30" width="72" height="46" rx="10" class="pulse-a"/>
                    <rect x="64" y="44" width="30" height="18" rx="9" class="pulse-b"/><circle cx="74" cy="53" r="3.5" class="pulse-dark"/>
                    <circle cx="92" cy="26" r="11" class="pulse-coin"/><circle cx="104" cy="40" r="8" class="pulse-coin"/>
                    <path d="M89 22h6M89 30h6" class="pulse-stroke"/>
                    @break
                @case('building')
                    <rect x="18" y="34" width="34" height="46" rx="4" class="pulse-b"/>
                    <rect x="56" y="18" width="44" height="62" rx="4" class="pulse-a"/>
                    @foreach ([[64, 28], [80, 28], [64, 42], [80, 42], [64, 56], [80, 56], [26, 44], [38, 44], [26, 58], [38, 58]] as [$x, $y])
                        <rect x="{{ $x }}" y="{{ $y }}" width="8" height="8" rx="1.5" class="pulse-glass"/>
                    @endforeach
                    @break
                @case('inbound')
                    <path d="M16 54l14-24h60l14 24v22a6 6 0 0 1-6 6H22a6 6 0 0 1-6-6z" class="pulse-b"/>
                    <path d="M16 54h26l6 10h24l6-10h26" class="pulse-stroke"/>
                    <rect x="44" y="14" width="32" height="28" rx="4" class="pulse-a"/><path d="M60 22v12m-6-6 6 6 6-6" class="pulse-arrow"/>
                    @break
                @case('review')
                    <rect x="28" y="16" width="56" height="68" rx="8" class="pulse-b"/>
                    <rect x="44" y="10" width="24" height="12" rx="4" class="pulse-a"/>
                    <path d="M40 40l6 6 10-10M40 58l6 6 10-10" class="pulse-arrow"/><path d="M62 42h14M62 60h14" class="pulse-stroke"/>
                    <circle cx="92" cy="24" r="12" class="pulse-coin"/><path d="M92 18v7l4 3" class="pulse-stroke"/>
                    @break
                @default
                    <path d="M22 38l24-12 24 12v26L46 76 22 64z" class="pulse-b"/>
                    <path d="M22 38l24 12 24-12M46 50v26" class="pulse-stroke"/>
                    <path d="M58 30l24-12 24 12v26L82 68 58 56z" class="pulse-a"/>
                    <path d="M58 30l24 12 24-12M82 42v26" class="pulse-stroke-light"/>
            @endswitch
        </svg>
    </div>

    <div class="pulse-head">
        <h2>{{ $pulse['title'] }}</h2>
        <small>{{ $pulse['series_label'] }} · آخر ١٤ يوماً</small>
    </div>

    <div class="pulse-counters">
        @foreach ($pulse['counters'] as $c)
            <div class="pulse-counter pulse-{{ $c['tone'] ?? 'plain' }}">
                <b><span class="num">{{ $c['value'] }}</span>@isset($c['unit'])<span class="pulse-unit"> {{ $c['unit'] }}</span>@endisset</b>
                <small>{{ $c['label'] }}</small>
            </div>
        @endforeach
    </div>

    <div class="pulse-chart" role="img" aria-label="{{ $pulse['series_label'] }}">
        @foreach ($series as $d)
            <span class="pulse-col {{ $d['today'] ? 'pulse-today' : '' }}" title="{{ $d['label'] }}: {{ number_format($d['count']) }}">
                <i style="height: {{ max(6, round(100 * $d['count'] / $top)) }}%"></i>
            </span>
        @endforeach
    </div>

    <button type="button" class="pulse-toggle" data-pulse-toggle aria-label="اطوِ النبض" title="اطوِ أو افتح">
        <x-icon name="chevron-down" class="size-4"/>
    </button>
</section>

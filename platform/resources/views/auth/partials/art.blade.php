{{--
  رسم صفحات الدخول (docs/plan/105): طبقات ورقٍ مقصوص تكشف لوحة دوائر إلكترونية برقاقةٍ في وسطها، ويسري
  التيار في مساراتها. الألوان كلّها من --accent في login.css، والحركة CSS وحدها، وتتوقّف لمن طلب حركةً أقلّ.
  المسار يُكتب مرّةً في $traces ويُرسم مرّتين: خطّه، ثم التيار فوقه.
--}}
@php
    // [المسار، الطبقة، مدّة دورة التيار، تأخيره] — التيار يسري من أول المسار إلى آخره
    $traces = [
        ['M-20 120 H120 L170 170 H300', 'back', 4.2, 0.4],
        ['M60 -20 V80 L110 130 V250', 'back', 3.8, 1.9],
        ['M-20 360 H90 L140 310 H200', 'back', 4.6, 2.6],
        ['M430 -20 V60 L480 110 H640', 'back', 4.0, 0.9],
        ['M120 720 V580 L170 530 H270', 'back', 4.4, 3.1],
        ['M-20 214 H140 L188 262 H214', 'mid', 2.8, 0.0],
        ['M300 -20 V118 L254 164 V240', 'mid', 3.0, 1.2],
        ['M392 300 H486 L536 250 H660', 'mid', 3.2, 0.6],
        ['M300 432 V506 L350 556 H470', 'mid', 2.9, 1.8],
        ['M-20 470 H60 L110 420 H158', 'mid', 3.4, 2.3],
        ['M30 -20 V40 L80 90 H196 L236 50 H360', 'front', 3.6, 0.8],
        ['M418 240 H470 L520 190', 'front', 2.6, 2.0],
        ['M-20 612 H80 L130 562 H226 L270 518 V448', 'front', 3.8, 1.4],
        ['M420 518 H494 L540 472 V392', 'front', 3.0, 2.8],
    ];
    $pads = [
        'back' => [[300, 170, 13], [110, 250, 13], [200, 310, 13], [270, 530, 13]],
        'mid' => [[158, 420, 11], [470, 556, 11]],
        'front' => [[360, 50, 9], [226, 562, 8], [420, 518, 9]],
    ];
@endphp
{{--
  ثلاث طبقات بالمقاس نفسه (docs/plan/110): الرسم الثابت بظلاله يُرسم مرّةً، والتيار وحده يتحرّك في طبقته بلا
  مرشّحات، والورق فوقه ثابت. فلا يُعاد رسم الظلال في كلّ إطار، وتسلس الحركة على الهاتف.
--}}
<svg class="art-base" viewBox="0 0 760 700" preserveAspectRatio="xMinYMin slice" focusable="false">
    <defs>
        <linearGradient id="lg-sky" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" class="sky-a"/>
            <stop offset="1" class="sky-b"/>
        </linearGradient>
        <radialGradient id="lg-glow" cx=".4" cy=".45" r=".45">
            <stop offset="0" class="acc-stop" stop-opacity=".45"/>
            <stop offset="1" class="acc-stop" stop-opacity="0"/>
        </radialGradient>
        <pattern id="lg-grid" width="30" height="30" patternUnits="userSpaceOnUse">
            <path d="M30 0H0V30" fill="none" stroke="#8ea2d6" stroke-opacity=".08"/>
        </pattern>
        <filter id="lg-lift" x="-20%" y="-20%" width="140%" height="150%">
            <feDropShadow dx="0" dy="14" stdDeviation="14" flood-color="#01040d" flood-opacity=".6"/>
        </filter>
    </defs>

    {{-- العمق --}}
    <rect width="760" height="700" fill="url(#lg-sky)"/>
    <rect width="760" height="700" fill="url(#lg-glow)"/>
    <rect width="760" height="700" fill="url(#lg-grid)"/>

    {{-- المسارات بطبقاتها، من الأعمق --}}
    @foreach (['back' => 11, 'mid' => 9] as $layer => $width)
        <g class="trace-{{ $layer }}" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="{{ $width }}" filter="url(#lg-lift)">
            @foreach ($traces as [$d, $l])
                @if ($l === $layer)<path d="{{ $d }}"/>@endif
            @endforeach
        </g>
        <g class="pad-{{ $layer }}" filter="url(#lg-lift)">
            @foreach ($pads[$layer] as [$x, $y, $r])<circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $r }}"/>@endforeach
        </g>
    @endforeach

    {{-- الرقاقة --}}
    <g transform="translate(214 246)" filter="url(#lg-lift)">
        <g class="chip-pins">
            @foreach ([22, 50, 78, 106, 134] as $p)
                <rect x="{{ $p }}" y="-14" width="10" height="18" rx="3"/>
                <rect x="{{ $p }}" y="174" width="10" height="18" rx="3"/>
                <rect x="-14" y="{{ $p }}" width="18" height="10" rx="3"/>
                <rect x="174" y="{{ $p }}" width="18" height="10" rx="3"/>
            @endforeach
        </g>
        <rect width="178" height="178" rx="22" class="chip-body"/>
        <rect x="12" y="12" width="154" height="154" rx="16" fill="none" stroke-width="2" class="chip-ring"/>
        <text x="89" y="104" text-anchor="middle" direction="ltr" class="chip-glyph">&lt;/&gt;</text>
        <rect x="58" y="124" width="62" height="6" rx="3" class="chip-bar"/>
    </g>

    <g class="trace-front" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="6.5" filter="url(#lg-lift)">
        @foreach ($traces as [$d, $l])
            @if ($l === 'front')<path d="{{ $d }}"/>@endif
        @endforeach
    </g>
    <g class="pad-front" filter="url(#lg-lift)">
        @foreach ($pads['front'] as [$x, $y, $r])<circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $r }}"/>@endforeach
    </g>
    <g class="pad-hole">
        <circle cx="360" cy="50" r="3.5"/><circle cx="420" cy="518" r="3.5"/>
    </g>
</svg>

{{-- التيار: نبضةٌ قصيرة تسري في كل مسار، بمدّةٍ وتأخيرٍ يخصّانه. التوهّج خطٌّ أعرض شفيف، لا تغبيش --}}
<svg class="art-current" viewBox="0 0 760 700" preserveAspectRatio="xMinYMin slice" focusable="false">
    <g class="current" fill="none" stroke-linecap="round">
        @foreach ($traces as [$d, $layer, $t, $delay])
            <path d="{{ $d }}" pathLength="1" class="current-glow l-{{ $layer }}" stroke-width="{{ $layer === 'front' ? 9 : 11 }}" style="--t: {{ $t }}s; --d: {{ $delay }}s"/>
            <path d="{{ $d }}" pathLength="1" class="current-core l-{{ $layer }}" stroke-width="{{ $layer === 'front' ? 3 : 3.5 }}" style="--t: {{ $t }}s; --d: {{ $delay }}s"/>
        @endforeach
    </g>
</svg>

<svg class="art-paper" viewBox="0 0 760 700" preserveAspectRatio="xMinYMin slice" focusable="false">
    <defs>
        <filter id="lg-sheet" x="-30%" y="-10%" width="160%" height="130%">
            <feDropShadow dx="-7" dy="8" stdDeviation="9" flood-color="#050a1a" flood-opacity=".42"/>
        </filter>
        <filter id="lg-sheet-soft" x="-30%" y="-10%" width="160%" height="130%">
            <feDropShadow dx="-5" dy="6" stdDeviation="7" flood-color="#0b1224" flood-opacity=".22"/>
        </filter>
        <path id="lg-edge" d="M670 -10 C 610 60, 705 120, 640 195 C 585 255, 525 235, 512 305 C 498 378, 608 388, 586 458 C 566 525, 460 522, 482 592 C 496 640, 578 660, 568 712 L 900 712 L 900 -10 Z"/>
        <path id="lg-floor" d="M-10 528 C 60 500, 112 566, 178 562 C 246 558, 262 612, 330 622 C 400 632, 470 650, 560 712 L -10 712 Z"/>
    </defs>

    {{-- طبقات الورق من الأسفل (تُخفى على الهاتف) --}}
    <g class="floor">
        <use href="#lg-floor" fill="#aeb8ca" transform="translate(64 -64)" filter="url(#lg-sheet)"/>
        <use href="#lg-floor" fill="#c3cad8" transform="translate(46 -46)" filter="url(#lg-sheet)"/>
        <use href="#lg-floor" fill="#d6dce6" transform="translate(30 -30)" filter="url(#lg-sheet-soft)"/>
        <use href="#lg-floor" fill="#e8ecf2" transform="translate(14 -14)" filter="url(#lg-sheet-soft)"/>
        <use href="#lg-floor" fill="#ffffff" filter="url(#lg-sheet-soft)"/>
    </g>

    {{-- طبقات الورق من جهة النموذج --}}
    <use href="#lg-edge" fill="#a9b3c6" transform="translate(-76 18)" filter="url(#lg-sheet)"/>
    <use href="#lg-edge" fill="#bcc5d4" transform="translate(-57 13)" filter="url(#lg-sheet)"/>
    <use href="#lg-edge" fill="#d0d6e1" transform="translate(-38 9)" filter="url(#lg-sheet)"/>
    <use href="#lg-edge" fill="#e3e7ee" transform="translate(-19 4)" filter="url(#lg-sheet-soft)"/>
    <use href="#lg-edge" fill="#ffffff" filter="url(#lg-sheet-soft)"/>
</svg>

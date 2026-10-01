@extends('layouts.app')
@section('title', 'الحركة اليومية')

@php
    /*
    | ثلاث سلاسل تمثّل حالات، فتأخذ ألوان مجموعة الحالات المحجوزة.
    | مُتحقَّق منها بمدقّق اللوحة على كل الأزواج: أسوأ زوج ΔE 11.0
    | (protan) وأسوأ زوج بالرؤية العادية 25.6، والتباين مع السطح ≥ 3:1.
    | والأخضر مع الوردي كان يسقط عند 5.4، فحلّ الكهرمانيّ محلّ الوردي
    | للراجع — وهو أصدق دلالةً أيضاً: الراجع انتباه لا خسارة.
    */
    $series = [
        ['key' => 'created',   'label' => 'أُنشئت', 'color' => '#1D4ED8'],
        ['key' => 'delivered', 'label' => 'وصلت',   'color' => '#047857'],
        ['key' => 'returned',  'label' => 'رجعت',   'color' => '#D97706'],
    ];

    $max = max(1, $days->map(fn ($d) => max($d['created'], $d['delivered'], $d['returned']))->max());
    $count = max(1, $days->count() - 1);

    /*
    | الرسم من اليمين لليسار كالصفحة: أقدم يوم يمين وأحدثه يسار.
    | SVG لا ينعكس مع dir="rtl"، فلو رُسم من اليسار لقرأ العربيّ
    | الزمن معكوساً — ولانفصل عنه شريط التواريخ الذي ينعكس وحده.
    |
    | ومساحة 20..930 لا 20..980: أرقام المحور تُكتب في الفراغ الباقي،
    | وبلا هذا الهامش تُقصّ خارج viewBox فيصير «25» رقماً هو «2».
    */
    $plotStart = 930;
    $plotWidth = 910;

    $point = fn (int $i, int $value) => [
        round($plotStart - $i / $count * $plotWidth, 1),
        round(280 - $value / $max * 250, 1),
    ];

    $paths = collect($series)->mapWithKeys(fn ($s) => [$s['key'] => $days
        ->map(fn ($d, $i) => implode(',', $point($i, $d[$s['key']])))
        ->implode(' ')]);
@endphp

@section('content')
<x-report-shell title="الحركة اليومية"
                question="ما دخل وما خرج، يوماً بيوم."
                :period="$period"
                basis="كل سلسلة بتاريخها: الإنشاء، والتسليم، والرجوع.">

    <div class="mb-5 grid grid-cols-3 gap-3">
        @foreach ($series as $s)
            <div class="stat">
                <div class="stat-label flex items-center gap-2">
                    {{-- مفتاح خطّي بلون السلسلة؛ النصّ يبقى بحبر النصّ --}}
                    <span class="inline-block h-0.5 w-4 rounded-full" style="background: {{ $s['color'] }}"></span>
                    {{ $s['label'] }}
                </div>
                <div class="num mt-1 text-2xl font-bold">{{ number_format($days->sum($s['key'])) }}</div>
                <div class="text-xs text-ink-500">
                    بمعدّل <span class="num">{{ number_format(round($days->sum($s['key']) / max(1, $days->count()), 1), 1) }}</span> يومياً
                </div>
            </div>
        @endforeach
    </div>

    <section class="card mb-5 p-5">
        <h2 class="card-title mb-4">الاتّجاه خلال {{ \App\Support\Arabic::days($days->count()) }}</h2>

        <div class="relative" id="daily-chart">
            <svg viewBox="0 0 1000 300" class="w-full" style="height: 18rem" role="img"
                 aria-label="الحركة اليومية: أُنشئت ووصلت ورجعت">
                {{-- شبكة خافتة بخطّ شعري متّصل --}}
                @foreach ([0, 0.25, 0.5, 0.75, 1] as $step)
                    @php $y = round(280 - $step * 250, 1); @endphp
                    <line x1="20" y1="{{ $y }}" x2="930" y2="{{ $y }}"
                          stroke="var(--color-ink-200)" stroke-width="1"></line>
                    <text x="938" y="{{ $y + 4 }}" font-size="11" fill="var(--color-ink-500)"
                          text-anchor="start">{{ number_format(round($max * $step)) }}</text>
                @endforeach

                @foreach ($series as $s)
                    <polyline points="{{ $paths[$s['key']] }}" fill="none" stroke="{{ $s['color'] }}"
                              stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                @endforeach

                {{-- علامة النهاية بحلقة بلون السطح حتى تبقى مقروءة فوق خطّ آخر --}}
                @foreach ($series as $s)
                    @php [$x, $y] = $point($days->count() - 1, $days->last()[$s['key']]); @endphp
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="{{ $s['color'] }}"
                            stroke="white" stroke-width="2"></circle>
                @endforeach

                <line id="daily-crosshair" y1="20" y2="280" stroke="var(--color-ink-400)"
                      stroke-width="1" style="display: none"></line>
                <rect x="20" y="20" width="910" height="260" fill="transparent" id="daily-hit"></rect>
            </svg>

            <div id="daily-tip" hidden
                 class="pointer-events-none absolute z-10 rounded-lg border border-ink-200 bg-white px-3 py-2
                        text-xs shadow-lg"></div>
        </div>

        {{-- الشريط ينعكس مع الصفحة، فأوّله على اليمين كأوّل الرسم --}}
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 pe-16 text-xs text-ink-500">
            <span>{{ $days->first()['label'] ?? '' }}</span>
            <span>{{ $days->last()['label'] ?? '' }}</span>
        </div>
    </section>

    {{-- جدول القيم: كل ما يقوله التلميح مقروء بلا تحويم --}}
    <section class="card overflow-hidden">
        <h2 class="card-title border-b border-ink-100 px-5 py-4">القيم يوماً بيوم</h2>
        <div class="max-h-96 overflow-y-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>اليوم</th>
                        @foreach ($series as $s)<th>{{ $s['label'] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days->reverse() as $day)
                        <tr>
                            <td class="num">{{ $day['day'] }}</td>
                            @foreach ($series as $s)
                                <td class="num {{ $day[$s['key']] ? '' : 'text-ink-400' }}">
                                    {{ number_format($day[$s['key']]) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- بيانات الرسم لمؤشّره (resources/js/daily-chart.js): بياناتٌ لا سكربت --}}
    <script type="application/json" id="daily-data">@json(['days' => $days->values(), 'series' => $series])</script>
</x-report-shell>
@endsection

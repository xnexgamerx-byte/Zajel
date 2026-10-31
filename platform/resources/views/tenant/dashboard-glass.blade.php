@extends('layouts.app')
@section('title', 'الرئيسية')

{{--
  رئيسية «زجاجيّ» (docs/plan/100) كما في صورتها: العنوان وأفعاله، وثلاثة أرقام، ثم الشحنات خلال الشهر،
  والمبالغ المستلمة، والمناديب على الطريق، وتتبّع آخر شحنةٍ خرجت. كلّها من النظام (DesignBoard).
--}}
@section('content')
@php
    $days = collect($b['days']);
    $top = max(1, (int) $days->max('count'));
    $hot = $days->search(fn ($d) => $d['count'] === $days->max('count'));
    $money = $b['money'];
    // تدريج المقياس: ٤٧ خطّاً على نصف دائرة، ليمونيّةٌ حتى القيمة
    $fill = $money ? $money['fill'] : (($b['rate'] ?? 0) / 100);
    $ticks = collect(range(0, 46))->map(function (int $i) use ($fill) {
        $a = M_PI * (1 - $i / 46);

        return [
            sprintf('%.1f', 120 + 92 * cos($a)), sprintf('%.1f', 128 - 92 * sin($a)),
            sprintf('%.1f', 120 + 112 * cos($a)), sprintf('%.1f', 128 - 112 * sin($a)),
            $i / 46 <= $fill,
        ];
    });
@endphp
<div class="ds2">
    <section class="ds2-title">
        <div>
            <h1>نظرة على التوصيل اليوم</h1>
            <p>
                @if ($b['yesterday_rate'] !== null) سلّمتم <span class="num">{{ $b['yesterday_rate'] }}%</span> من شحنات الأمس. @endif
                وأمامكم اليوم <span class="num">{{ number_format($b['on_road']) }}</span> شحنة على الطريق.
            </p>
        </div>
        <div class="ds2-actions">
            <span class="ds2-when"><x-icon name="calendar" class="size-5"/><span><small>المدّة</small><b>{{ \App\Services\Dashboard\DesignBoard::MONTHS[now()->month] }} {{ now()->year }}</b></span></span>
            <span class="ds2-sep"></span>
            <a href="{{ route('shipments.index') }}">كل الشحنات</a>
            @can('shipments.create')
                <a href="{{ route('shipments.create') }}" class="ds2-cta">+ شحنة جديدة</a>
            @endcan
        </div>
    </section>

    <section class="ds2-layout">
        <div class="ds2-kpis">
            <a href="{{ route('shipments.index') }}" class="ds2-kpi"><span class="ds2-bub"><x-icon name="truck" class="size-5"/></span><span><b class="num">{{ number_format($b['today']) }}</b><small>شحنات اليوم</small></span></a>
            <span class="ds2-kpi"><span class="ds2-bub"><x-icon name="clock" class="size-5"/></span><span><b class="num">{{ $b['rate'] === null ? '—' : $b['rate'].'%' }}</b><small>نسبة التسليم (٣٠ يوماً)</small></span></span>
            <a href="{{ route('shipments.index', ['status' => 'out_for_delivery']) }}" class="ds2-kpi"><span class="ds2-bub"><x-icon name="exchange" class="size-5"/></span><span><b class="num">{{ number_format($b['on_road']) }}</b><small>قيد التوصيل</small></span></a>
        </div>

        <div class="ds2-mid">
            <div class="ds2-card ds2-gauge">
                <h3><x-icon name="chart" class="size-4"/> {{ $money ? 'المبالغ المستلمة' : 'التسليم' }}</h3>
                <svg viewBox="0 0 240 140" aria-hidden="true">
                    @foreach ($ticks as [$x1, $y1, $x2, $y2, $on])
                        <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" class="{{ $on ? 'ds2-on' : 'ds2-off' }}" stroke-width="3.4" stroke-linecap="round"/>
                    @endforeach
                </svg>
                <div class="ds2-val">
                    @if ($money)
                        <small>هذا الأسبوع</small>
                        <b class="num">{{ number_format($money['value']) }}</b>
                        <div class="ds2-trend">
                            @if ($money['change'] !== null)<b dir="ltr">{{ $money['change'] >= 0 ? '↗ +' : '↘ ' }}{{ $money['change'] }}%</b> عن الأسبوع الماضي · @endif د.ع
                        </div>
                    @else
                        <small>سُلّمت هذا الأسبوع</small>
                        <b class="num">{{ number_format($b['delivered_week']) }}</b>
                        <div class="ds2-trend">نسبة التسليم <b class="num">{{ $b['rate'] ?? '—' }}%</b></div>
                    @endif
                </div>
            </div>

            <a href="{{ route('couriers.tracking') }}" class="ds2-card ds2-truck">
                <h3><x-icon name="truck" class="size-4"/> مناديب على الطريق</h3>
                <svg class="ds2-van" viewBox="0 0 320 170" aria-hidden="true">
                    <defs><linearGradient id="ds2-box" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#dfe3e2"/></linearGradient>
                        <linearGradient id="ds2-cab" x1="0" x2="1"><stop offset="0" stop-color="#f4f6f5"/><stop offset="1" stop-color="#cfd5d3"/></linearGradient></defs>
                    <ellipse cx="160" cy="150" rx="150" ry="10" fill="rgb(0 0 0/.18)"/>
                    <path d="M110 18 L300 6 L306 120 L112 128 Z" fill="url(#ds2-box)" stroke="#c9cfcd"/>
                    <path d="M110 18 L112 128 L96 130 L92 26 Z" fill="#cdd3d1"/>
                    <path d="M92 40 L40 46 Q22 48 18 70 L12 112 L14 134 L96 132 Z" fill="url(#ds2-cab)" stroke="#b9c0be"/>
                    <path d="M36 52 L82 48 L84 88 L22 92 Q26 60 36 52 Z" fill="#3b4744"/>
                    <rect x="14" y="104" width="10" height="6" rx="2" fill="#f2f77a"/>
                    <path d="M12 118 L96 116 L96 134 L14 136 Z" fill="#9aa5a2"/>
                    <rect x="104" y="122" width="204" height="10" rx="3" fill="#8a9592"/>
                    <g fill="#2a302e"><circle cx="54" cy="136" r="17"/><circle cx="220" cy="134" r="17"/><circle cx="262" cy="133" r="17"/></g>
                    <g fill="#b7bfbc"><circle cx="54" cy="136" r="7"/><circle cx="220" cy="134" r="7"/><circle cx="262" cy="133" r="7"/></g>
                </svg>
                <span class="ds2-onroad"><i></i> <span class="num">{{ number_format($b['couriers_on_road']) }}</span> مندوباً على الطريق</span>
            </a>
        </div>

        <div class="ds2-card ds2-live">
            <h3><x-icon name="pin" class="size-4"/> تتبّع المناديب</h3>
            <a href="{{ route('couriers.tracking') }}" class="ds2-expand" aria-label="تتبّع المناديب"><x-icon name="arrow" class="size-4 rotate-45"/></a>
            <div class="ds2-route">
                <svg viewBox="0 0 300 250" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M268 22 C 250 70, 150 60, 140 100 S 120 150, 60 160 C 40 165, 46 205, 50 228" fill="none" stroke="var(--ui-lime)" stroke-width="5" stroke-linecap="round"/>
                    <circle cx="268" cy="22" r="6" fill="#fff" stroke="#c9cfcd" stroke-width="2"/>
                    <circle cx="50" cy="228" r="9" fill="var(--ui-lime-deep)" stroke="#fff" stroke-width="3"/>
                    <path d="M146 96 l18 -10 l-6 18 l-3 -6 z" fill="var(--ui-ink)" stroke="var(--ui-lime)" stroke-width="2" stroke-linejoin="round"/>
                </svg>
            </div>
            @if ($t = $b['tracking'])
                <a href="{{ $t['url'] }}" class="ds2-track">
                    <span class="ds2-id"><span><small>رقم الوصل</small><b><span class="num">{{ $t['number'] }}</span>{{ $t['governorate'] ? ' · '.$t['governorate'] : '' }}</b></span><span class="ds2-st"><i></i> قيد التوصيل</span></span>
                    <span class="ds2-steps">
                        @foreach ($t['steps'] as $i => $step)
                            <span class="ds2-step {{ $i === 0 ? 'ds2-now' : '' }}"><span><small>{{ $step['status'] }}</small><b>{{ $step['text'] }}</b></span><time class="num">{{ $step['at']?->format('h:i') }} {{ $step['at']?->format('A') === 'AM' ? 'ص' : 'م' }}</time></span>
                        @endforeach
                    </span>
                </a>
            @else
                <p class="ds2-empty">لا شحنة مع المناديب الآن.</p>
            @endif
        </div>

        <div class="ds2-card ds2-stats">
            <h3><x-icon name="chart" class="size-4"/> الشحنات خلال الشهر</h3>
            <a href="{{ route('shipments.index', ['status' => 'delivered']) }}" class="ds2-expand" aria-label="الشحنات المسلّمة"><x-icon name="arrow" class="size-4 rotate-45"/></a>
            <div class="ds2-legend"><span><i class="ds2-bar-dot"></i>المسلّمة كل ٣ أيام</span><span><i class="ds2-hot-dot"></i>أعلاها</span></div>
            <div class="ds2-plot">
                <div class="ds2-lines"></div>
                <div class="ds2-axis num">@foreach ($top < 4 ? [$top, '', '', '', 0] : array_map(fn ($tk) => number_format((int) round($top * $tk)), [1, .75, .5, .25, 0]) as $tk)<span>{{ $tk }}</span>@endforeach</div>
                @foreach ($days as $i => $d)
                    <div class="ds2-col {{ $i === $hot && $d['count'] > 0 ? 'ds2-hot' : '' }}" title="{{ number_format($d['count']) }}"><i style="height: {{ max(3, round(100 * $d['count'] / $top)) }}%"></i><small class="num">{{ $d['label'] }}</small></div>
                @endforeach
            </div>
        </div>
    </section>
</div>
@endsection

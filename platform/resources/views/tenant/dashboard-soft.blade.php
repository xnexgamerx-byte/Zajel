@extends('layouts.app')
@section('title', 'الرئيسية')

{{--
  رئيسية «بطاقات فاتحة» (docs/plan/100) كما في صورتها: البحث الكبير وثلاثة أرقامٍ بتغيّرها، ثم أداء التوصيل،
  وللمعالجة، وآخر ملاحظة، وأكثر المحافظات، ثم المسلَّمة بالأشهر ومواعيد الاستلام. كلّها من النظام (DesignBoard).
--}}
@section('content')
@php
    $pct = fn (?int $n) => $n === null ? null : (($n >= 0 ? '+' : '').$n.'%');
    $months = collect($b['months']);
    $top = max(1, (int) $months->max('count'));
    $hot = $months->search(fn ($m) => $m['count'] === $months->max('count'));
    $rate = $b['performance']['rate'];
    // نصف دائرة المقياس: قوسٌ من ١٨٠° بقدر النسبة، والإبرة إلى طرفه
    $f = min(1, max(0, ($rate ?? 0) / 100));
    $arc = fn (float $f) => sprintf('%.1f %.1f', 110 - 90 * cos(M_PI * $f), 118 - 90 * sin(M_PI * $f));
    $needle = sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f',
        110 - 64 * cos(M_PI * $f), 118 - 64 * sin(M_PI * $f),
        110 - 7 * sin(M_PI * $f), 118 + 7 * cos(M_PI * $f),
        110 + 7 * sin(M_PI * $f), 118 - 7 * cos(M_PI * $f));
    // المحور: أرقامٌ صحيحة لا تتكرّر، وفي القليل الأعلى والصفر وحدهما
    $axis = fn (int $top) => $top < 4 ? [$top, '', '', '', 0] : array_map(fn ($t) => number_format((int) round($top * $t)), [1, .75, .5, .25, 0]);
@endphp
<div class="ds1">
    <section class="ds1-hero">
        <form method="GET" action="{{ route('shipments.index') }}" role="search" class="ds1-search">
            <label for="ds1-q" class="sr-only">ابحث برقم الوصل أو هاتف الزبون</label>
            <input id="ds1-q" name="q" placeholder="ابحث برقم الوصل…" autocomplete="off" enterkeyhint="search">
            <button type="submit" class="ds1-round" aria-label="ابحث"><x-icon name="search" class="size-5"/></button>
        </form>
        <div class="ds1-stats">
            @foreach ([['شحنات هذا الشهر', $b['stats']['month']], ['التجّار النشطون', $b['stats']['merchants']], ['قيد التوصيل الآن', $b['stats']['on_road']]] as [$label, $stat])
                <div class="ds1-stat">
                    <small>{{ $label }}</small>
                    <b class="num">{{ number_format($stat['value']) }}</b>
                    @if ($stat['change'] !== null)
                        <span dir="ltr" class="ds1-chip {{ $stat['change'] >= 0 ? 'ds1-up' : 'ds1-down' }}">{{ $pct($stat['change']) }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="ds1-grid">
        <div class="ds1-card">
            <h3>أداء التوصيل</h3>
            <a href="{{ route('couriers.tracking') }}" class="ds1-corner" aria-label="تتبّع المناديب"><x-icon name="plus" class="size-4"/></a>
            <div class="ds1-perf">
                <div>
                    <small class="ds1-muted">متوسّط زمن التسليم:</small>
                    <div class="mt-1.5">
                        <span class="ds1-big num">{{ $b['performance']['hours'] ?? '—' }}</span>
                        @if ($rate !== null)
                            <span class="ds1-fast">{{ $rate >= 80 ? '▲ سريع' : ($rate >= 60 ? 'متوسّط' : '▼ بطيء') }}</span>
                        @endif
                    </div>
                    <small class="font-semibold">ساعة · تسليم <span class="num">{{ $rate === null ? '—' : $rate.'%' }}</span> في ٣٠ يوماً</small>
                </div>
                <svg viewBox="0 0 220 130" class="ds1-gauge" role="img" aria-label="نسبة التسليم {{ $rate ?? 0 }}%">
                    <path d="M20 118 A90 90 0 0 1 200 118" fill="none" class="ds1-track" stroke-width="34"/>
                    @if ($rate)
                        <path d="M20 118 A90 90 0 0 1 {{ $arc($f) }}" fill="none" class="ds1-fill" stroke-width="34"/>
                    @endif
                    <polygon points="{{ $needle }}" fill="var(--color-aeblack-950)"/>
                </svg>
            </div>
            <div class="ds1-channels">
                @php
                    $bubbles = [
                        ['tickets', 'clipboard', 'طلبات المناديب', $b['channels']['tickets'], 'tickets.index'],
                        ['merchants', 'chat', 'محادثات التجّار', $b['channels']['merchants'], 'conversations.index'],
                        ['staff', 'users', 'رسائل الموظّفين', $b['channels']['staff'], 'staff-chat.index'],
                    ];
                @endphp
                @foreach ($bubbles as [$key, $icon, $label, $count, $route])
                    @if ($count !== null)
                        <a href="{{ route($route) }}" class="ds1-ch" title="{{ $label }}" aria-label="{{ $label }}: {{ $count }}">
                            @if ($count > 0)<span class="ds1-n num">{{ $count }}</span>@endif
                            <x-icon :name="$icon" class="size-6"/>
                        </a>
                    @endif
                @endforeach
                @if ($b['channels']['couriers'] !== null)
                    <a href="{{ route('courier-chat.index') }}" class="ds1-msg">
                        <span class="ds1-msg-logo"><x-icon name="truck" class="size-5"/></span>
                        <span>
                            <b>رسائل المناديب</b>
                            <span class="ds1-tag num">{{ $b['channels']['couriers'] }}</span><small class="ds1-muted">رسالة تنتظر</small>
                        </span>
                        <span class="ds1-round ds1-round-sm ms-auto"><x-icon name="arrow" class="size-4 rotate-45"/></span>
                    </a>
                @endif
            </div>
        </div>

        <a href="{{ route('processing.index') }}" class="ds1-card ds1-orange">
            <h3>للمعالجة</h3>
            <span class="ds1-corner"><x-icon name="arrow" class="size-4 -rotate-45"/></span>
            <span class="ds1-btn"><i>+</i> قرّر الآن</span>
            <span class="ds1-foot"><b class="num">{{ number_format($b['processing']) }}</b><small>شحنة لم تُسلَّم<br>تنتظر القرار</small></span>
        </a>

        <div class="ds1-card ds1-notes">
            <h3>ملاحظات<br>الموظّفين</h3>
            <a href="{{ route('shipments.index') }}" class="ds1-corner" aria-label="الشحنات"><x-icon name="plus" class="size-4"/></a>
            <div class="ds1-sheet">
                @if ($note = $b['note'])
                    <div class="flex items-center justify-between"><span class="ds1-when"><x-icon name="clock" class="size-3"/> {{ $note['at']?->diffForHumans() }}</span><span>⋯</span></div>
                    <p>{{ $note['text'] }}</p>
                    <a href="{{ $note['url'] }}" class="ds1-who"><i></i> {{ $note['merchant'] }} · <span class="num">{{ $note['number'] }}</span></a>
                @else
                    <p class="ds1-muted">لا ملاحظات على الشحنات بعد.</p>
                @endif
            </div>
        </div>

        <div class="ds1-card ds1-map">
            <svg class="ds1-map-bg" viewBox="0 0 300 260" preserveAspectRatio="none" aria-hidden="true">
                <g fill="none" stroke="#e3e3e6" stroke-width="7"><path d="M-10 70 L120 40 L320 90"/><path d="M60 -10 L90 120 L40 280"/><path d="M180 -10 L170 140 L240 280"/><path d="M-10 190 L130 160 L320 210"/></g>
                <g fill="none" stroke="#ececee" stroke-width="3"><path d="M120 40 L170 140"/><path d="M90 120 L260 120"/><path d="M20 140 L130 160"/></g>
            </svg>
            <h3 class="relative">أكثر المحافظات<br>شحنات</h3>
            <a href="{{ route('shipments.index') }}" class="ds1-corner" aria-label="الشحنات"><x-icon name="arrow" class="size-4 rotate-45"/></a>
            <span class="ds1-pin"><b></b></span>
            <div class="ds1-place">
                <span class="ds1-thumb"></span>
                @if ($g = $b['governorate'])
                    <b class="num">{{ $g['share'] }}%</b><small>{{ $g['name'] }}<br>من المفتوحة</small>
                @else
                    <small>لا شحنات مفتوحة</small>
                @endif
                <span class="ds1-go"><x-icon name="arrow" class="size-3.5 -rotate-45 text-white"/></span>
            </div>
        </div>
    </section>

    <section class="ds1-row2">
        <div class="ds1-card">
            <div class="ds1-head">
                <h3>الشحنات المسلّمة</h3>
                <div class="flex gap-2"><span class="ds1-select">آخر ٨ أشهر</span>
                    <a href="{{ route('shipments.index', ['status' => 'delivered']) }}" class="ds1-round" aria-label="الشحنات المسلّمة"><x-icon name="upload" class="size-4"/></a></div>
            </div>
            <div class="ds1-bars">
                <div class="ds1-axis num">@foreach ($axis($top) as $t)<span>{{ $t }}</span>@endforeach</div>
                @foreach ($months as $i => $m)
                    <div class="ds1-bar {{ $i === $hot && $m['count'] > 0 ? 'ds1-hot' : '' }}" title="{{ $m['label'] }}: {{ number_format($m['count']) }}">
                        <i style="height: {{ max(4, round(100 * $m['count'] / $top)) }}%">@if ($i === $hot && $m['count'] > 0)<span class="ds1-tip num">{{ number_format($m['count']) }}</span>@endif</i>
                        <small>{{ $m['label'] }}</small>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="ds1-card ds1-sched">
            <div class="ds1-head">
                <h3>مواعيد الاستلام</h3>
                <div class="flex gap-2"><span class="ds1-select">{{ \App\Services\Dashboard\DesignBoard::MONTHS[now()->month] }} {{ now()->year }}</span>
                    <a href="{{ route('pickups.index') }}" class="ds1-round" aria-label="طلبات الاستلام"><x-icon name="plus" class="size-4"/></a></div>
            </div>
            <div class="ds1-days">
                <span></span>
                @foreach ($b['pickups']['days'] as $day)<span class="{{ $day['today'] ? 'ds1-on' : '' }}">{{ $day['label'] }}</span>@endforeach
            </div>
            <div class="ds1-slots">
                @foreach (\App\Services\Dashboard\DesignBoard::SLOTS as $s => [$slotLabel])
                    <small class="num">{{ $slotLabel }}</small>
                    @foreach ($b['pickups']['days'] as $day)
                        <i class="ds1-dot ds1-{{ $day['slots'][$s] }}"></i>
                    @endforeach
                @endforeach
            </div>
            @if ($next = $b['pickups']['next'])
                <div class="ds1-pop">
                    <a href="{{ $next['url'] }}" class="ds1-x" aria-label="طلبات الاستلام">↗</a>
                    <h4>طلب الاستلام<br>القادم</h4>
                    <div class="ds1-tags"><span>{{ $next['merchant'] }}</span><span class="num">◷ {{ $next['at']->isToday() ? 'اليوم' : \App\Services\Dashboard\DesignBoard::DAYS[$next['at']->dayOfWeek] }} {{ $next['at']->format('g:i') }}</span></div>
                    <div class="ds1-join">
                        <small>{{ $next['count'] ? \App\Support\Arabic::shipments($next['count']).' · ' : '' }}{{ $next['courier'] ? 'مع '.$next['courier'] : 'بلا مندوب بعد' }}</small>
                        <a href="{{ $next['url'] }}" class="ds1-plus" aria-label="افتح الطلب">+</a>
                    </div>
                </div>
            @endif
        </div>
    </section>
</div>
@endsection

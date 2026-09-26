@extends('layouts.app')
@section('title', 'لوحة اليوم')

@section('content')
@php
    // التحية بساعة بغداد، والتوقيت المخزَّن UTC
    $hour = now('Asia/Baghdad')->hour;
    $greeting = $hour >= 4 && $hour < 12 ? 'صباح الخير' : 'مساء الخير';
@endphp
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <p class="mb-1 text-sm text-ink-500">{{ $greeting }}، {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</p>
        <h1 class="page-title">لوحة اليوم</h1>
        <p class="page-sub">{{ now()->translatedFormat('l j F Y') }}</p>
    </div>
    <a href="{{ route('shipments.index') }}" class="btn-ghost">كل الشحنات</a>
</div>

@php
    $daily = collect($week);
    $canCreate = auth()->user()->can('shipments.create');
@endphp

{{--
  الصفّ الأوّل كبطاقات الصورة المرجعية: رقم اليوم الأهمّ في بطاقةٍ مرجانية
  بخطّ أيامه السبعة، وحركة الشحنات الجديدة «مصّاصاتٍ»، ودعوةٌ إلى شحنةٍ جديدة
  بحدٍّ متقطّع. الأيام من اليمين: أقدمها أوّلاً واليوم آخرها، مع اتجاه القراءة.
--}}
<div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-12">
    <a href="{{ route('shipments.index', ['status' => 'delivered']) }}"
       class="glow-card rise flex flex-col gap-4 transition hover:-translate-y-0.5 lg:col-span-5" style="--i: 0">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="text-[15px] font-medium text-white/90">سُلّمت اليوم</div>
                <div class="display-num num mt-3 text-[64px]">{{ number_format($cards['delivered_today']) }}</div>
            </div>
            <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-medium">
                خلال ٧ أيام: <span class="num">{{ number_format($daily->sum('delivered')) }}</span>
            </span>
        </div>

        @php
            // نقطة كل يومٍ في وسط عموده من سبعة، فتقع تحتها تسميته تماماً
            $peak = max(1, (int) $daily->max('delivered'));
            $points = $daily->values()->map(fn ($day, $i) => [
                (6 - $i + 0.5) * 40,
                round(54 - $day['delivered'] / $peak * 44, 1),
            ]);
            $line = $points->map(fn ($p) => implode(',', $p))->implode(' ');
        @endphp
        <div class="mt-auto">
            <svg viewBox="0 0 280 64" class="h-auto w-full overflow-visible" role="img"
                 aria-label="التسليم في الأيام السبعة الأخيرة: {{ $daily->map(fn ($d) => \App\Support\Arabic::weekday($d['date']).' '.$d['delivered'])->implode('، ') }}">
                <polygon points="{{ $line }} 20,64 260,64" fill="white" fill-opacity="0.12"/>
                <polyline points="{{ $line }}" fill="none" stroke="white" stroke-width="2.25"
                          stroke-linecap="round" stroke-linejoin="round"/>
                @foreach ($points as [$x, $y])
                    @if ($loop->last)
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="7" fill="white" fill-opacity="0.3"/>
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="white"/>
                    @else
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="2.5" fill="white"/>
                    @endif
                @endforeach
            </svg>
            <div class="mt-2 grid grid-cols-7 text-center text-[11px] text-white/75">
                @foreach ($daily as $day)
                    <span class="{{ $loop->last ? 'font-semibold text-white' : '' }}">{{ $loop->last ? 'اليوم' : \App\Support\Arabic::weekday($day['date']) }}</span>
                @endforeach
            </div>
        </div>
    </a>

    <section class="card rise flex flex-col gap-4 p-6 {{ $canCreate ? 'lg:col-span-4' : 'lg:col-span-7' }}" style="--i: 1">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="card-title">شحنات جديدة</h2>
                <p class="card-hint">خلال ٧ أيام: <span class="num">{{ number_format($daily->sum('created')) }}</span></p>
            </div>
            <a href="{{ route('shipments.index') }}" class="icon-btn bg-primary-50 text-primary-700" aria-label="كل الشحنات" title="كل الشحنات">
                <x-icon name="arrow" class="size-4 rtl:-scale-x-100"/>
            </a>
        </div>

        <div class="flex items-baseline gap-2">
            <span class="display-num num text-[44px] text-primary-600">{{ number_format($cards['today']) }}</span>
            <span class="text-sm text-ink-500">أُنشئت اليوم</span>
        </div>

        @php
            $most = (int) $daily->max('created');
            $busiest = $most > 0 ? $daily->search(fn ($d) => $d['created'] === $most) : null;
        @endphp
        {{-- «مصّاصة» لكل يوم: عودٌ ورأس، وأكثر الأيام مرجانيٌّ بعدده --}}
        <div class="mt-auto">
            <div class="grid h-32 grid-cols-7 items-end border-b border-dashed border-aeblack-200">
                @foreach ($daily as $i => $day)
                    <div class="flex h-full flex-col items-center justify-end"
                         title="{{ $day['date']->translatedFormat('l j F') }}: {{ \App\Support\Arabic::shipments($day['created']) }}">
                        @if ($i === $busiest)
                            <span class="num mb-1.5 rounded-full bg-primary-600 px-2 py-0.5 text-[11px] font-medium text-white">{{ number_format($most) }}</span>
                        @endif
                        <span class="size-2.5 shrink-0 rounded-full {{ $i === $busiest ? 'bg-primary-600 ring-4 ring-primary-100' : 'bg-aeblack-700' }}"></span>
                        <span class="w-px {{ $i === $busiest ? 'bg-primary-500' : 'bg-aeblack-300' }}"
                              style="height: {{ max(4, (int) round($day['created'] / max(1, $most) * 72)) }}px"></span>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 grid grid-cols-7 text-center text-[11px] text-ink-500">
                @foreach ($daily as $day)
                    <span class="{{ $loop->last ? 'font-semibold text-aeblack-800' : '' }}">{{ $loop->last ? 'اليوم' : \App\Support\Arabic::weekday($day['date']) }}</span>
                @endforeach
            </div>
        </div>
    </section>

    @if ($canCreate)
        <div class="dash-card rise md:col-span-2 lg:col-span-3" style="--i: 2">
            <a href="{{ route('shipments.create') }}" class="grid justify-items-center gap-3">
                <span class="dash-card-plus"><x-icon name="plus" class="size-6"/></span>
                <span class="font-heading text-base">شحنة جديدة</span>
            </a>
            <a href="{{ route('shipments.import') }}" class="text-xs font-normal text-primary-700 hover:underline">
                أو ارفع دفعةً من ملف
            </a>
        </div>
    @endif
</div>

{{-- ما بقي من أسئلة الصباح: أين الشحنات المفتوحة الآن --}}
<div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ([
        ['مع المندوبين', $cards['with_couriers'], ['status' => 'out_for_delivery'], 'truck'],
        ['في المخزن والنقل', $cards['at_hub'], ['status' => 'at_hub'], 'building'],
        ['متعثّرة', $cards['stuck'], ['status' => 'failed_attempt'], 'alert'],
        ['قيد التنفيذ', $cards['open'], null, 'clock'],
    ] as $i => [$label, $value, $filter, $icon])
        @php $tag = $filter ? 'a' : 'div'; @endphp
        <{{ $tag }} @if ($filter) href="{{ route('shipments.index', $filter) }}" @endif class="kpi rise" style="--i: {{ 3 + $i }}">
            <span class="kpi-icon"><x-icon :name="$icon" class="size-6"/></span>
            <div class="min-w-0">
                <div class="kpi-value num">{{ number_format($value) }}</div>
                <div class="kpi-label">{{ $label }}</div>
            </div>
            @if ($filter)
                <x-icon name="arrow" class="ms-auto size-4 shrink-0 text-ink-400 rtl:-scale-x-100"/>
            @endif
        </{{ $tag }}>
    @endforeach
</div>

@php
    /*
    | العمر يرافق المبلغ. «نقد بيد المندوبين ٨٫٦ مليون» يبدو دورة عمل
    | طبيعية؛ «وأقدمه منذ ٣٧ يوماً» يقول إن أحداً لم يُسوِّ حساباً منذ
    | شهر. الرقم الأول وحده لا يدفع أحداً لفعل شيء.
    */
    $age = fn (?int $days) => match (true) {
        $days === null => ['—', 'chip-mute'],
        $days === 0    => ['اليوم', 'chip-mute'],
        $days >= 14    => ['أقدمه منذ '.\App\Support\Arabic::days($days), 'chip-bad'],
        $days >= 7     => ['أقدمه منذ '.\App\Support\Arabic::days($days), 'chip-warn'],
        default        => ['أقدمه منذ '.\App\Support\Arabic::days($days), 'chip-mute'],
    };
@endphp

<div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-3">
    @foreach ([
        ['مبالغ لم تُحصَّل', $cards['cod_open'], 'text-warn-700', 'على شحنات قيد التنفيذ', null, 'wallet'],
        ['نقد بيد المندوبين', $cards['cash_in_hand'], 'text-bad-700', 'لم يُسلَّم للشركة', $aging['cod_oldest_days'], 'cash'],
        ['مستحقّ للتجّار', $cards['owed_merchants'], 'text-ink-900', 'لم يُدفَع بعد', $aging['merchant_oldest_days'], 'store'],
    ] as $i => [$label, $value, $tone, $hint, $days, $icon])
        @php [$ageLabel, $ageTone] = $age($days); @endphp
        <div class="card rise flex flex-col gap-3 px-6 py-5" style="--i: {{ 7 + $i }}">
            <div class="flex items-center justify-between gap-3">
                <span class="text-[15px] font-medium">{{ $label }}</span>
                <span class="grid size-10 place-items-center rounded-full bg-primary-50 text-primary-600"><x-icon :name="$icon" class="size-5"/></span>
            </div>
            <div class="{{ $tone }}">
                <span class="display-num num text-[34px]">{{ number_format($value) }}</span>
                <span class="text-sm text-ink-500">د.ع</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-ink-500">{{ $hint }}</span>
                @if ($days !== null && $value > 0)
                    <span class="chip {{ $ageTone }}">{{ $ageLabel }}</span>
                @endif
            </div>
        </div>
    @endforeach
</div>

@if ($aging['stale_shipments'] > 0)
    {{-- تنبيهٌ تحذيريّ: جملة الفعل، ثم زرّ دائريّ مرجانيّ يفتحها --}}
    <a href="{{ route('shipments.index', ['status' => 'failed_attempt']) }}"
       class="mb-5 flex items-center gap-4 rounded-3xl bg-camel-50 px-5 py-4 ring-1 ring-camel-200 transition hover:-translate-y-0.5 hover:bg-camel-100">
        <span class="grid size-11 shrink-0 place-items-center rounded-full bg-camel-100 text-camel-700">
            <x-icon name="clock" class="size-6"/>
        </span>
        <div class="min-w-0 flex-1">
            {{-- الصيغة تحمل عددها: «شحنتان» و«٧ شحنات» و«١٢ شحنة» --}}
            <div class="font-heading text-[20px] font-medium text-aeblack-950">{{ \App\Support\Arabic::shipments($aging['stale_shipments']) }}</div>
            <div class="mt-0.5 text-[15px] text-aeblack-700">
                لم تتغيّر حالتها منذ أكثر من {{ \App\Support\Arabic::days($aging['stale_after']) }}
                — كل يوم تأخير يزيد احتمال الراجع.
            </div>
        </div>
        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-primary-600 text-white">
            <x-icon name="arrow" class="size-4 rtl:-scale-x-100"/>
        </span>
    </a>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section>
            <div class="panel-head">
                <span class="panel-head-icon"><x-icon name="alert" class="size-5"/></span>
                <h2 class="panel-head-title">شحنات متعثّرة</h2>
                <a href="{{ route('shipments.index', ['status' => 'failed_attempt']) }}"
                   class="ms-auto text-sm font-medium text-primary-600 hover:text-primary-500 hover:underline">الكل</a>
            </div>
            <p class="card-hint mb-3 mt-2.5 px-1">
                الأقدم أولاً — كل يوم تأخير يرفع احتمال أن تصير راجعة.
            </p>

            @if ($stuck->isEmpty())
                <p class="card py-8 text-center text-sm text-ink-500">لا شيء متعثّر. يوم جيد.</p>
            @else
                <div class="space-y-1.5">
                    @foreach ($stuck as $shipment)
                        <a href="{{ route('shipments.show', $shipment) }}" class="row-link flex-wrap">
                            <span class="font-mono text-sm font-semibold" dir="ltr">
                                {{ $shipment->number }}
                            </span>
                            <span class="h-px w-2.5 bg-ink-300" aria-hidden="true"></span>
                            <span class="min-w-28 flex-1 truncate">{{ $shipment->recipient_name }}</span>
                            @if ($shipment->lastFailureReason)
                                <span class="chip chip-warn">
                                    {{ $shipment->lastFailureReason->name_ar }}
                                </span>
                            @endif
                            <span class="text-xs text-ink-500">
                                {{ $shipment->deliveryCourier?->name ?? 'بلا مندوب' }}
                            </span>
                            <span class="text-xs text-ink-400" dir="ltr">
                                {{ $shipment->status_changed_at?->diffForHumans() }}
                            </span>
                            <x-icon name="arrow" class="size-4 shrink-0 rtl:-scale-x-100"/>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="card-title">قيد التنفيذ حسب المحافظة</h2>
            <p class="card-hint mb-5">سلسلة واحدة بلون واحد — الطول وحده يحمل المقدار.</p>

            @if ($byGovernorate->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">لا شحنات قيد التنفيذ.</p>
            @else
                @php $max = max(1, (int) $byGovernorate->max('c')); @endphp

                <div class="space-y-3">
                    @foreach ($byGovernorate as $row)
                        <div class="group flex items-center gap-3"
                             title="{{ $row->name }}: {{ \App\Support\Arabic::shipments((int) $row->c) }} قيد التنفيذ">
                            <span class="w-24 shrink-0 truncate text-sm text-ink-700">{{ $row->name }}</span>

                            {{-- عمودٌ مرجانيّ على مسارٍ باهت: الطول وحده يحمل المقدار --}}
                            <div class="h-2 flex-1 rounded-full bg-primary-50">
                                <div class="h-full rounded-full bg-linear-to-l from-primary-600 to-primary-400 transition-opacity group-hover:opacity-80"
                                     style="width: {{ max(2, round($row->c / $max * 100)) }}%"></div>
                            </div>

                            <span class="num w-12 shrink-0 text-end text-sm font-semibold text-ink-900">
                                {{ number_format($row->c) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        @if ($cards['pending_pickups'])
            <a href="{{ route('pickups.index') }}"
               class="kpi kpi-accent items-start px-5 py-4">
                <span class="kpi-icon"><x-icon name="clipboard" class="size-6"/></span>
                <div class="min-w-0">
                    <div class="text-sm font-medium">طلبات استلام تنتظر</div>
                    <div class="kpi-value num mt-1.5">{{ $cards['pending_pickups'] }}</div>
                    <div class="mt-1.5 text-xs text-white/85">تجّار جهّزوا طرودهم ولم يُسنَد لهم مندوب.</div>
                </div>
            </a>
        @endif

        <section>
            <div class="panel-head">
                <span class="panel-head-icon"><x-icon name="cash" class="size-5"/></span>
                <h2 class="panel-head-title">تجاوزوا سقف النقد</h2>
            </div>
            <p class="card-hint mb-3 mt-2.5 px-1">سوِّ معهم قبل إسناد شحنات جديدة.</p>

            @if ($overCashLimit->isEmpty())
                <p class="card py-6 text-center text-sm text-ink-500">لا أحد تجاوز سقفه.</p>
            @else
                <div class="space-y-1.5">
                    @foreach ($overCashLimit as $courier)
                        <a href="{{ route('settlements.couriers.index') }}" class="row-link justify-between">
                            <span class="font-medium">{{ $courier->name }}</span>
                            <span class="flex items-center gap-2">
                                <span class="num font-semibold text-bad-700" dir="ltr">
                                    {{ number_format($courier->cash_in_hand) }}
                                </span>
                                <x-icon name="arrow" class="size-4 shrink-0 rtl:-scale-x-100"/>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
@endsection

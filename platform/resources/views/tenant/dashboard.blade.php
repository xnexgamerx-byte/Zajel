@extends('layouts.app')
@section('title', 'لوحة اليوم')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">لوحة اليوم</h1>
        <p class="page-sub">{{ now()->translatedFormat('l j F Y') }}</p>
    </div>
    <a href="{{ route('shipments.index') }}" class="btn-ghost">كل الشحنات</a>
</div>

{{-- ستّ بطاقات: أسئلة الصباح كلّها في نظرة — بطاقات مؤشّر التصميم، والأولى بارزة --}}
<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ([
        ['أُنشئت اليوم', $cards['today'], null, 'plus'],
        ['سُلّمت اليوم', $cards['delivered_today'], null, 'check'],
        ['مع المندوبين', $cards['with_couriers'], ['status' => 'out_for_delivery'], 'truck'],
        ['في المخزن والنقل', $cards['at_hub'], ['status' => 'at_hub'], 'building'],
        ['متعثّرة', $cards['stuck'], ['status' => 'failed_attempt'], 'alert'],
        ['قيد التنفيذ', $cards['open'], null, 'clock'],
    ] as $i => [$label, $value, $filter, $icon])
        @php $tag = $filter ? 'a' : 'div'; @endphp
        <{{ $tag }} @if ($filter) href="{{ route('shipments.index', $filter) }}" @endif
            class="kpi {{ $i === 0 ? 'kpi-accent' : '' }}">
            <span class="kpi-icon"><x-icon :name="$icon" class="size-6"/></span>
            <div class="min-w-0">
                <div class="kpi-value num">{{ number_format($value) }}</div>
                <div class="kpi-label">{{ $label }}</div>
            </div>
            @if ($filter)
                <x-icon name="arrow" class="ms-auto size-4 shrink-0 text-ink-500 rtl:-scale-x-100"/>
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

<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-3">
    @foreach ([
        ['مبالغ لم تُحصَّل', $cards['cod_open'], 'text-warn-700', 'على شحنات قيد التنفيذ', null, 'wallet'],
        ['نقد بيد المندوبين', $cards['cash_in_hand'], 'text-bad-700', 'لم يُسلَّم للشركة', $aging['cod_oldest_days'], 'cash'],
        ['مستحقّ للتجّار', $cards['owed_merchants'], 'text-ink-900', 'لم يُدفَع بعد', $aging['merchant_oldest_days'], 'store'],
    ] as [$label, $value, $tone, $hint, $days, $icon])
        @php [$ageLabel, $ageTone] = $age($days); @endphp
        <div class="card flex flex-col gap-3 px-5 py-4">
            <div class="flex items-center justify-between gap-3">
                <span class="text-[15px] font-medium">{{ $label }}</span>
                <span class="grid size-9 place-items-center rounded-full border border-ink-900"><x-icon :name="$icon" class="size-[18px]"/></span>
            </div>
            <div class="text-[32px] leading-none font-semibold {{ $tone }}">
                <span class="num">{{ number_format($value) }}</span>
                <span class="text-sm font-normal text-ink-500">د.ع</span>
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
    {{-- بطاقة التصميم الزيتونية: جملة الفعل، ثم زرّ دائريّ داكن يفتحها --}}
    <a href="{{ route('shipments.index', ['status' => 'failed_attempt']) }}"
       class="mb-5 flex items-center gap-4 rounded-[24px] border border-ink-900 bg-sage px-5 py-4 transition hover:brightness-[0.97]">
        <div class="min-w-0 flex-1">
            {{-- الصيغة تحمل عددها: «شحنتان» و«٧ شحنات» و«١٢ شحنة» --}}
            <div class="text-[20px] font-semibold">{{ \App\Support\Arabic::shipments($aging['stale_shipments']) }}</div>
            <div class="mt-0.5 text-[15px]">
                لم تتغيّر حالتها منذ أكثر من {{ \App\Support\Arabic::days($aging['stale_after']) }}
                — كل يوم تأخير يزيد احتمال الراجع.
            </div>
        </div>
        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ink-900 text-white">
            <x-icon name="arrow" class="size-4 rtl:-scale-x-100"/>
        </span>
    </a>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section>
            <div class="panel-head">
                <span class="panel-head-icon bg-amber"><x-icon name="alert" class="size-4"/></span>
                <h2 class="panel-head-title">شحنات متعثّرة</h2>
                <a href="{{ route('shipments.index', ['status' => 'failed_attempt']) }}"
                   class="ms-auto text-sm font-medium text-sun hover:underline">الكل</a>
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
                            <span class="h-px w-2.5 bg-ink-900" aria-hidden="true"></span>
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

                            {{-- أعمدة التصميم: حبرٌ على مسارٍ لافنديّ، ونهايتها وحدها مدوّرة --}}
                            <div class="h-3 flex-1 rounded-full bg-lilac-soft">
                                <div class="h-full rounded-full bg-ink-900 transition group-hover:bg-ink-700"
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
                    <div class="mt-1.5 text-xs text-ink-700">تجّار جهّزوا طرودهم ولم يُسنَد لهم مندوب.</div>
                </div>
            </a>
        @endif

        <section>
            <div class="panel-head">
                <span class="panel-head-icon bg-lilac"><x-icon name="cash" class="size-4"/></span>
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

@extends('layouts.app')
@section('title', 'لوحة اليوم')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">لوحة اليوم</h1>
        <p class="mt-1 text-sm text-ink-500">{{ now()->translatedFormat('l j F Y') }}</p>
    </div>
    <a href="{{ route('shipments.index') }}" class="btn-ghost">كل الشحنات</a>
</div>

{{-- ستّ بطاقات: أسئلة الصباح كلّها في سطر واحد --}}
<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
    @foreach ([
        ['أُنشئت اليوم', $cards['today'], null],
        ['سُلّمت اليوم', $cards['delivered_today'], null],
        ['مع المندوبين', $cards['with_couriers'], ['status' => 'out_for_delivery']],
        ['في المخزن والنقل', $cards['at_hub'], ['status' => 'at_hub']],
        ['متعثّرة', $cards['stuck'], ['status' => 'failed_attempt']],
        ['قيد التنفيذ', $cards['open'], null],
    ] as [$label, $value, $filter])
        @if ($filter)
            <a href="{{ route('shipments.index', $filter) }}" class="stat">
                <div class="stat-label">{{ $label }}</div>
                <div class="stat-value">{{ number_format($value) }}</div>
            </a>
        @else
            <div class="stat">
                <div class="stat-label">{{ $label }}</div>
                <div class="stat-value">{{ number_format($value) }}</div>
            </div>
        @endif
    @endforeach
</div>

@php
    /*
    | العمر يرافق المبلغ. «نقد بيد المندوبين ٨٫٦ مليون» يبدو دورة عمل
    | طبيعية؛ «وأقدمه منذ ٣٧ يوماً» يقول إن أحداً لم يُسوِّ حساباً منذ
    | شهر. الرقم الأول وحده لا يدفع أحداً لفعل شيء.
    */
    $age = fn (?int $days) => match (true) {
        $days === null => ['—', 'text-ink-400'],
        $days === 0    => ['اليوم', 'text-ink-500'],
        $days >= 14    => ['أقدمه منذ '.\App\Support\Arabic::days($days), 'text-bad-700 font-semibold'],
        $days >= 7     => ['أقدمه منذ '.\App\Support\Arabic::days($days), 'text-warn-700 font-semibold'],
        default        => ['أقدمه منذ '.\App\Support\Arabic::days($days), 'text-ink-500'],
    };
@endphp

<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-3">
    @foreach ([
        ['مبالغ لم تُحصَّل', $cards['cod_open'], 'text-warn-700', 'على شحنات قيد التنفيذ', null],
        ['نقد بيد المندوبين', $cards['cash_in_hand'], 'text-bad-700', 'لم يُسلَّم للشركة', $aging['cod_oldest_days']],
        ['مستحقّ للتجّار', $cards['owed_merchants'], 'text-ink-900', 'لم يُدفَع بعد', $aging['merchant_oldest_days']],
    ] as [$label, $value, $tone, $hint, $days])
        @php [$ageLabel, $ageTone] = $age($days); @endphp
        <div class="stat">
            <div class="stat-label">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">
                <span class="num">{{ number_format($value) }}</span>
                <span class="text-sm font-medium text-ink-500">د.ع</span>
            </div>
            <div class="mt-0.5 flex flex-wrap items-baseline gap-x-2 text-xs">
                <span class="text-ink-400">{{ $hint }}</span>
                @if ($days !== null && $value > 0)
                    <span class="{{ $ageTone }}">{{ $ageLabel }}</span>
                @endif
            </div>
        </div>
    @endforeach
</div>

@if ($aging['stale_shipments'] > 0)
    <a href="{{ route('shipments.index', ['status' => 'failed_attempt']) }}"
       class="card mb-5 flex flex-wrap items-center gap-3 border-warn-200 bg-warn-50 p-4 text-sm
              text-warn-700 transition hover:border-warn-700">
        {{-- الصيغة تحمل عددها: «شحنتان» و«٧ شحنات» و«١٢ شحنة» --}}
        <span class="text-xl font-bold">{{ \App\Support\Arabic::shipments($aging['stale_shipments']) }}</span>
        <span>
            لم تتغيّر حالتها منذ أكثر من {{ \App\Support\Arabic::days($aging['stale_after']) }}
            — كل يوم تأخير يزيد احتمال الراجع.
        </span>
    </a>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <div class="mb-1 flex items-center justify-between">
                <h2 class="card-title">شحنات متعثّرة</h2>
                <a href="{{ route('shipments.index', ['status' => 'failed_attempt']) }}"
                   class="text-sm font-semibold text-[var(--brand)] hover:underline">الكل</a>
            </div>
            <p class="card-hint mb-4">
                الأقدم أولاً — كل يوم تأخير يرفع احتمال أن تصير راجعة.
            </p>

            @if ($stuck->isEmpty())
                <p class="py-8 text-center text-sm text-ink-500">لا شيء متعثّر. يوم جيد.</p>
            @else
                <div class="divide-y divide-ink-100">
                    @foreach ($stuck as $shipment)
                        <a href="{{ route('shipments.show', $shipment) }}"
                           class="flex flex-wrap items-center gap-3 py-2.5 hover:bg-ink-50">
                            <span class="font-mono text-sm font-semibold text-[var(--brand)]" dir="ltr">
                                {{ $shipment->number }}
                            </span>
                            <span class="min-w-28 flex-1 truncate text-sm">{{ $shipment->recipient_name }}</span>
                            @if ($shipment->lastFailureReason)
                                <span class="rounded-full bg-warn-50 px-2 py-0.5 text-xs font-medium text-warn-700">
                                    {{ $shipment->lastFailureReason->name_ar }}
                                </span>
                            @endif
                            <span class="text-xs text-ink-500">
                                {{ $shipment->deliveryCourier?->name ?? 'بلا مندوب' }}
                            </span>
                            <span class="text-xs text-ink-400" dir="ltr">
                                {{ $shipment->status_changed_at?->diffForHumans() }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="card-title">قيد التنفيذ حسب المحافظة</h2>
            <p class="card-hint mb-4">سلسلة واحدة بلون واحد — الطول وحده يحمل المقدار.</p>

            @if ($byGovernorate->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">لا شحنات قيد التنفيذ.</p>
            @else
                @php $max = max(1, (int) $byGovernorate->max('c')); @endphp

                <div class="space-y-2.5">
                    @foreach ($byGovernorate as $row)
                        <div class="group flex items-center gap-3"
                             title="{{ $row->name }}: {{ \App\Support\Arabic::shipments((int) $row->c) }} قيد التنفيذ">
                            <span class="w-24 shrink-0 truncate text-sm text-ink-600">{{ $row->name }}</span>

                            {{-- القضيب رفيع ونهايته وحدها مدوّرة، وقاعدته مربّعة عند خطّ الأساس --}}
                            <div class="h-2.5 flex-1">
                                <div class="h-full rounded-s-none rounded-e-[4px] transition group-hover:brightness-110"
                                     style="width: {{ max(2, round($row->c / $max * 100)) }}%; background: var(--brand)"></div>
                            </div>

                            <span class="num w-10 shrink-0 text-end text-sm font-semibold text-ink-900">
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
               class="card block border-r-4 border-warn-700 p-5 hover:bg-ink-50">
                <div class="text-sm font-bold">طلبات استلام تنتظر</div>
                <div class="mt-1 text-3xl font-bold text-warn-700">{{ $cards['pending_pickups'] }}</div>
                <div class="mt-1 text-xs text-ink-500">تجّار جهّزوا طرودهم ولم يُسنَد لهم مندوب.</div>
            </a>
        @endif

        <section class="card p-5">
            <h2 class="card-title">تجاوزوا سقف النقد</h2>
            <p class="card-hint mb-4">سوِّ معهم قبل إسناد شحنات جديدة.</p>

            @if ($overCashLimit->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">لا أحد تجاوز سقفه.</p>
            @else
                <div class="divide-y divide-ink-100">
                    @foreach ($overCashLimit as $courier)
                        <a href="{{ route('settlements.couriers.index') }}"
                           class="flex items-center justify-between gap-3 py-2.5 hover:bg-ink-50">
                            <span class="text-sm font-medium">{{ $courier->name }}</span>
                            <span class="text-sm font-bold text-bad-700" dir="ltr">
                                {{ number_format($courier->cash_in_hand) }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
@endsection

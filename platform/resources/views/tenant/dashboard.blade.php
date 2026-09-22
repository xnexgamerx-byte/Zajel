@extends('layouts.app')
@section('title', 'لوحة اليوم')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">لوحة اليوم</h1>
        <p class="mt-1 text-sm text-slate-500">{{ now()->translatedFormat('l j F Y') }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('shipments.create') }}" class="btn-primary">+ شحنة</a>
        <a href="{{ route('shipments.index') }}" class="btn-ghost">كل الشحنات</a>
    </div>
</div>

{{-- ستّ بطاقات: أسئلة الصباح كلّها في سطر واحد --}}
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
    @foreach ([
        ['أُنشئت اليوم', $cards['today'], 'text-slate-900', null],
        ['سُلّمت اليوم', $cards['delivered_today'], 'text-emerald-700', null],
        ['مع المندوبين', $cards['with_couriers'], 'text-sky-700', ['status' => 'out_for_delivery']],
        ['في المخزن والنقل', $cards['at_hub'], 'text-slate-700', ['status' => 'at_hub']],
        ['متعثّرة', $cards['stuck'], 'text-amber-700', ['status' => 'failed_attempt']],
        ['قيد التنفيذ', $cards['open'], 'text-brand-700', null],
    ] as [$label, $value, $tone, $filter])
        <a @if ($filter) href="{{ route('shipments.index', $filter) }}" @endif
           class="card block p-4 {{ $filter ? 'hover:ring-brand-300' : '' }}">
            <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </a>
    @endforeach
</div>

<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-3">
    @foreach ([
        ['مبالغ لم تُحصَّل', $cards['cod_open'], 'text-amber-700', 'على شحنات قيد التنفيذ'],
        ['نقد بيد المندوبين', $cards['cash_in_hand'], 'text-red-600', 'لم يُسلَّم للشركة'],
        ['مستحقّ للتجّار', $cards['owed_merchants'], 'text-brand-700', 'لم يُدفَع بعد'],
    ] as [$label, $value, $tone, $hint])
        <div class="card p-4">
            <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}" dir="ltr">
                {{ number_format($value) }} <span class="text-sm font-medium text-slate-500">د.ع</span>
            </div>
            <div class="mt-0.5 text-xs text-slate-400">{{ $hint }}</div>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <div class="mb-1 flex items-center justify-between">
                <h2 class="text-sm font-bold">شحنات متعثّرة</h2>
                <a href="{{ route('shipments.index', ['status' => 'failed_attempt']) }}"
                   class="text-sm font-semibold text-brand-700 hover:underline">الكل</a>
            </div>
            <p class="mb-4 text-xs text-slate-500">
                الأقدم أولاً — كل يوم تأخير يرفع احتمال أن تصير راجعة.
            </p>

            @if ($stuck->isEmpty())
                <p class="py-8 text-center text-sm text-slate-500">لا شيء متعثّر. يوم جيد.</p>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($stuck as $shipment)
                        <a href="{{ route('shipments.show', $shipment) }}"
                           class="flex flex-wrap items-center gap-3 py-2.5 hover:bg-slate-50">
                            <span class="font-mono text-sm font-semibold text-brand-700" dir="ltr">
                                {{ $shipment->number }}
                            </span>
                            <span class="min-w-28 flex-1 truncate text-sm">{{ $shipment->recipient_name }}</span>
                            @if ($shipment->lastFailureReason)
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800">
                                    {{ $shipment->lastFailureReason->name_ar }}
                                </span>
                            @endif
                            <span class="text-xs text-slate-500">
                                {{ $shipment->deliveryCourier?->name ?? 'بلا مندوب' }}
                            </span>
                            <span class="text-xs text-slate-400" dir="ltr">
                                {{ $shipment->status_changed_at?->diffForHumans(short: true) }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">قيد التنفيذ حسب المحافظة</h2>

            @if ($byGovernorate->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">لا شحنات قيد التنفيذ.</p>
            @else
                @php $max = max(1, (int) $byGovernorate->max('c')); @endphp
                <div class="space-y-2">
                    @foreach ($byGovernorate as $row)
                        <div class="flex items-center gap-3">
                            <span class="w-24 shrink-0 text-sm text-slate-600">{{ $row->name }}</span>
                            <div class="h-5 flex-1 overflow-hidden rounded bg-slate-100">
                                <div class="h-full rounded bg-brand-500"
                                     style="width: {{ round($row->c / $max * 100) }}%"></div>
                            </div>
                            <span class="w-10 shrink-0 text-end text-sm font-semibold" dir="ltr">
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
               class="card block border-r-4 border-amber-400 p-5 hover:bg-slate-50">
                <div class="text-sm font-bold">طلبات استلام تنتظر</div>
                <div class="mt-1 text-3xl font-bold text-amber-700">{{ $cards['pending_pickups'] }}</div>
                <div class="mt-1 text-xs text-slate-500">تجّار جهّزوا طرودهم ولم يُسنَد لهم مندوب.</div>
            </a>
        @endif

        <section class="card p-5">
            <h2 class="mb-1 text-sm font-bold">تجاوزوا سقف النقد</h2>
            <p class="mb-4 text-xs text-slate-500">سوِّ معهم قبل إسناد شحنات جديدة.</p>

            @if ($overCashLimit->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">لا أحد تجاوز سقفه.</p>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($overCashLimit as $courier)
                        <a href="{{ route('settlements.couriers.index') }}"
                           class="flex items-center justify-between gap-3 py-2.5 hover:bg-slate-50">
                            <span class="text-sm font-medium">{{ $courier->name }}</span>
                            <span class="text-sm font-bold text-red-600" dir="ltr">
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

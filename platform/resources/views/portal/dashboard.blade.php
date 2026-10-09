@extends('layouts.portal')
@section('title', 'الرئيسية')

@section('content')
@php
    $owed = $merchant->balance >= 0;
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'صباح الخير' : 'مساء الخير';
@endphp
<div class="mb-5">
    <h1 class="page-title">{{ $greeting }}، {{ $merchant->owner_name ?: $merchant->business_name }}</h1>
    <p class="page-sub">وضع شحناتك وحسابك مع {{ $company->name }}.</p>
</div>

{{-- «نبض»: رصيده أوّل ما يراه — بطاقةٌ بلون الشركة، ومنها يطلب المحاسبة أو يفتح كشفه --}}
<section class="glow-card rise mb-4 flex flex-wrap items-end justify-between gap-5" style="--i: 0">
    <div class="min-w-0">
        <div class="text-base font-bold text-white/90">{{ $owed ? 'لك عند الشركة' : 'عليك للشركة' }}</div>
        <div class="mt-2 flex items-baseline gap-2" dir="ltr">
            <span class="display-num num text-[clamp(2.5rem,11vw,4rem)]">{{ number_format(abs($merchant->balance)) }}</span>
            <span class="text-base font-bold text-white/80">د.ع</span>
        </div>
        @if ($unsettled)
            <p class="mt-1 text-sm font-semibold text-white/85">عن {{ \App\Support\Arabic::shipments($unsettled) }} واصلة لم تُحاسَب بعد</p>
        @endif
    </div>
    <div class="flex flex-wrap gap-2">
        @if (\App\Support\FeatureGate::allowsRoute('portal.requests.index'))
            <a href="{{ route('portal.requests.index') }}" class="btn-on-brand">اطلب محاسبة</a>
        @endif
        <a href="{{ route('portal.statement') }}" class="btn-on-brand-ghost">كشف الحساب</a>
    </div>
</section>

<x-app-ads audience="merchants" class="mb-4" />

{{-- الأعداد بحاوياتٍ ملوّنة بمعناها: في الطريق أزرق، والواصل أخضر، وما يحتاجه كهرمانيّ --}}
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ([
        ['قيد التوصيل', $counts['open'], 'bg-info-soft text-info-deep', 'truck', route('portal.shipments.index')],
        ['مسلَّمة', $counts['delivered'], 'bg-ok-soft text-ok-deep', 'check', route('portal.shipments.index', ['status' => 'delivered'])],
        ['تحتاج انتباهك', $attention->count(), 'bg-warn-soft text-warn-deep', 'alert', $merchant->can_process ? route('portal.processing.index') : null],
        ['راجعة', $counts['returned'], 'bg-aeblack-100 text-aeblack-800', 'undo', route('portal.shipments.index', ['status' => 'returned'])],
    ] as $i => [$label, $value, $tone, $icon, $href])
        @php $tag = $href ? 'a' : 'div'; @endphp
        <{{ $tag }} @if ($href) href="{{ $href }}" @endif
            class="kpi kpi-tonal rise {{ $tone }} {{ ['tile-shape-1', 'tile-shape-2', 'tile-shape-3', 'tile-shape-4'][$i] }}" style="--i: {{ 1 + $i }}">
            <span class="kpi-icon max-sm:hidden"><x-icon :name="$icon" class="size-6"/></span>
            <div class="min-w-0">
                <div class="kpi-value num">{{ number_format($value) }}</div>
                <div class="kpi-label">{{ $label }}</div>
            </div>
        </{{ $tag }}>
    @endforeach
</div>
@if ($deliveredToday)
    <p class="-mt-2 mb-5 text-sm font-semibold text-ok-700">وصل اليوم {{ \App\Support\Arabic::shipments($deliveredToday) }}.</p>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        @if ($attention->isNotEmpty())
            <section>
                <div class="mb-2 flex items-end justify-between gap-3 px-1">
                    <div>
                        <h2 class="card-title">تحتاج انتباهك</h2>
                        <p class="card-hint">شحنات تعثّرت. أحياناً مكالمة منك للزبون تحلّ ما لا تحلّه محاولة ثانية.</p>
                    </div>
                    @if ($merchant->can_process)
                        <a href="{{ route('portal.processing.index') }}" class="text-sm font-bold text-primary-800 hover:underline">عالجها</a>
                    @endif
                </div>
                <ul class="list-2l">
                    @foreach ($attention as $shipment)
                        <li>
                            <a href="{{ route('portal.shipments.show', $shipment) }}" class="list-2l-item">
                                <span class="list-2l-icon bg-warn-soft text-warn-deep"><x-icon name="alert" class="size-5"/></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-bold text-aeblack-950">
                                        <span class="num" dir="ltr">{{ $shipment->number }}</span> · {{ $shipment->recipient_name }}
                                    </span>
                                    <span class="block truncate text-sm text-aeblack-600">
                                        {{ $shipment->lastFailureReason?->name_ar ?? $shipment->status->label() }}
                                    </span>
                                </span>
                                <x-status-badge :status="$shipment->status" :shipment="$shipment" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section>
            <div class="mb-2 flex items-center justify-between px-1">
                <h2 class="card-title">آخر شحناتك</h2>
                <a href="{{ route('portal.shipments.index') }}" class="text-sm font-bold text-primary-800 hover:underline">الكل</a>
            </div>

            @if ($recent->isEmpty())
                <div class="card py-10 text-center">
                    <p class="text-ink-500">لم ترسل شحنة بعد.</p>
                    <a href="{{ route('portal.shipments.create') }}" class="btn-primary mt-4">أنشئ أول شحنة</a>
                </div>
            @else
                <ul class="list-2l">
                    @foreach ($recent as $shipment)
                        @php
                            [$icon, $tone] = match (true) {
                                $shipment->status === \App\Enums\ShipmentStatus::Delivered => ['check', 'bg-ok-soft text-ok-deep'],
                                $shipment->status->isOpen() => ['truck', 'bg-info-soft text-info-deep'],
                                default => ['undo', 'bg-aeblack-100 text-aeblack-700'],
                            };
                        @endphp
                        <li>
                            <a href="{{ route('portal.shipments.show', $shipment) }}" class="list-2l-item">
                                <span class="list-2l-icon {{ $tone }}"><x-icon :name="$icon" class="size-5"/></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-bold text-aeblack-950">{{ $shipment->recipient_name }}</span>
                                    <span class="block truncate text-sm text-aeblack-600">
                                        <span class="num" dir="ltr">{{ $shipment->number }}</span> · {{ $shipment->governorate->name_ar }} · {{ $shipment->status->label() }}
                                    </span>
                                </span>
                                <span class="num shrink-0 font-extrabold text-aeblack-950" dir="ltr">{{ number_format($shipment->cod_amount) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">طلب استلام</h2>

            @if ($pickups->isNotEmpty())
                @foreach ($pickups as $pickup)
                    <div class="mb-3 rounded-lg bg-info-50 px-3 py-2.5 text-sm ring-1 ring-info-200">
                        <div class="font-semibold">
                            طلب {{ $pickup->number }} — {{ \App\Support\Arabic::parcels((int) $pickup->expected_count) }}
                        </div>
                        <div class="mt-0.5 text-xs text-info-700">
                            {{ ['pending' => 'بانتظار إسناد مندوب', 'assigned' => 'أُسند لمندوب',
                                'in_progress' => 'المندوب في الطريق'][$pickup->status] ?? $pickup->status }}
                            @if ($pickup->courier) — {{ $pickup->courier->name }} @endif
                        </div>
                    </div>
                @endforeach
            @else
                <form method="POST" action="{{ route('portal.pickups.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="field-label" for="expected_count">كم طرداً جاهز؟</label>
                        <input id="expected_count" name="expected_count" type="number" min="1"
                               class="field-input text-left" dir="ltr" required value="{{ old('expected_count') }}">
                    </div>
                    <div>
                        <label class="field-label" for="scheduled_at">الموعد المفضّل</label>
                        <input id="scheduled_at" name="scheduled_at" type="date" class="field-input"
                               value="{{ old('scheduled_at') }}">
                    </div>
                    <button type="submit" class="btn-primary w-full">اطلب مندوب استلام</button>
                </form>
            @endif

            <a href="{{ route('portal.pickups.index') }}"
               class="mt-3 inline-block text-xs text-[var(--brand)] hover:underline">كل الطلبات</a>
        </section>

        <section class="card p-5">
            <h2 class="mb-3 text-sm font-bold">عنوان الاستلام</h2>
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-ink-500">العنوان</dt>
                    <dd class="font-medium">{{ $merchant->address ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-500">نقطة دالّة</dt>
                    <dd class="font-medium">{{ $merchant->landmark ?: '—' }}</dd>
                </div>
                <div class="border-t border-ink-100 pt-2">
                    <dt class="text-ink-500">دورة التسوية</dt>
                    <dd class="font-medium">
                        {{ ['daily' => 'يومي', 'weekly' => 'أسبوعي', 'biweekly' => 'كل أسبوعين',
                            'monthly' => 'شهري', 'on_demand' => 'عند الطلب'][$merchant->settlement_cycle] ?? '—' }}
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-ink-500">
                لتعديل هذه البيانات راجع {{ $company->name }}.
            </p>
        </section>
    </div>
</div>
@endsection

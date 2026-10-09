@extends('layouts.app')
@section('title', 'التنبيهات التشغيلية')

@use('App\Services\Operations\OperationalAlerts')
@use('App\Support\Arabic')
@use('App\Support\DeliveryDeadline')

@section('content')
<div class="mb-4">
    <h1 class="page-title">التنبيهات التشغيلية</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما مرّ عليه آخر موعدٍ للتوصيل — <span class="font-semibold text-ink-700">{{ Arabic::hours($hours) }}</span> — ولم يُحسم بعد.
        @can('settings.company')
            <a href="{{ route('settings.company') }}" class="text-[var(--brand)] hover:underline">غيّر الموعد</a>
        @endcan
    </p>
</div>

<nav class="tab-nav mb-4" aria-label="التنبيهات التشغيلية">
    @foreach (array_filter([
        'overdue'   => ['تجاوزت موعد التوصيل', number_format($counts['overdue'])],
        'unscanned' => ['لم تُمسح عند نقطة انتقال', number_format($counts['unscanned'])],
        'unsettled' => $money ? ['تحصيلات لم تُسوَّ', number_format($counts['unsettled']).' د.ع'] : null,
    ]) as $key => [$label, $badge])
        <a href="{{ route('operations.alerts', ['tab' => $key]) }}" @class(['tab-link', 'tab-link-active' => $tab === $key])
           @if ($tab === $key) aria-current="page" @endif>
            {{ $label }} <span class="nav-badge num">{{ $badge }}</span>
        </a>
    @endforeach
</nav>

@if ($tab === 'overdue')
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-3xl text-xs leading-6 text-ink-600">
            <span class="font-semibold text-ink-800">الأولوية:</span>
            لكل يومٍ بعد الموعد نقطة (حتى ثلاث)، ونقطةٌ لكلٍّ من: تاجرٌ مميّز، وتاجرٌ سأل عنها في محادثة مفتوحة، ومحاولتان فاشلتان فأكثر،
            ومبلغٌ {{ number_format(OperationalAlerts::BIG_AMOUNT) }} فأكثر. ثلاث نقاطٍ فأكثر <span class="chip chip-bad">عاجلة</span>،
            ونقطةٌ فأكثر <span class="chip chip-warn">مرتفعة</span>.
        </p>
        <div class="flex gap-1 text-sm">
            <a href="{{ route('operations.alerts', ['tab' => 'overdue']) }}" @class(['btn-ghost', 'font-bold text-ink-900' => $sort === 'priority'])>الأهمّ أوّلاً</a>
            <a href="{{ route('operations.alerts', ['tab' => 'overdue', 'sort' => 'late']) }}" @class(['btn-ghost', 'font-bold text-ink-900' => $sort === 'late'])>الأطول تأخيراً</a>
        </div>
    </div>

    <div class="card overflow-hidden">
        @if ($postponed)
            <p class="border-b border-ink-100 px-5 py-2.5 text-xs text-ink-500">
                و{{ Arabic::shipments($postponed) }} أجّلها الزبون إلى يومٍ قادم — لا تُحسب تأخيراً حتى يأتي يومها.
            </p>
        @endif
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>الأولوية</th><th>رقم الوصل</th><th>التاجر</th><th>الحالة</th><th>أين الآن</th><th>استُلمت من التاجر</th><th>متأخرة</th><th>المبلغ</th></tr>
                </thead>
                <tbody>
                    @forelse ($shipments as $shipment)
                        @php
                            [$points, $level, $tone, $reasons] = OperationalAlerts::priority($shipment);
                            $due = DeliveryDeadline::dueAt($shipment);
                            $withCourier = $shipment->deliveryCourier && in_array($shipment->status, [
                                \App\Enums\ShipmentStatus::OutForDelivery, \App\Enums\ShipmentStatus::FailedAttempt, \App\Enums\ShipmentStatus::Postponed,
                            ], true);
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap">
                                <span class="chip {{ $tone }}">{{ $level }}</span>
                                @if ($reasons)
                                    <span class="mt-1 block max-w-48 text-xs leading-5 text-ink-500">{{ implode(' · ', $reasons) }}</span>
                                @endif
                            </td>
                            <td class="num font-semibold"><a href="{{ route('shipments.show', $shipment) }}" class="text-[var(--brand)] hover:underline">{{ $shipment->number }}</a></td>
                            <td>{{ $shipment->merchant?->business_name }}</td>
                            <td><x-status-badge :status="$shipment->status" :shipment="$shipment" /></td>
                            <td class="text-sm">
                                @if ($withCourier)
                                    مع {{ $shipment->deliveryCourier->name }}
                                    @if ($shipment->deliveryCourier->phone)
                                        <a href="tel:{{ $shipment->deliveryCourier->phone }}" class="num block text-xs text-ink-500 hover:underline">{{ $shipment->deliveryCourier->phone }}</a>
                                    @endif
                                @else
                                    {{ $shipment->hub?->name ?? '—' }}
                                @endif
                                <span class="block text-xs text-ink-500">{{ $shipment->governorate?->name_ar }}</span>
                            </td>
                            <td class="num whitespace-nowrap text-xs text-ink-500">{{ $shipment->picked_up_at?->format('Y-m-d H:i') }}</td>
                            <td class="whitespace-nowrap font-bold text-bad-700">
                                {{ Arabic::duration((int) $due?->diffInMinutes(now())) }}
                                <span class="num block text-xs font-normal text-ink-500">الموعد {{ $due?->format('m-d H:i') }}</span>
                            </td>
                            <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-12 text-center text-ink-500">لا شحنة تجاوزت موعد توصيلها.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($shipments->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
        @endif
    </div>
@elseif ($tab === 'unscanned')
    <div class="mb-3 flex flex-wrap gap-2">
        @foreach (OperationalAlerts::CHECKPOINTS as $key => [$label])
            <a href="{{ route('operations.alerts', ['tab' => 'unscanned', 'kind' => $key]) }}"
               @class(['chip', 'chip-info' => $kind === $key, 'chip-mute' => $kind !== $key && ! $checkpoints[$key], 'chip-warn' => $kind !== $key && $checkpoints[$key]])>
                {{ $label }} <span class="num">({{ number_format($checkpoints[$key]) }})</span>
            </a>
        @endforeach
    </div>
    <p class="mb-3 text-xs text-ink-600">
        {{ OperationalAlerts::CHECKPOINTS[$kind][1] }} منذ أكثر من {{ Arabic::hours($hours) }}. تحقّق من مكانها مع المسؤول عنها، ثمّ امسحها في شاشتها.
    </p>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>رقم الوصل</th><th>التاجر</th><th>الحالة</th><th>أين يُفترض أنها</th><th>المسؤول عنها</th><th>منذ</th></tr>
                </thead>
                <tbody>
                    @forelse ($shipments as $shipment)
                        @php
                            $where = OperationalAlerts::whereabouts($shipment, $kind);
                            $since = OperationalAlerts::since($shipment, $kind);
                        @endphp
                        <tr>
                            <td class="num font-semibold"><a href="{{ route('shipments.show', $shipment) }}" class="text-[var(--brand)] hover:underline">{{ $shipment->number }}</a></td>
                            <td>{{ $shipment->merchant?->business_name }}</td>
                            <td><x-status-badge :status="$shipment->status" :shipment="$shipment" /></td>
                            <td @class(['text-sm', 'font-semibold text-bad-700' => $where['missing']])>{{ $where['where'] }}</td>
                            <td class="text-sm">
                                {{ $where['who'] }}
                                @if ($where['phone'])
                                    <a href="tel:{{ $where['phone'] }}" class="num block text-xs text-ink-500 hover:underline">{{ $where['phone'] }}</a>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-sm font-bold text-bad-700">
                                {{ $since ? Arabic::duration((int) $since->diffInMinutes(now())) : '—' }}
                                <span class="num block text-xs font-normal text-ink-500">{{ $since?->format('m-d H:i') }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-ink-500">لا شحنة متوقّفة عند هذه النقطة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($shipments->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
        @endif
    </div>
@else
    <p class="mb-3 text-xs text-ink-600">
        مبالغ شحناتٍ سُلّمت قبل أكثر من {{ Arabic::hours($hours) }} وما زالت بيد المندوب — حسب فرعه، أقدمها أوّلاً.
        «كشف المندوب» يفتح محاسبته.
    </p>

    @forelse ($branches as $branch => $rows)
        <section class="card mb-4 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-5 py-3">
                <h2 class="card-title">{{ $branch }}</h2>
                <span class="text-sm text-ink-600">
                    {{ Arabic::shipments($rows->sum('shipments')) }} ·
                    <span class="num font-bold text-ink-900">{{ number_format($rows->sum('amount')) }} د.ع</span>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>المندوب</th><th>الشحنات</th><th>المبلغ</th><th>أقدمها</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $oldest = \Illuminate\Support\Carbon::parse($row->oldest); @endphp
                            <tr>
                                <td>
                                    {{ $row->courier }}
                                    @if ($row->phone)
                                        <a href="tel:{{ $row->phone }}" class="num block text-xs text-ink-500 hover:underline">{{ $row->phone }}</a>
                                    @endif
                                </td>
                                <td class="num">{{ number_format($row->shipments) }}</td>
                                <td class="num font-bold">{{ number_format($row->amount) }}</td>
                                <td class="whitespace-nowrap text-sm">
                                    <span class="font-semibold text-bad-700">{{ Arabic::duration((int) $oldest->diffInMinutes(now())) }}</span>
                                    <span class="num block text-xs text-ink-500">{{ $oldest->format('Y-m-d') }}</span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('settlements.couriers.index', ['courier_id' => $row->courier_id]) }}" class="btn-ghost text-sm">كشف المندوب ←</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="card px-4 py-12 text-center text-ink-500">كل ما حُصّل قبل الموعد سُلّم وسُوّي.</div>
    @endforelse
@endif
@endsection

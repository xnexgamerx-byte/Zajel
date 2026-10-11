@extends('layouts.app')
@section('title', 'تتبّع المناديب')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">تتبّع المناديب</h1>
        <p class="mt-1 text-sm text-ink-500">
            أين كلّ مندوبٍ الآن من موقع هاتفه — يُرسله كلّ دقيقةٍ ما دامت شاشته مفتوحة — وآخر شحنةٍ تحدّثت على يده.
        </p>
    </div>
    <form method="GET" class="flex gap-2">
        <input name="q" value="{{ $search }}" class="field-input w-56" placeholder="اسم المندوب أو هاتفه أو رمزه" aria-label="بحث">
        <button class="btn-ghost">بحث</button>
    </form>
</div>

{{-- الخريطة (docs/plan/62): كلّ مندوبٍ عند موقع هاتفه، أو تقريبياً عند محافظة شحنته إن لم يُرسل هاتفه شيئاً --}}
<section class="card mb-5 overflow-hidden">
    <div data-map data-refresh="60" class="h-[26rem] w-full bg-ink-50"
         data-center='@json($map['center'])' data-zoom="{{ $map['zoom'] }}" data-points='@json($map['points'])'
         aria-label="خريطة المناديب"></div>
    <p class="flex flex-wrap gap-4 border-t border-ink-100 px-4 py-2 text-xs text-ink-500">
        <span class="font-semibold text-ink-700">يرسل موقعه الآن: {{ number_format($map['live']) }}</span>
        <span><span class="inline-block size-2.5 rounded-full bg-[var(--brand)]"></span> موقع هاتف المندوب</span>
        <span><span class="inline-block size-2.5 rounded-full bg-warn-500"></span> آخر موقعٍ من هاتفه قديم: أغلق الشاشة منذ أكثر من {{ \App\Support\CourierLocation::QUIET_MINUTES }} دقيقة</span>
        <span><span class="inline-block size-2.5 rounded-full border-2 border-ink-400"></span> تقريبيّ: هاتفه لم يُرسل موقعه بعد</span>
    </p>
</section>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>المندوب</th>
                    <th>موقع الهاتف</th>
                    <th>آخر شحنة</th>
                    <th>حالتها</th>
                    <th>المنطقة</th>
                    <th>متى</th>
                    <th>بيده الآن</th>
                    <th>وصّل اليوم</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $quiet = $row->status_changed_at->lt(now()->subHours(3)); @endphp
                    <tr>
                        <td>
                            <span class="font-semibold">{{ $row->deliveryCourier?->name }}</span>
                            @if ($row->deliveryCourier?->phone)
                                <a href="tel:{{ $row->deliveryCourier->phone }}" class="num block text-xs text-[var(--brand)]" dir="ltr">{{ $row->deliveryCourier->phone }}</a>
                            @endif
                        </td>
                        @php $seenAt = $row->deliveryCourier?->last_seen_at; @endphp
                        <td class="whitespace-nowrap text-xs">
                            @if (! $seenAt)
                                <span class="text-ink-400">لم يُرسل</span>
                            @elseif ($seenAt->gt(now()->subMinutes(\App\Support\CourierLocation::LIVE_MINUTES)))
                                <span class="chip chip-ok">الآن</span>
                            @else
                                <span class="{{ $seenAt->lt(now()->subMinutes(\App\Support\CourierLocation::QUIET_MINUTES)) ? 'text-warn-700' : 'text-ink-600' }}"
                                      title="{{ $seenAt->timezone('Asia/Baghdad')->format('Y-m-d H:i') }}">{{ $seenAt->locale('ar')->diffForHumans() }}</span>
                            @endif
                        </td>
                        <td><a href="{{ route('shipments.show', $row) }}" class="num font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $row->number }}</a></td>
                        <td><x-status-badge :status="$row->status" /></td>
                        <td>{{ collect([$row->governorate?->name_ar, $row->city?->name_ar])->filter()->implode(' · ') ?: '—' }}</td>
                        <td class="whitespace-nowrap {{ $quiet ? 'text-warn-700' : 'text-ink-600' }}">
                            <span title="{{ $row->status_changed_at->timezone('Asia/Baghdad')->format('Y-m-d H:i') }}">{{ $row->status_changed_at->locale('ar')->diffForHumans() }}</span>
                        </td>
                        <td class="num font-semibold">{{ number_format($inHand[$row->delivery_courier_id] ?? 0) }}</td>
                        <td class="num">{{ number_format($today[$row->delivery_courier_id] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-ink-500">لا حركة لمندوبٍ بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
        «موقع الهاتف»: متى أرسل هاتفه مكانه آخر مرّة — يُرسله ما دامت شاشة المندوب مفتوحة. و«متى» آخر شحنةٍ حدّثها،
        ومن لم يحدّث شيئاً منذ ثلاث ساعات بلونٍ آخر.
    </p>
</div>
@endsection

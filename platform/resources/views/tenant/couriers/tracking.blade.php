@extends('layouts.app')
@section('title', 'تتبّع المناديب')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">تتبّع المناديب</h1>
        <p class="mt-1 text-sm text-ink-500">
            أين كلّ مندوبٍ الآن، من آخر شحنةٍ تحدّثت على يده: حالتها ومنطقتها ومتى — الأحدث حركةً أوّلاً.
        </p>
    </div>
    <form method="GET" class="flex gap-2">
        <input name="q" value="{{ $search }}" class="field-input w-56" placeholder="اسم المندوب أو هاتفه أو رمزه" aria-label="بحث">
        <button class="btn-ghost">بحث</button>
    </form>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>المندوب</th>
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
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-500">لا حركة لمندوبٍ بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
        الموقع تقريبيّ: منطقة آخر شحنةٍ حدّثها المندوب. ومن لم يحدّث شيئاً منذ ثلاث ساعات بلونٍ آخر.
    </p>
</div>
@endsection

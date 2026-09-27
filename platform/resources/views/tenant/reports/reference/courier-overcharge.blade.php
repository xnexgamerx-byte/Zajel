@extends('layouts.app')
@section('title', 'حوسب المندوب بتكلفة أعلى')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'حوسب المندوب بتكلفة أعلى', 'blurb' => 'وصولاتٌ حصّة مندوبها فيها أكبر من أجرة توصيلها — خسارةٌ على كل تسليم، تُراجَع أجرة المندوب أو التسعيرة. '.$period->label()])

<x-report-period :period="$period">
    <div class="min-w-40">
        <label class="field-label" for="courier_id">مندوب التوصيل</label>
        <select id="courier_id" name="courier_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="settled">محاسبة المندوب</label>
        <select id="settled" name="settled" class="field-input">
            <option value="">الكل</option>
            <option value="yes" @selected(request('settled') === 'yes')>تمّت المحاسبة</option>
            <option value="no" @selected(request('settled') === 'no')>لم تتمّ</option>
        </select>
    </div>
</x-report-period>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="card p-4"><div class="text-xs text-ink-500">الوصولات</div><div class="num mt-1 text-2xl font-bold">{{ number_format((int) ($totals->shipments ?? 0)) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-500">حصص المندوبين عليها</div><div class="num mt-1 text-2xl font-bold">{{ number_format((int) ($totals->commission ?? 0)) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-500">الفرق فوق أجرتها</div><div class="num mt-1 text-2xl font-bold text-bad-700">{{ number_format((int) ($totals->commission ?? 0) - (int) ($totals->fees ?? 0)) }}</div></div>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>رقم الوصل</th><th>مندوب التوصيل</th><th>حصّة المندوب</th><th>من فرع</th><th>إلى</th><th>أجرة التوصيل</th><th>الفرق</th><th>محاسبة المندوب</th></tr></thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    @php $fee = (int) $shipment->delivery_fee + (int) $shipment->extra_fee; @endphp
                    <tr>
                        <td class="num font-semibold"><a href="{{ route('shipments.show', $shipment) }}" class="text-[var(--brand)] hover:underline">{{ $shipment->number }}</a></td>
                        <td>{{ $shipment->deliveryCourier?->name ?? '—' }}</td>
                        <td class="num">{{ number_format($shipment->courier_commission) }}</td>
                        <td class="text-sm">{{ $shipment->branch?->name ?? '—' }}</td>
                        <td class="text-sm">{{ $shipment->governorate?->name_ar }}</td>
                        <td class="num">{{ number_format($fee) }}</td>
                        <td class="num font-semibold text-bad-700">{{ number_format($shipment->courier_commission - $fee) }}</td>
                        <td>@if ($shipment->courier_settled_at)<span class="chip chip-ok">تمّت المحاسبة</span>@else<span class="chip chip-mute">لم تتمّ</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-ink-500">لا وصل حوسب عليه مندوبه فوق أجرته في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
    @endif
</div>
@endsection

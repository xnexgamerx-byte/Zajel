@extends('layouts.print', ['back' => $back])
@section('title', 'رواجع '.$merchant->business_name)
@section('docTitle', 'كشف رواجع تاجر')
@section('subtitle', $merchant->business_name.' — '.($from || $to ? trim(($from ? 'من '.$from->format('Y-m-d') : '').' '.($to ? 'إلى '.$to->format('Y-m-d') : '')) : 'كل ما استلمه'))

@section('content')
<div class="mb-5 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">التاجر</span><span>{{ $merchant->business_name }} <span class="num">{{ $merchant->code }}</span></span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">هاتفه</span><span class="num">{{ $merchant->phone }}</span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">عدد الرواجع</span><span class="num">{{ number_format($returns->count()) }}</span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">مجموع أجرة الراجع</span>
        <span class="num">{{ number_format($returns->sum(fn ($s) => $s->wasDelivered() ? 0 : (int) $s->return_fee)) }}</span></div>
</div>

<table class="tbl w-full">
    <thead>
        <tr>
            <th class="w-8">#</th>
            <th>رقم الوصل</th>
            <th>المستلم</th>
            <th>الوجهة</th>
            <th>سبب الرجوع</th>
            <th>الإيصال</th>
            <th>استلمه في</th>
            <th>أجرة الراجع</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($returns as $i => $shipment)
            <tr>
                <td class="num text-ink-500">{{ $i + 1 }}</td>
                <td class="num font-semibold">{{ $shipment->number }}</td>
                <td>{{ $shipment->recipient_name }}</td>
                <td>{{ $shipment->governorate?->name_ar }}{{ $shipment->city ? ' · '.$shipment->city->name_ar : '' }}</td>
                <td>{{ $shipment->returnReason() ?? '—' }}</td>
                <td class="num">{{ $shipment->returnBatch?->number ?? '—' }}</td>
                <td class="num">{{ $shipment->returned_at?->format('Y-m-d') }}</td>
                <td class="num">{{ number_format($shipment->wasDelivered() ? 0 : $shipment->return_fee) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-8 text-center text-ink-500">لا رواجع استلمها في هذه المدّة.</td></tr>
        @endforelse
    </tbody>
</table>

<div class="mt-10 grid grid-cols-2 gap-10 text-sm">
    <div class="border-t border-ink-900 pt-2 text-center">توقيع التاجر</div>
    <div class="border-t border-ink-900 pt-2 text-center">توقيع الموظّف</div>
</div>
@endsection

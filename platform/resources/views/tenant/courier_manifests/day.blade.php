@extends('layouts.print', ['back' => route('courier-manifests.index', ['courier_id' => $courier->id])])
@section('title', 'كشف '.$courier->name.' — '.$day->format('Y-m-d'))
@section('docTitle', 'كشف مندوب توصيل')
@section('subtitle', $courier->name.' — '.$courier->phone.' — '.$day->format('Y-m-d'))

@section('content')
<div class="mb-5 grid grid-cols-3 gap-3 text-center">
    <div class="rounded-lg border border-ink-300 p-3">
        <div class="text-xs text-ink-600">عدد الشحنات</div>
        <div class="num text-xl font-bold">{{ number_format($totals->shipments) }}</div>
    </div>
    <div class="rounded-lg border border-ink-300 p-3">
        <div class="text-xs text-ink-600">مبالغها (د.ع)</div>
        <div class="num text-xl font-bold">{{ number_format($totals->cod) }}</div>
    </div>
    <div class="rounded-lg border-2 border-ink-900 p-3">
        <div class="text-xs text-ink-600">المحصَّل منها (د.ع)</div>
        <div class="num text-xl font-black">{{ number_format($totals->collected) }}</div>
    </div>
</div>

@if ($buckets->isNotEmpty())
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach ($labels as $key => $label)
            @if ($buckets[$key] ?? 0)
                <span class="rounded border border-ink-300 px-2 py-0.5">{{ $label }}: <b class="num">{{ $buckets[$key] }}</b></span>
            @endif
        @endforeach
    </div>
@endif

@if ($shipments->isEmpty())
    <p class="py-10 text-center text-ink-500">لا شحنات في كشف هذا اليوم.</p>
@else
    <table class="tbl w-full">
        <thead>
            <tr>
                <th class="w-8">#</th>
                <th>رقم الوصل</th>
                <th>التاجر</th>
                <th>المستلم</th>
                <th>الوجهة</th>
                <th>المبلغ</th>
                <th>ما صارت إليه</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($shipments as $i => $shipment)
                @php $bucket = \App\Services\Couriers\CourierManifests::bucketOf($shipment, $courier->id); @endphp
                <tr>
                    <td class="num text-ink-500">{{ $i + 1 }}</td>
                    <td class="num font-semibold">
                        <a href="{{ route('shipments.show', $shipment) }}" class="print:no-underline">{{ $shipment->number }}</a>
                    </td>
                    <td>{{ $shipment->merchant?->business_name }}</td>
                    <td>{{ $shipment->recipient_name }}</td>
                    <td>{{ $shipment->governorate?->name_ar }}{{ $shipment->city ? ' · '.$shipment->city->name_ar : '' }}</td>
                    <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                    <td>
                        {{ $labels[$bucket] }}
                        @if ($bucket === 'other')
                            <span class="text-xs text-ink-500">({{ $shipment->statusLabel() }}{{ $shipment->deliveryCourier && (int) $shipment->delivery_courier_id !== $courier->id ? ' — مع '.$shipment->deliveryCourier->name : '' }})</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="mt-10 grid grid-cols-2 gap-10 text-sm">
    <div class="border-t border-ink-900 pt-2 text-center">توقيع المندوب</div>
    <div class="border-t border-ink-900 pt-2 text-center">توقيع الموظّف</div>
</div>
@endsection

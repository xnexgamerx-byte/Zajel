@extends('layouts.print')
@section('title', 'كشف المندوب '.$courier->name)
@section('docTitle', 'كشف عهدة مندوب')
@section('subtitle', $courier->name.' — '.$courier->phone)

@section('content')
<div class="mb-5 grid grid-cols-3 gap-3 text-center">
    <div class="rounded-lg border border-ink-300 p-3">
        <div class="text-xs text-ink-600">عدد الشحنات</div>
        <div class="num text-xl font-bold">{{ number_format($totals->shipments) }}</div>
    </div>
    <div class="rounded-lg border border-ink-300 p-3">
        <div class="text-xs text-ink-600">عدد القطع</div>
        <div class="num text-xl font-bold">{{ number_format($totals->pieces) }}</div>
    </div>
    <div class="rounded-lg border-2 border-ink-900 p-3">
        <div class="text-xs text-ink-600">إجمالي المبالغ (د.ع)</div>
        <div class="num text-xl font-black">{{ number_format($totals->cod) }}</div>
    </div>
</div>

@if ($shipments->isEmpty())
    <p class="py-10 text-center text-ink-500">لا شحنات بيد هذا المندوب الآن.</p>
@else
    <table class="tbl w-full">
        <thead>
            <tr>
                <th class="w-8">#</th>
                <th>رقم الوصل</th>
                <th>المستلم</th>
                <th>الهاتف</th>
                <th>العنوان</th>
                <th>التاجر</th>
                <th>المبلغ</th>
                <th class="w-20">التسليم</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($shipments as $i => $shipment)
                <tr>
                    <td class="num text-ink-500">{{ $i + 1 }}</td>
                    <td class="num font-semibold">{{ $shipment->number }}</td>
                    <td>{{ $shipment->recipient_name }}</td>
                    {{--
                      الهاتف مكشوف هنا وحده: الورقة بيد المندوب الذي
                      سيتّصل بكل رقم فيها. والإخفاء على الشاشة يمنع نسخ
                      قائمة زبائن، ولا يمنع مندوباً من عمله.
                    --}}
                    <td class="num">{{ $shipment->recipient_phone }}</td>
                    <td class="max-w-56 text-xs">
                        <span class="font-medium">{{ $shipment->governorate?->name_ar }}</span>
                        @if ($shipment->city)<span class="text-ink-600"> — {{ $shipment->city->name_ar }}</span>@endif
                        <span class="block text-ink-600">{{ $shipment->address }}</span>
                    </td>
                    <td class="max-w-32 truncate text-xs text-ink-600">{{ $shipment->merchant?->business_name }}</td>
                    <td class="num font-semibold">{{ number_format($shipment->cod_amount) }}</td>
                    {{-- خانة فارغة يكتب فيها المندوب بيده عند التسليم --}}
                    <td></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-ink-900">
                <td colspan="6" class="text-end font-bold">المجموع</td>
                <td class="num font-black">{{ number_format($totals->cod) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
@endif
@endsection

@section('signatures')
    {{--
      توقيعان لا واحد: الورقة عقدُ عهدة بين طرفين، وتوقيعُ المستلم
      وحده يجعلها إقراراً بلا مُقِرّ له.
    --}}
    <div class="flex-1">
        <div>سلّمها موظّف الفرع: {{ auth()->user()->name }}</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
    <div class="flex-1">
        <div>استلمها المندوب: {{ $courier->name }}</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
    <div class="flex-1 text-center">
        <div>أقرّ باستلام {{ \App\Support\Arabic::shipments($totals->shipments) }}</div>
        <div class="num mt-1 font-bold">بمبلغ {{ number_format($totals->cod) }} د.ع</div>
    </div>
@endsection

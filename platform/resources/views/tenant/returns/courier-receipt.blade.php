@extends('layouts.print', ['back' => route('courier-return-receipts.index')])
@section('title', 'إيصال راجع مندوب '.$receipt->number)
@section('docTitle', 'إيصال استلام راجع من مندوب')
@section('subtitle', $receipt->courier?->name)

@section('content')
<section>
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-lg font-bold">{{ $receipt->courier?->name ?? 'مندوب' }}
            <span class="num text-sm font-normal text-ink-600">{{ $receipt->courier?->code }}</span></h2>
        <div class="num text-base font-bold">{{ $receipt->number }}</div>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
        <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">هاتف المندوب</span><span class="num" dir="ltr">{{ $receipt->courier?->phone }}</span></div>
        <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">وقت الاستلام</span><span class="num">{{ $receipt->received_at->format('Y-m-d H:i') }}</span></div>
    </div>

    <table class="tbl w-full">
        <thead>
            <tr>
                <th class="w-8">#</th>
                <th>رقم الوصل</th>
                <th>التاجر</th>
                <th>المستلم</th>
                <th>الوجهة</th>
                <th>المبلغ</th>
                <th>سبب الرجوع</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receipt->shipments as $i => $shipment)
                <tr>
                    <td class="num text-ink-500">{{ $i + 1 }}</td>
                    <td class="num font-semibold">{{ $shipment->number }}</td>
                    <td>{{ $shipment->merchant?->business_name }}</td>
                    <td>{{ $shipment->recipient_name }}</td>
                    <td>{{ $shipment->governorate?->name_ar }}@if ($shipment->city) · {{ $shipment->city->name_ar }}@endif</td>
                    <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                    <td>{{ $shipment->returnReason() ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-ink-900">
                <td colspan="5" class="font-bold">{{ \App\Support\Arabic::shipments($receipt->shipments_count) }}</td>
                <td class="num font-black">{{ number_format($receipt->cod_total) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    @if ($receipt->note)
        <p class="mt-3 text-sm text-ink-600">ملاحظة: {{ $receipt->note }}</p>
    @endif

    {{-- مَن سلّم ومَن استلم: بالتوقيعين يُحسم «وين راجعي؟» بين المندوب والمخزن --}}
    <div class="mt-8 flex items-end justify-between gap-8 text-xs text-ink-600">
        <div class="flex-1">
            <div>سلّمه المندوب: {{ $receipt->courier?->name ?? '..................' }}</div>
            <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
        </div>
        <div class="flex-1">
            <div>استلمه بالمخزن: {{ $receipt->receivedBy?->name ?? '..................' }}</div>
            <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
        </div>
    </div>
</section>
@endsection

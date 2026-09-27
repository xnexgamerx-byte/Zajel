@extends('layouts.print', ['back' => route('shipments.index', $filters)])
@section('title', 'قائمة الشحنات')
@section('docTitle', 'قائمة الشحنات')
@section('subtitle')
    {{ \App\Support\Arabic::shipments($shipments->count()) }}
    @if ($total > $shipments->count()) من <span class="num">{{ number_format($total) }}</span> — الأحدث وحدها؛ ضيّق الفلاتر للباقي @endif
@endsection

@section('content')
<table class="w-full border-collapse text-[10px] leading-4">
    <thead>
        <tr class="border-b-2 border-ink-900 text-start">
            @foreach (['#', 'رقم الوصل', 'الحالة', 'التاجر', 'المستلم', 'الهاتف', 'الوجهة', 'المبلغ', 'المندوب', 'التاريخ'] as $head)
                <th class="px-1 py-1.5 text-start font-bold">{{ $head }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($shipments as $i => $s)
            <tr class="border-b border-ink-200 align-top break-inside-avoid">
                <td class="num px-1 py-1">{{ $i + 1 }}</td>
                <td class="num px-1 py-1 font-semibold" dir="ltr">{{ $s->number }}</td>
                <td class="px-1 py-1">{{ $s->status->label() }}</td>
                <td class="px-1 py-1">{{ $s->merchant?->business_name }}</td>
                <td class="px-1 py-1">{{ $s->recipient_name }}</td>
                <td class="num px-1 py-1" dir="ltr">{{ $s->recipient_phone }}</td>
                <td class="px-1 py-1">{{ $s->governorate?->name_ar }}@if ($s->city) · {{ $s->city->name_ar }}@endif</td>
                <td class="num px-1 py-1" dir="ltr">{{ number_format($s->cod_amount) }}</td>
                <td class="px-1 py-1">{{ $s->deliveryCourier?->name ?? '—' }}</td>
                <td class="num px-1 py-1" dir="ltr">{{ $s->created_at->format('Y-m-d') }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="border-t-2 border-ink-900 font-bold">
            <td colspan="7" class="px-1 py-1.5">المجموع</td>
            <td class="num px-1 py-1.5" dir="ltr">{{ number_format($shipments->sum('cod_amount')) }}</td>
            <td colspan="2"></td>
        </tr>
    </tfoot>
</table>
@endsection

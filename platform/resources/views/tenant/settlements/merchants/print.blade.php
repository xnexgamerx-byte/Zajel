@extends('layouts.print', ['back' => route('settlements.merchants.show', $settlement)])
@section('title', 'وصل حساب '.$settlement->merchant->business_name)
@section('docTitle', 'وصل حساب تاجر')
@section('subtitle', $settlement->merchant->business_name.' — '.$settlement->code)

@section('content')
{{-- يُعطى للتاجر أو يُحضّر قبل مجيئه: يُمسح رمزه فيُفتح كشفه ليُدفع ويُستلم (docs/plan/61) --}}
<div class="mb-5 flex items-stretch gap-3">
    <div class="grid flex-1 grid-cols-3 gap-3 text-center">
        <div class="rounded-lg border border-ink-300 p-3">
            <div class="text-xs text-ink-600">الشحنات</div>
            <div class="num text-xl font-bold">{{ number_format($settlement->shipments_count) }}</div>
        </div>
        <div class="rounded-lg border border-ink-300 p-3">
            <div class="text-xs text-ink-600">المحصَّل − الأجور (د.ع)</div>
            <div class="num text-xl font-bold">{{ number_format($settlement->cod_total) }} − {{ number_format($settlement->delivery_fees_total + $settlement->return_fees_total) }}</div>
        </div>
        <div class="rounded-lg border-2 border-ink-900 p-3">
            <div class="text-xs text-ink-600">له (د.ع)</div>
            <div class="num text-xl font-black">{{ number_format($settlement->net_amount) }}</div>
        </div>
    </div>
    @php($scan = \App\Actions\Receipts\ConfirmReceipt::required())
    <figure class="shrink-0 text-center">
        <img src="{{ \App\Support\QrCode::dataUri($scan ? route('receipt.link', $settlement->receiptCode()) : route('doc.merchant', $settlement)) }}" alt="" class="size-24">
        @if ($scan)
            {{-- يمسحه التاجر من تطبيقه أو بوابته فيُؤكَّد استلامه، أو يكتب الرمز (docs/plan/69) --}}
            <figcaption class="text-[10px] text-ink-600">رمز الاستلام</figcaption>
            <div class="num text-xs font-bold tracking-wider" dir="ltr" data-receipt-code>{{ \App\Support\ReceiptCode::format($settlement->receiptCode()) }}</div>
        @else
            <figcaption class="text-[10px] text-ink-600">امسحه للاستلام</figcaption>
        @endif
    </figure>
</div>

<table class="tbl w-full">
    <thead><tr><th class="w-8">#</th><th>رقم الوصل</th><th>المستلم</th><th>المحصَّل</th><th>التوصيل</th><th>الراجع</th><th>له</th></tr></thead>
    <tbody>
        @foreach ($lines as $i => $line)
            <tr>
                <td class="num text-ink-500">{{ $i + 1 }}</td>
                <td class="num font-semibold">{{ $line->shipment?->number }}</td>
                <td>{{ $line->shipment?->recipient_name }}</td>
                <td class="num">{{ number_format($line->collected_amount) }}</td>
                <td class="num">{{ $line->delivery_fee ? number_format($line->delivery_fee) : '' }}</td>
                <td class="num">{{ $line->return_fee ? number_format($line->return_fee) : '' }}</td>
                <td class="num font-bold">{{ number_format($line->net_amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@if ($settlement->advance_deduction)
    <p class="mt-3 text-sm">يُقتطع منه للسلف: <b class="num">{{ number_format($settlement->advance_deduction) }}</b> د.ع</p>
@endif
@endsection

@section('signatures')
    <div class="flex-1 border-t border-ink-400 pt-2 text-center">توقيع التاجر بالاستلام</div>
    <div class="flex-1 border-t border-ink-400 pt-2 text-center">توقيع الموظّف</div>
@endsection

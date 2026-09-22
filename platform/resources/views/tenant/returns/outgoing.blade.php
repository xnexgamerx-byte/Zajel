@extends('layouts.app')
@section('title', 'تسليم الراجع للتاجر')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">تسليم الراجع للتاجر</h1>
    <p class="mt-1 text-sm text-ink-500">
        طرود وصلت المخزن وتنتظر صاحبها. عند التسليم تُقيَّد أجرة الراجع على حساب التاجر.
    </p>
</div>

<div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('returns.outgoing') }}"
       class="chip {{ $merchantId ? 'chip-mute' : 'chip-info' }}">الكل ({{ number_format($perMerchant->sum()) }})</a>

    @foreach ($merchants as $merchant)
        @continue(! $perMerchant->has($merchant->id))
        <a href="{{ route('returns.outgoing', ['merchant_id' => $merchant->id]) }}"
           class="chip {{ $merchantId === $merchant->id ? 'chip-info' : 'chip-mute' }}">
            {{ $merchant->business_name }} ({{ number_format($perMerchant[$merchant->id]) }})
        </a>
    @endforeach
</div>

@if ($merchantId)
    <x-returns-table :shipments="$shipments" :action="route('returns.deliver')" party="merchant"
                     :merchant-id="$merchantId"
                     submit="سلّمت للتاجر"
                     empty="لا راجع جاهز لهذا التاجر." />
@else
    {{-- التسليم لتاجر واحد في كل مرّة: توقيع واحد على كشف واحد --}}
    <section class="card overflow-hidden">
        <p class="border-b border-ink-100 px-5 py-4 text-sm text-ink-600">
            اختر تاجراً من الأعلى لتسليمه راجعه. التسليم يكون لتاجر واحد في كل مرّة.
        </p>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>الوصل</th><th>التاجر</th><th>المستلم</th><th>المبلغ</th><th>في المخزن منذ</th></tr>
                </thead>
                <tbody>
                    @forelse ($shipments as $shipment)
                        <tr>
                            <td><a href="{{ route('shipments.show', $shipment) }}"
                                   class="num font-semibold hover:underline">{{ $shipment->number }}</a></td>
                            <td class="text-ink-600">{{ $shipment->merchant?->business_name }}</td>
                            <td class="text-ink-600">{{ $shipment->recipient_name }}</td>
                            <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                            <td class="text-sm text-ink-500">{{ $shipment->return_received_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-10 text-center text-ink-500">لا راجع في المخزن الآن.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection

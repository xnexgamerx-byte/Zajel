@extends('layouts.app')
@section('title', $waybill ? 'شحنة من وصلٍ مطبوع' : 'شحنة جديدة')

@section('content')
<div class="mx-auto mb-5 flex max-w-3xl flex-wrap items-center justify-between gap-3">
    <div>
        @if ($waybill)
            {{-- من «شحنة من وصلٍ مطبوع»: يُكتب ما كتبه التاجر بيده على الوصل --}}
            <h1 class="page-title">شحنة من الوصل المطبوع <span class="num">{{ $waybill->code }}</span></h1>
            <p class="mt-1 text-sm text-ink-500">
                اكتب ما على الوصل. للشحنة رقمٌ يُولَّد عند الحفظ، ورقم الوصل المطبوع يبقى عليها: يُمسح به ويُبحث كرقمها.
            </p>
        @else
            <h1 class="page-title">شحنة جديدة</h1>
            <p class="mt-1 text-sm text-ink-500">رقم الوصل يُولَّد تلقائياً عند الحفظ.</p>
        @endif
    </div>
    @if ($waybill)
        <a href="{{ route('shipments.waybill') }}" class="btn-ghost">رجوع للمسح</a>
    @else
        <a href="{{ route('shipments.index') }}" class="btn-ghost">رجوع للقائمة</a>
    @endif
</div>

@if ($created)
    {{-- المحفوظة للتوّ: رقمها وطباعة وصلها، والنموذج تحتها فارغٌ للتالية --}}
    <div class="mx-auto mb-4 flex max-w-3xl flex-wrap items-center gap-3 rounded-3xl border border-ok-200 bg-ok-50 px-5 py-3.5 text-sm text-ok-700"
         role="status">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-white text-ok-700"><x-icon name="check" class="size-5"/></span>
        <span>
            حُفظت الشحنة
            <a href="{{ route('shipments.show', $created) }}" class="num font-bold underline-offset-4 hover:underline">{{ $created->number }}</a>
            — {{ $created->recipient_name }}، <span class="num">{{ number_format($created->cod_amount) }}</span> د.ع
        </span>
        <a href="{{ route('shipments.labels', ['ids' => [$created->id]]) }}" target="_blank" class="btn-ghost ms-auto">
            <x-icon name="printer" class="size-4"/>
            اطبع الوصل
        </a>
    </div>
@endif

@feature('order_reading')
    <x-order-reader :url="route('shipments.read')" form="shipment-form" />
@endfeature

@include('tenant.shipments._form', ['shipment' => null, 'reroutable' => true])
@endsection

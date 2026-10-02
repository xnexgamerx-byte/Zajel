@extends('layouts.app')
@section('title', $waybill ? 'شحنة من وصلٍ مطبوع' : 'شحنة جديدة')

@section('content')
<div class="mb-5 flex items-center justify-between">
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

@include('tenant.shipments._form', ['shipment' => null, 'reroutable' => true])
@endsection

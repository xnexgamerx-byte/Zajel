@extends('layouts.app')
@section('title', 'تعديل الشحنة ' . $shipment->number)

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">تعديل الشحنة <span class="num">{{ $shipment->number }}</span></h1>
        <p class="page-sub">كل تعديلٍ يُسجَّل في سجلّ الشحنة: ما كان، وما صار، ومن غيّره.</p>
    </div>
    <a href="{{ route('shipments.show', $shipment) }}" class="btn-ghost">رجوع للشحنة</a>
</div>

@include('tenant.shipments._form', ['shipment' => $shipment, 'reroutable' => $reroutable])
@endsection

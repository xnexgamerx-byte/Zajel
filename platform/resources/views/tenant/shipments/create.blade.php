@extends('layouts.app')
@section('title', 'شحنة جديدة')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="page-title">شحنة جديدة</h1>
        <p class="mt-1 text-sm text-ink-500">رقم الوصل يُولَّد تلقائياً عند الحفظ.</p>
    </div>
    <a href="{{ route('shipments.index') }}" class="btn-ghost">رجوع للقائمة</a>
</div>

@include('tenant.shipments._form', ['shipment' => null, 'reroutable' => true])
@endsection

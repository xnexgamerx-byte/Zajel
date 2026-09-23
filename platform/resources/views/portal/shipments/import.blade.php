@extends('layouts.portal')
@section('title', 'رفع شحنات')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">رفع شحنات من ملف</h1>
        <p class="mt-1 text-sm text-ink-500">
            صدّر طلباتك من متجرك وارفعها دفعة واحدة. تُعاين قبل الحفظ.
        </p>
    </div>
    <a href="{{ route('portal.shipments.create') }}" class="btn-ghost">شحنة واحدة</a>
</div>

<x-import-form :action="route('portal.shipments.import.store')"
               :template-url="route('portal.shipments.import.template')"
               :columns="$columns" :required="$required" />
@endsection

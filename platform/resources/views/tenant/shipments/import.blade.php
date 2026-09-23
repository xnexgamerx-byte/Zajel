@extends('layouts.app')
@section('title', 'رفع شحنات')

@section('content')
<div class="mb-5">
    <h1 class="page-title">رفع شحنات من ملف</h1>
    <p class="mt-1 text-sm text-ink-500">
        بديل إدخال مئة شحنة واحدة واحدة. تُعاين قبل الحفظ، ويُنبَّه على المكرّر.
    </p>
</div>

<x-import-form :action="route('shipments.import.store')"
               :template-url="route('shipments.import.template')"
               :columns="$columns" :required="$required" :merchants="$merchants" />
@endsection

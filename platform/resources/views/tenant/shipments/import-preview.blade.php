@extends('layouts.app')
@section('title', 'معاينة الاستيراد')

@section('content')
<div class="mb-5">
    <h1 class="page-title">معاينة الاستيراد</h1>
    <p class="mt-1 text-sm text-ink-500">لم يُحفَظ شيء بعد.</p>
</div>

<x-import-preview :rows="$rows" :merchant="$merchant" :action="$action"
                  :back="$back" :path="$path" :merchant-id="$merchant->id" />
@endsection

@extends('layouts.app')
@section('title', 'لوحة اليوم')

@section('content')
@php
    // التحية بساعة بغداد: توقيت التطبيق (config/app.php)
    $hour = now()->hour;
    $greeting = $hour >= 4 && $hour < 12 ? 'صباح الخير' : 'مساء الخير';
@endphp
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $greeting }}، {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
        <p class="page-sub">لوحة اليوم · {{ now()->translatedFormat('l j F Y') }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('home.customize') }}" class="btn-ghost">
            <x-icon name="sliders" class="size-5"/>
            خصّص الرئيسية
        </a>
        <a href="{{ route('shipments.index') }}" class="btn-ghost">كل الشحنات</a>
    </div>
</div>

@include('tenant._dashboard-sections')
@endsection

@extends('layouts.app')
@section('title', 'استلام الراجع من المندوب')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">استلام الراجع من المندوب</h1>
    <p class="mt-1 text-sm text-ink-500">
        طرود قُرّر إرجاعها وما زالت بيد المندوب. تُصبح جاهزة للتسليم للتاجر بعد استلامها هنا.
    </p>
</div>

@if ($couriers->isNotEmpty())
    <div class="mb-4 flex flex-wrap gap-2">
        <a href="{{ route('returns.incoming') }}"
           class="chip {{ $courierId ? 'chip-mute' : 'chip-info' }}">الكل ({{ number_format($perCourier->sum()) }})</a>

        @foreach ($couriers as $courier)
            @continue(! $perCourier->has($courier->id))
            <a href="{{ route('returns.incoming', ['courier_id' => $courier->id]) }}"
               class="chip {{ $courierId === $courier->id ? 'chip-info' : 'chip-mute' }}">
                {{ $courier->name }} ({{ number_format($perCourier[$courier->id]) }})
            </a>
        @endforeach
    </div>
@endif

<x-returns-table :shipments="$shipments" :action="route('returns.receive')" party="courier"
                 submit="استلمت هذه الطرود"
                 empty="لا راجع عند المندوبين الآن." />
@endsection

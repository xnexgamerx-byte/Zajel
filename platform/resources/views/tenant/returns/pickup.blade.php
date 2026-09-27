@extends('layouts.app')
@section('title', 'تسليم الراجع لمندوب الاستلام')

@section('content')
<div class="mb-5">
    <h1 class="page-title">تسليم الراجع لمندوب الاستلام</h1>
    <p class="mt-1 text-sm text-ink-500">
        مندوب الاستلام يمرّ بتجّاره كل يوم: يحمل رواجعهم معه بإيصالٍ لكل تاجر يوقَّع عند بابه.
        تُقيَّد أجرة الراجع عند التسليم له، ويبقى الإيصال «لم يؤكَّد» حتى يؤكّده التاجر.
    </p>
</div>

<div class="mb-4 flex flex-wrap gap-2">
    @forelse ($couriers as $c)
        <a href="{{ route('returns.pickup', ['courier_id' => $c->id]) }}"
           class="chip {{ $courier?->id === $c->id ? 'chip-info' : 'chip-mute' }}">
            {{ $c->name }} ({{ number_format($perCourier[$c->id] ?? 0) }})
        </a>
    @empty
        <p class="text-sm text-ink-500">لا مندوب استلام مفعّل.</p>
    @endforelse
</div>

@if ($courier)
    <x-returns-table :shipments="$shipments" :action="route('returns.pickup.deliver')" party="merchant"
                     :hidden="['courier_id' => $courier->id]"
                     submit="سلّمت لمندوب الاستلام"
                     empty="لا راجع على الرفّ لتجّار هذا المندوب." />
@else
    <section class="card p-10 text-center text-sm text-ink-500">
        اختر مندوب استلام من الأعلى: تظهر رواجع التجّار الذين يمرّ بهم (المندوب المعتاد في بطاقة التاجر).
        والعدد بجانب اسمه ما ينتظره منها الآن.
    </section>
@endif
@endsection

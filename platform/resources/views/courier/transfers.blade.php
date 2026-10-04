@extends('layouts.courier')
@section('title', 'كشوف النقل')

{{-- مندوب النقل بين الفروع (المناورة): ما يحمله بين المحافظات، وما يُحمَّل له، وما سلّمه اليوم --}}
@section('content')
<div class="mb-4 grid grid-cols-2 gap-3">
    <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
        <div class="text-xs text-ink-500">كشوف بيدك</div>
        <div class="mt-0.5 text-3xl font-bold">{{ number_format($onTheRoad->count()) }}</div>
    </div>
    <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
        <div class="text-xs text-ink-500">شحنات معك</div>
        <div class="mt-0.5 text-3xl font-bold"><span class="num">{{ number_format($onTheRoad->sum('shipments_count')) }}</span></div>
    </div>
</div>

<x-app-ads audience="couriers" class="mb-4" />

@php($sections = [
    ['بيدك في الطريق', $onTheRoad, 'سلّمها لمركز الوصول؛ هو يستلم الأكياس بالعدد.'],
    ['تُحمَّل لك', $loading, 'تُختم الأكياس وتُحمَّل في مركز الانطلاق قبل خروجك.'],
    ['سلّمتها اليوم', $arrived, null],
])

@foreach ($sections as [$title, $manifests, $hint])
    @continue($manifests->isEmpty())
    <h2 class="mb-2 mt-4 px-1 text-sm font-bold text-ink-600">{{ $title }}</h2>
    @if ($hint)<p class="mb-2 px-1 text-xs text-ink-500">{{ $hint }}</p>@endif

    @foreach ($manifests as $manifest)
        <div class="mb-2 rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="font-bold">{{ $manifest->fromHub?->name }} ← {{ $manifest->toHub?->name }}</div>
                    <div class="font-mono text-xs text-ink-400" dir="ltr">{{ $manifest->code }}</div>
                </div>
                <span class="chip {{ $manifest->statusTone() }}">{{ $manifest->statusLabel() }}</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-600">
                <span><span class="num font-semibold text-ink-900">{{ number_format($manifest->bags_count) }}</span> أكياس</span>
                <span><span class="num font-semibold text-ink-900">{{ number_format($manifest->shipments_count) }}</span> شحنة</span>
                @if ($manifest->arrived_at)
                    <span>وصل <span dir="ltr">{{ $manifest->arrived_at->format('H:i') }}</span></span>
                @elseif ($manifest->departed_at)
                    <span>غادر {{ $manifest->departed_at->diffForHumans() }}</span>
                @endif
            </div>
        </div>
    @endforeach
@endforeach

@if ($onTheRoad->isEmpty() && $loading->isEmpty() && $arrived->isEmpty())
    <div class="rounded-xl border border-ink-200 bg-white p-10 text-center shadow-xs">
        <p class="font-semibold text-ink-700">لا كشوف نقلٍ لك الآن.</p>
        <p class="mt-1 text-sm text-ink-500">حين يُنشأ كشفٌ باسمك بين فرعين يظهر هنا.</p>
    </div>
@endif
@endsection

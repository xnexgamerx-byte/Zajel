@extends('layouts.app')
@section('title', 'ربط المناطق بالوسيط')

{{--
  محافظاتنا بمدنهم ومناطقنا بمناطقهم (docs/plan/104): الطلب لا يُنشأ عندهم بلا مدينةٍ ومنطقة.
  «اربط بالاسم» يربط ما يطابق اسمه وحده، ويجلب مناطق كل مدينةٍ مربوطة؛ وما بقي يُختار هنا.
--}}
@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <a href="{{ route('aggregator.edit') }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ ربط الشركة الوسيطة</a>
        <h1 class="page-title mt-2">ربط المناطق</h1>
        <p class="mt-1 text-sm text-ink-500">لكل محافظةٍ عندنا مدينتها عندهم، ولكل منطقةٍ منطقتها. ما لم يُربط لا يُرسل ويظهر في السجلّ بسببه.</p>
    </div>
    <form method="POST" action="{{ route('aggregator.areas.auto') }}">
        @csrf
        <button type="submit" class="btn-ghost" @disabled(! $theirCities)><x-icon name="bolt" class="size-5"/> اربط بالاسم</button>
    </form>
</div>

@if ($errors->has('areas'))
    <div class="alert alert-bad mb-5">{{ $errors->first('areas') }}</div>
@endif

@if (! $theirCities)
    <div class="alert alert-warn">اتّصل بنظامهم أوّلاً من صفحة الربط لتُجلب مدنهم.</div>
@else
<form method="POST" action="{{ route('aggregator.areas.update') }}" class="space-y-4">
    @csrf
    @method('PUT')
    @foreach ($governorates as $governorate)
        @php
            $remoteCity = $mapped['governorate'][$governorate->id] ?? null;
            $theirRegions = $remoteCity ? ($regions[$remoteCity] ?? []) : [];
            $ours = $cities[$governorate->id] ?? collect();
        @endphp
        <details class="card p-4" @if ($remoteCity && $ours->contains(fn ($c) => ! isset($mapped['city'][$c->id]))) open @endif>
            <summary class="flex cursor-pointer flex-wrap items-center gap-3">
                <b class="min-w-28">{{ $governorate->name_ar }}</b>
                <select name="governorate[{{ $governorate->id }}]" class="field-input w-56 py-1.5" aria-label="مدينتهم لـ {{ $governorate->name_ar }}">
                    <option value="">— غير مربوطة —</option>
                    @foreach ($theirCities as $id => $name)
                        <option value="{{ $id }}" @selected((string) $remoteCity === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @if ($remoteCity)
                    @php $left = $ours->filter(fn ($c) => ! isset($mapped['city'][$c->id]))->count(); @endphp
                    <span class="chip {{ $left ? 'chip-warn' : 'chip-ok' }}">{{ $left ? $left.' منطقة بلا ربط' : 'كل مناطقها مربوطة' }}</span>
                @endif
            </summary>

            @if ($remoteCity)
                @if ($theirRegions)
                    <div class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1.5 md:grid-cols-2">
                        @foreach ($ours as $city)
                            <label class="flex items-center justify-between gap-3 border-b border-ink-100 py-1 text-sm">
                                <span>{{ $city->name_ar }}</span>
                                <select name="city[{{ $city->id }}]" class="field-input w-56 py-1">
                                    <option value="">—</option>
                                    @foreach ($theirRegions as $id => $name)
                                        <option value="{{ $id }}" @selected((string) ($mapped['city'][$city->id] ?? '') === (string) $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                @else
                    <p class="mt-3 text-sm text-ink-500">احفظ ثم اضغط «اربط بالاسم» لتُجلب مناطق مدينتهم.</p>
                @endif
            @endif
        </details>
    @endforeach
    <button type="submit" class="btn-primary">احفظ ربط المناطق</button>
</form>
@endif
@endsection

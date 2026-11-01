@extends('layouts.app')
@section('title', 'ربط المناطق بالوسيط')

{{--
  محافظاتنا بمدنهم (docs/plan/104): الطلب لا يُنشأ عندهم بلا مدينةٍ ومنطقة. مناطق كل محافظةٍ في صفحتها —
  آلاف المناطق بقوائم مناطقهم لا تُبنى في صفحةٍ واحدة. «اربط بالاسم» يربط ما يطابق اسمه وحده.
--}}
@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <a href="{{ route('aggregator.edit') }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ ربط الشركة الوسيطة</a>
        <h1 class="page-title mt-2">ربط المناطق</h1>
        <p class="mt-1 text-sm text-ink-500">لكل محافظةٍ عندنا مدينتها عندهم، ولكل منطقةٍ منطقتها. ما لم يُربط لا يُرسل ويظهر في السجلّ بسببه.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ route('aggregator.areas.verify') }}">
            @csrf
            <button type="submit" class="btn-ghost" @disabled(! $theirCities)><x-icon name="check" class="size-5"/> افحص الربط</button>
        </form>
        <form method="POST" action="{{ route('aggregator.areas.auto') }}">
            @csrf
            <button type="submit" class="btn-ghost" @disabled(! $theirCities)><x-icon name="bolt" class="size-5"/> اربط بالاسم</button>
        </form>
        <form method="POST" action="{{ route('aggregator.areas.prefix') }}">
            @csrf
            <button type="submit" class="btn-ghost" @disabled(! $theirCities)
                    title="ما بقي بلا ربط يُربط بأول اسمه إن وُجد عندهم: «دجيل الحاتمية» ← «دجيل». يُعلَّم لتراجعه."><x-icon name="pin" class="size-5"/> اربط بأول الاسم</button>
        </form>
    </div>
</div>

@if ($errors->has('areas'))
    <div class="alert alert-bad mb-5">{{ $errors->first('areas') }}</div>
@endif

{{-- ما ربطه «اربط بأول الاسم» الآن: يُراجَع، وكلّه معلَّم في صفحة محافظته --}}
@if ($prefixed = session('aggregator_prefix'))
    <section class="card mb-5 p-5">
        <details open>
            <summary class="cursor-pointer text-sm font-semibold">ما رُبط بأول الاسم ({{ count($prefixed) }}) — راجعه</summary>
            <ul class="mt-2 list-disc ps-5 text-sm text-ink-700">
                @foreach ($prefixed as $line)<li>{{ $line }}</li>@endforeach
            </ul>
        </details>
        <p class="field-hint mt-2">ما لا يصحّ منها غيّره من صفحة محافظته («بأول الاسم — للمراجعة»).</p>
    </section>
@endif

{{-- نتيجة «افحص الربط»: ما رُبط على قوائمهم الحيّة، وما سيُرفض عند الإرسال --}}
@if ($check = session('aggregator_check'))
    @php $bad = count($check['gone_cities']) + count($check['gone_regions']); @endphp
    <section class="card mb-5 p-5">
        <h2 class="card-title">نتيجة الفحص على قوائمهم الآن</h2>
        <div class="mt-3 flex flex-wrap gap-2">
            <span class="chip chip-ok"><span class="num">{{ number_format($check['ok']) }}</span> منطقة سليمة</span>
            <span class="chip {{ $check['unmapped'] ? 'chip-warn' : 'chip-ok' }}"><span class="num">{{ number_format($check['unmapped']) }}</span> بلا ربط</span>
            <span class="chip {{ $bad ? 'chip-bad' : 'chip-ok' }}"><span class="num">{{ $bad }}</span> مربوطةٌ بما لم يعد عندهم</span>
            <span class="chip {{ $check['governorates_left'] ? 'chip-warn' : 'chip-ok' }}"><span class="num">{{ $check['governorates_left'] }}</span> محافظة بلا مدينة</span>
        </div>
        @if ($check['unmapped'] || $check['governorates_left'])
            <p class="mt-3 text-sm text-warn-700">شحنةٌ إلى منطقةٍ أو محافظةٍ بلا ربط لا تُرسل لهم، وتظهر في السجلّ بسببها حتى تُربط ثم تُعاد.</p>
        @endif
        @foreach ([['gone_cities', 'مدنٌ مربوطة لم تعد في قائمتهم'], ['gone_regions', 'مناطقُ مربوطة لم تعد في قائمتهم'], ['shared', 'مناطقنا التي تشترك بمنطقةٍ واحدة عندهم (راجعها: قد تكون مقصودة)']] as [$key, $title])
            @if ($check[$key])
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm font-semibold">{{ $title }} ({{ count($check[$key]) }})</summary>
                    <ul class="mt-2 list-disc ps-5 text-sm text-ink-700">
                        @foreach (array_slice($check[$key], 0, 100) as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                </details>
            @endif
        @endforeach
    </section>
@endif

@if (! $theirCities)
    <div class="alert alert-warn">اتّصل بنظامهم أوّلاً من صفحة الربط لتُجلب مدنهم.</div>
@else
<form method="POST" action="{{ route('aggregator.areas.update') }}" class="space-y-3">
    @csrf
    @method('PUT')
    @foreach ($governorates as $governorate)
        @php $remoteCity = $mapped[$governorate->id] ?? null; $left = $governorate->total - $governorate->mapped; @endphp
        <div class="card flex flex-wrap items-center gap-3 p-4">
            <b class="min-w-28">{{ $governorate->name_ar }}</b>
            <select name="governorate[{{ $governorate->id }}]" class="field-input w-56 py-1.5" aria-label="مدينتهم لـ {{ $governorate->name_ar }}">
                <option value="">— غير مربوطة —</option>
                @foreach ($theirCities as $id => $name)
                    <option value="{{ $id }}" @selected((string) $remoteCity === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
            @if ($remoteCity)
                <span class="chip {{ $left ? 'chip-warn' : 'chip-ok' }}">
                    <span class="num">{{ number_format($governorate->mapped) }}</span> من <span class="num">{{ number_format($governorate->total) }}</span> مربوطة
                </span>
                <a href="{{ route('aggregator.areas.show', $governorate) }}" class="btn-ghost ms-auto py-1">
                    {{ $left ? 'اربط '.number_format($left).' منطقة' : 'عرض مناطقها' }}
                </a>
            @else
                <span class="text-xs text-ink-500">اختر مدينتها ثم احفظ لتظهر مناطقها.</span>
            @endif
        </div>
    @endforeach
    <button type="submit" class="btn-primary">احفظ ربط المحافظات</button>
</form>
@endif
@endsection

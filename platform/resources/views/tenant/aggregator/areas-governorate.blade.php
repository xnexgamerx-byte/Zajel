@extends('layouts.app')
@section('title', 'مناطق '.$governorate->name_ar.' عند الوسيط')

{{--
  مناطق محافظةٍ بمناطق مدينتها عندهم (docs/plan/104): ١٥ في الصفحة، وغير المربوطة أوّلاً، وبحثٌ بالاسم.
  في كل صفٍّ قائمةٌ بمناطقهم كلّها، فعدد الصفوف يُحَدّ حتى لا تثقل الصفحة.
--}}
@section('content')
<div class="mb-5">
    <a href="{{ route('aggregator.areas') }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ ربط المناطق</a>
    <h1 class="page-title mt-2">مناطق {{ $governorate->name_ar }}</h1>
    @if ($remoteCity)
        <p class="mt-1 text-sm text-ink-500">مدينتها عندهم: <b>{{ $remoteCityName }}</b> — <span class="num">{{ number_format(count($regions)) }}</span> منطقة عندهم.</p>
    @endif
</div>

@if (! $remoteCity)
    <div class="alert alert-warn">اربط المحافظة بمدينتها عندهم أوّلاً من صفحة ربط المناطق.</div>
@else
    {{-- منطقةٌ هي عندهم في محافظةٍ أخرى («طوزخورماتو» في كركوك عندهم): تُعرض مناطق تلك المدينة وتُربط منها --}}
    <form method="GET" class="card mb-4 flex flex-wrap items-center gap-2 p-4">
        <label for="their_city" class="text-sm font-semibold">مناطق مدينة عندهم:</label>
        <select id="their_city" name="their_city" class="field-input w-56 py-1.5" onchange="this.form.submit()">
            @foreach ($theirCities as $id => $name)
                <option value="{{ $id }}" @selected((string) $shown === (string) $id)>{{ $name }}{{ (string) $id === (string) $remoteCity ? ' (مدينتها)' : '' }}</option>
            @endforeach
        </select>
        <input type="hidden" name="show" value="{{ $show }}">
        <input type="hidden" name="q" value="{{ $search }}">
        <noscript><button type="submit" class="btn-ghost py-1.5">اعرض</button></noscript>
        <p class="field-hint w-full">منطقةٌ من {{ $governorate->name_ar }} هي عندهم في محافظةٍ أخرى؟ اختر مدينتها هنا ثم اربطها: تُرسل شحناتها بتلك المدينة.</p>
    </form>
    @if ((string) $shown !== (string) $remoteCity)
        <div class="alert alert-warn mb-4">تعرض مناطق <b>{{ $theirCities[$shown] ?? $shown }}</b>، لا مدينة {{ $governorate->name_ar }}: ما تربطه هنا يُرسل بها.</div>
    @endif
    @if ($regionsError)
        <div class="alert alert-bad mb-4">تعذّر جلب مناطق هذه المدينة: {{ $regionsError }}</div>
    @endif
@endif

@if ($remoteCity && ! $regions && ! $regionsError)
    <div class="alert alert-warn">لم تُجلب مناطق مدينتها بعد: اضغط «اربط بالاسم» في صفحة ربط المناطق.</div>
@elseif ($remoteCity && $regions)
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @if ((string) $shown !== (string) $remoteCity)<input type="hidden" name="their_city" value="{{ $shown }}">@endif
        <select name="show" class="field-input w-44 py-1.5" aria-label="ما يُعرض">
            <option value="unmapped" @selected($show === 'unmapped')>غير المربوطة</option>
            <option value="prefix" @selected($show === 'prefix')>بأول الاسم — للمراجعة</option>
            <option value="all" @selected($all)>كل المناطق</option>
        </select>
        <input name="q" value="{{ $search }}" class="field-input w-56 py-1.5" placeholder="ابحث باسم المنطقة…" aria-label="بحث">
        <button type="submit" class="btn-ghost py-1.5">تصفية</button>
    </form>

    @if ($rows->isEmpty())
        <div class="card p-5 text-sm text-ink-500">
            {{ $search !== '' || $all ? 'لا منطقة بهذا الاسم.' : ($show === 'prefix' ? 'لا منطقة مربوطة بأول الاسم.' : 'كل مناطق '.$governorate->name_ar.' مربوطة.') }}
        </div>
    @else
        <form method="POST" action="{{ route('aggregator.areas.update') }}" class="card p-5">
            @csrf
            @method('PUT')
            @if ((string) $shown !== (string) $remoteCity)<input type="hidden" name="their_city" value="{{ $shown }}">@endif
            <div class="grid grid-cols-1 gap-x-6 gap-y-1.5 md:grid-cols-2">
                @foreach ($rows as $city)
                    @php
                        $place = $places[$city->id] ?? null;
                        // مدينة ربطها الحاليّ: مدينتها الأخرى إن رُبطت بها، وإلّا مدينة المحافظة
                        $placeCity = $place ? (string) ($place->remote_city_id ?: $remoteCity) : null;
                        $here = $place && $placeCity === (string) $shown;
                    @endphp
                    <label class="flex items-center justify-between gap-3 border-b border-ink-100 py-1 text-sm">
                        <span>
                            {{ $city->name_ar }}
                            @if ($place && ! $here)
                                <span class="block text-xs text-ink-500">مربوطة بـ «{{ $place->remote_name ?? $place->remote_id }}» في {{ $theirCities[$placeCity] ?? $placeCity }}</span>
                            @elseif ($place?->remote_city_id)
                                <span class="chip chip-warn ms-1">في {{ $theirCities[$place->remote_city_id] ?? $place->remote_city_id }}</span>
                            @endif
                            @if (isset($prefixed[$city->id]))
                                <span class="chip chip-warn ms-1" title="رُبطت بأول اسمها: احفظها لتُثبَّت، أو غيّرها">بأول الاسم</span>
                            @endif
                        </span>
                        <select name="city[{{ $city->id }}]" class="field-input w-56 py-1">
                            @if ($place && ! $here)
                                {{-- مربوطةٌ في مدينةٍ أخرى: الحفظ لا يفكّها ما لم تُختر لها منطقةٌ هنا --}}
                                <option value="keep" selected>— كما هي —</option>
                            @endif
                            <option value="">—</option>
                            @foreach ($regions as $id => $name)
                                <option value="{{ $id }}" @selected($here && (string) $place->remote_id === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
            <button type="submit" class="btn-primary mt-4">احفظ هذه الصفحة</button>
            <p class="field-hint mt-2">الحفظ لهذه الصفحة وحدها: ما في الصفحات الأخرى يُحفظ من صفحته.</p>
        </form>
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
@endif
@endsection

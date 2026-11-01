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
@elseif (! $regions)
    <div class="alert alert-warn">لم تُجلب مناطق مدينتها بعد: اضغط «اربط بالاسم» في صفحة ربط المناطق.</div>
@else
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        <select name="show" class="field-input w-44 py-1.5" aria-label="ما يُعرض">
            <option value="unmapped" @selected(! $all)>غير المربوطة</option>
            <option value="all" @selected($all)>كل المناطق</option>
        </select>
        <input name="q" value="{{ $search }}" class="field-input w-56 py-1.5" placeholder="ابحث باسم المنطقة…" aria-label="بحث">
        <button type="submit" class="btn-ghost py-1.5">تصفية</button>
    </form>

    @if ($rows->isEmpty())
        <div class="card p-5 text-sm text-ink-500">
            {{ $all || $search !== '' ? 'لا منطقة بهذا الاسم.' : 'كل مناطق '.$governorate->name_ar.' مربوطة.' }}
        </div>
    @else
        <form method="POST" action="{{ route('aggregator.areas.update') }}" class="card p-5">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-x-6 gap-y-1.5 md:grid-cols-2">
                @foreach ($rows as $city)
                    <label class="flex items-center justify-between gap-3 border-b border-ink-100 py-1 text-sm">
                        <span>{{ $city->name_ar }}</span>
                        <select name="city[{{ $city->id }}]" class="field-input w-56 py-1">
                            <option value="">—</option>
                            @foreach ($regions as $id => $name)
                                <option value="{{ $id }}" @selected((string) ($mapped[$city->id] ?? '') === (string) $id)>{{ $name }}</option>
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

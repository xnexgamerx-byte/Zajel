@extends('layouts.platform')
@section('title', 'حذف البيانات')

{{--
  حذف بيانات شركة (docs/plan/102): الشركة، وما يُحذف منها، ومدّته. «اعرض ما سيُحذف» لا يحذف شيئاً:
  يعرض الأعداد، ومنها يُؤكَّد الحذف باسم الشركة المختصر وكلمة المرور.
--}}
@section('content')
<div class="mb-5">
    <h1 class="page-title">حذف البيانات</h1>
    <p class="mt-1 text-sm text-ink-500">احذف من نظام شركةٍ ما تختاره: كل الوقت، أو يوماً، أو مدّة. لا يُحذف شيءٌ قبل أن ترى أعداده وتؤكّد.</p>
</div>

@if ($done = session('purged'))
    <div class="alert alert-ok mb-5">
        <p class="font-semibold">حُذف من {{ $done['company'] }} — {{ $done['period'] }}:</p>
        @if ($done['counts'])
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($done['counts'] as $key => $n)
                    <li class="chip {{ str_starts_with($key, 'kept_') ? 'chip-warn' : 'chip-ok' }}"><span class="num">{{ number_format($n) }}</span> {{ \App\Services\Platform\CompanyDataPurge::COUNTED[$key] ?? $key }}</li>
                @endforeach
            </ul>
        @else
            <p class="mt-1">لم يكن في المدّة شيءٌ ممّا اخترت.</p>
        @endif
    </div>
@endif

@unless ($canPurge)
    <div class="alert alert-warn mb-5">الحذف لمدير المنصّة وحده.</div>
@endunless

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <form method="GET" action="{{ route('admin.purge.preview') }}" class="card space-y-5 p-5 lg:col-span-2">
        <div>
            <label class="field-label" for="company">الشركة</label>
            <select id="company" name="company" class="field-input" required>
                <option value="">اختر شركة…</option>
                @foreach ($companies as $c)
                    <option value="{{ $c->id }}" @selected(old('company', $chosen) == $c->id)>{{ $c->name }} ({{ $c->slug }})</option>
                @endforeach
            </select>
            @error('company') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="field-label">ما يُحذف</legend>
            <label class="mb-2 flex items-center gap-3 rounded-lg bg-bad-50 px-3 py-2.5 text-sm font-semibold text-bad-700 ring-1 ring-bad-200">
                <input type="checkbox" name="everything" value="1" class="size-4 shrink-0" @checked(old('everything'))>
                كلّ شيء (كل ما تحت)
            </label>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($parts as $key => [$name, $hint])
                    <label class="flex items-start gap-3 rounded-lg px-3 py-2.5 text-sm ring-1 ring-ink-200 hover:bg-ink-50">
                        <input type="checkbox" name="parts[]" value="{{ $key }}" class="mt-0.5 size-4 shrink-0" @checked(in_array($key, old('parts', []), true))>
                        <span><b class="block">{{ $name }}</b><span class="text-xs text-ink-500">{{ $hint }}</span></span>
                    </label>
                @endforeach
            </div>
            @error('parts') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset>
            <legend class="field-label">المدّة</legend>
            <p class="field-hint mb-2">بتاريخ إنشاء كلّ سطر، والقيود بتاريخها المحاسبيّ.</p>
            <div class="space-y-2">
                <label class="flex items-center gap-3 text-sm"><input type="radio" name="mode" value="all" class="size-4 shrink-0" @checked(old('mode', 'all') === 'all')> كل الوقت</label>
                <label class="flex flex-wrap items-center gap-3 text-sm">
                    <input type="radio" name="mode" value="day" class="size-4 shrink-0" @checked(old('mode') === 'day')> يوم معيّن
                    <input type="date" name="day" value="{{ old('day') }}" class="field-input w-auto" aria-label="اليوم">
                </label>
                <label class="flex flex-wrap items-center gap-3 text-sm">
                    <input type="radio" name="mode" value="range" class="size-4 shrink-0" @checked(old('mode') === 'range')> من
                    <input type="date" name="from" value="{{ old('from') }}" class="field-input w-auto" aria-label="من">
                    إلى
                    <input type="date" name="to" value="{{ old('to') }}" class="field-input w-auto" aria-label="إلى">
                </label>
            </div>
            @foreach (['mode', 'day', 'from', 'to'] as $f) @error($f) <p class="field-error">{{ $message }}</p> @enderror @endforeach
        </fieldset>

        <button type="submit" class="btn-danger" @disabled(! $canPurge)>
            <x-icon name="eye" class="size-5"/> اعرض ما سيُحذف
        </button>
    </form>

    <div class="space-y-5">
    {{-- تاجرٌ واحدٌ وكلّ ما له (docs/plan/108): تاجرٌ تجريبيّ مثلاً، دون أن يُمسّ غيره --}}
    <form method="GET" action="{{ route('admin.purge.merchant') }}" class="card space-y-4 p-5">
        <h2 class="card-title">حذف تاجرٍ واحدٍ وكلّ ما له</h2>
        <p class="card-hint">شحناته وطلباته ورسائله وحركات حسابه وحسابات دخوله. ترى الأعداد قبل أن تؤكّد.</p>
        <div>
            <label class="field-label" for="m-company">الشركة</label>
            <select id="m-company" name="company" class="field-input" required>
                <option value="">اختر شركة…</option>
                @foreach ($companies as $c)
                    <option value="{{ $c->id }}" @selected($chosen == $c->id)>{{ $c->name }} ({{ $c->slug }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label" for="m-merchant">رمز التاجر أو اسمه كما هو</label>
            <input id="m-merchant" name="merchant" class="field-input" required value="{{ old('merchant') }}" placeholder="M0005 أو test">
            @error('merchant') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-danger w-full justify-center" @disabled(! $canPurge)>
            <x-icon name="eye" class="size-5"/> اعرض ما سيُحذف
        </button>
    </form>

    <section class="card p-5">
        <h2 class="card-title">قبل أن تحذف</h2>
        <ul class="mt-3 list-disc space-y-2 ps-5 text-sm text-ink-700">
            <li>الحذف نهائيّ لا يُسترجع. خذ نسخةً احتياطية من الخادم أوّلاً (<span dir="ltr" class="font-mono text-xs">deploy/backup.sh</span>).</li>
            <li>يبقى دائماً: الشركة وصاحبها، وإعداداتها وفروعها وأسعارها ومناطقها، واشتراكها وفواتيرها معك، وسجلّ التدقيق.</li>
            <li>التاجر الذي بقيت له شحنات أو حركات مال لا يُحذف، والمندوب كذلك بكشوفه وماله. احذفها معه ليُحذف.</li>
            <li>حذف المال يعيد أرصدة التجّار والمناديب والصناديق من الحركات الباقية، ويعيد ترقيم دفتر القيود.</li>
            <li>الحذف يُكتب في سجلّ تدقيق الشركة: من حذف، ومتى، وماذا.</li>
        </ul>
    </section>
    </div>
</div>
@endsection

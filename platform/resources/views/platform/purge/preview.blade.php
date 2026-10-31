@extends('layouts.platform')
@section('title', 'تأكيد الحذف')

{{-- ما سيُحذف بأعداده، والتأكيد باسم الشركة المختصر وكلمة مرور مدير المنصّة (docs/plan/102) --}}
@section('content')
@php $total = collect($rows)->flatten(1)->sum(fn ($r) => $r[1]); @endphp
<div class="mb-5">
    <a href="{{ route('admin.purge.index', ['company' => $company->id]) }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ غيّر الاختيار</a>
    <h1 class="page-title mt-2">تأكيد الحذف من {{ $company->name }}</h1>
    <p class="mt-1 text-sm text-ink-500">المدّة: <b>{{ $period }}</b>. هذا ما سيُحذف الآن، ولا يُسترجع بعده.</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card p-5 lg:col-span-2">
        <h2 class="card-title">ما سيُحذف</h2>
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($rows as $part => $items)
                <div class="rounded-xl p-4 ring-1 ring-bad-200">
                    <h3 class="mb-2 text-sm font-bold text-bad-700">{{ $labels[$part][0] }}</h3>
                    <ul class="space-y-1 text-sm">
                        @foreach ($items as [$label, $n])
                            <li class="flex justify-between gap-3"><span>{{ $label }}</span><b class="num">{{ number_format($n) }}</b></li>
                        @endforeach
                    </ul>
                    @if (in_array($part, ['merchants', 'couriers'], true))
                        <p class="mt-2 text-xs text-ink-500">من بقيت له شحنات أو حركات مال يبقى، وتراه في النتيجة.</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <form method="POST" action="{{ route('admin.purge.run') }}" class="card space-y-4 p-5">
        @csrf
        <input type="hidden" name="company" value="{{ $company->id }}">
        @foreach ($parts as $part) <input type="hidden" name="parts[]" value="{{ $part }}"> @endforeach
        <input type="hidden" name="mode" value="{{ $mode }}">
        @if ($from)
            <input type="hidden" name="{{ $mode === 'day' ? 'day' : 'from' }}" value="{{ $from->toDateString() }}">
            @if ($mode === 'range') <input type="hidden" name="to" value="{{ $to->toDateString() }}"> @endif
        @endif

        <h2 class="card-title text-bad-700">أكّد الحذف</h2>
        @if ($total === 0)
            <p class="text-sm text-ink-500">لا شيء في هذه المدّة ممّا اخترت.</p>
        @endif

        <div>
            <label class="field-label" for="confirm_slug">اكتب اسم الشركة المختصر: <span dir="ltr" class="font-mono">{{ $company->slug }}</span></label>
            <input id="confirm_slug" name="confirm_slug" class="field-input" dir="ltr" autocomplete="off" required>
            @error('confirm_slug') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="password">كلمة مرورك</label>
            <input id="password" type="password" name="password" class="field-input" autocomplete="current-password" required>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-start gap-3 text-sm">
            <input type="checkbox" name="understood" value="1" class="mt-0.5 size-4 shrink-0" required>
            أفهم أنّ هذا الحذف نهائيّ ولا يُسترجع.
        </label>
        @error('understood') <p class="field-error">{{ $message }}</p> @enderror

        <button type="submit" class="btn-danger w-full justify-center" @disabled($total === 0)>
            <x-icon name="trash" class="size-5"/> احذف نهائياً
        </button>
    </form>
</div>
@endsection

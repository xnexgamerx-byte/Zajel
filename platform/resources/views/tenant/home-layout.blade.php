@extends('layouts.app')
@section('title', 'تخصيص الرئيسية')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">تخصيص الرئيسية</h1>
        <p class="page-sub">
            @if ($rank)
                رئيسية أصحاب مرتبة «{{ $rank->name }}»: يرونها ما لم يخصّص أحدهم رئيسيّته.
            @else
                اختر ما يظهر لك في «لوحة اليوم»: اختصاراتٍ إلى شاشاتك، والأقسام والقوائم التي تهمّك.
            @endif
        </p>
    </div>
    <a href="{{ route('dashboard') }}" class="btn-ghost">رجوع للرئيسية</a>
</div>

@if ($ranks->isNotEmpty())
    {{-- لمن يدير المراتب: رئيسيّته، أو الرئيسية الافتراضية لمرتبةٍ («المتابعة»، «المحاسب»…) --}}
    <form method="GET" action="{{ route('home.customize') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-56 flex-1">
            <label class="field-label" for="rank">لمن تخصّص</label>
            <select id="rank" name="rank" class="field-input" data-submit-on-change>
                <option value="">لي أنا</option>
                @foreach ($ranks as $option)
                    <option value="{{ $option->id }}" @selected($rank?->id === $option->id)>
                        مرتبة «{{ $option->name }}»{{ is_array($option->home_layout) ? ' — مخصّصة' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="btn-ghost">اعرض</button></noscript>
    </form>
@endif

@php
    $from = match ($source) {
        'own'   => 'تخصيصك أنت.',
        'rank'  => $rank ? 'هذه المرتبة مخصّصة.' : 'من مرتبتك «'.$userRank?->name.'» — يتغيّر لك وحدك إن حفظت هنا.',
        default => $rank ? 'لم تُخصَّص بعد: اللوحة كاملةً بلا اختصارات.' : 'الافتراضيّ: اللوحة كاملةً بلا اختصارات.',
    };
@endphp
<p class="mb-4 text-sm text-ink-600">الآن: {{ $from }}</p>

<form method="POST" action="{{ route('home.customize.update') }}" class="space-y-5">
    @csrf
    @if ($rank)
        <input type="hidden" name="rank" value="{{ $rank->id }}">
    @endif

    <section class="card p-5">
        <h2 class="card-title">اختصارات</h2>
        <p class="card-hint mb-4">تظهر أعلى الرئيسية مربّعاتٍ تفتح شاشتها بضغطة — المحاسب يضع الحسابات المالية، والمتابعة شاشاتها.</p>
        @error('shortcuts') <p class="field-error mb-3">{{ $message }}</p> @enderror

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($shortcuts as $group => $items)
                <fieldset class="rounded-xl border border-ink-200 p-4">
                    <legend class="px-1 text-sm font-semibold">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" class="size-4 accent-[var(--brand)]" aria-label="كل «{{ $group }}»"
                                   data-check-all-in="fieldset" data-check-all-of="input[name='shortcuts[]']">
                            {{ $group }}
                        </label>
                    </legend>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($items as $item)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="shortcuts[]" value="{{ $item['id'] }}" class="size-4 accent-[var(--brand)]"
                                       @checked(in_array($item['id'], old('shortcuts', $layout['shortcuts']), true))>
                                {{ $item['label'] }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>
    </section>

    <section class="card p-5">
        <h2 class="card-title">أقسام اللوحة</h2>
        <p class="card-hint mb-4">ما لا يهمّك يُخفى، ويرجع متى شئت.</p>
        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
            @foreach ($sections as $key => [$label, $hint])
                <label class="flex items-start gap-2 rounded-xl border border-ink-200 p-3 text-sm">
                    <input type="checkbox" name="sections[]" value="{{ $key }}" class="mt-0.5 size-4 accent-[var(--brand)]"
                           @checked(in_array($key, old('sections', $layout['sections']), true))>
                    <span>
                        <span class="font-medium">{{ $label }}</span>
                        <span class="mt-0.5 block text-xs text-ink-500">{{ $hint }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </section>

    <section class="card p-5">
        <h2 class="card-title">قوائم التنبيهات</h2>
        <p class="card-hint mb-4">كلّ قائمةٍ تظهر لمن يفتح ما خلفها، والفارغة تنطوي.</p>
        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
            @foreach (\App\Support\HomeLayout::ALERTS as $key => $label)
                <label class="flex items-center gap-2 rounded-xl border border-ink-200 p-3 text-sm">
                    <input type="checkbox" name="alerts[]" value="{{ $key }}" class="size-4 accent-[var(--brand)]"
                           @checked(in_array($key, old('alerts', $layout['alerts']), true))>
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary">حفظ</button>
        <button type="submit" name="reset" value="1" class="btn-ghost"
                data-confirm="{{ $rank ? 'ترجع رئيسية هذه المرتبة إلى الافتراضي؟' : 'ترجع رئيسيتك إلى الافتراضي؟' }}">رجوع للافتراضي</button>
    </div>
</form>
@endsection

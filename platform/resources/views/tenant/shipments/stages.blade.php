@extends('layouts.app')
@section('title', $stage ? $stage['label'].' — كل مراحل النقل' : 'كل مراحل النقل')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">كل مراحل النقل</h1>
        <p class="mt-1 text-sm text-ink-500">
            أين كل شحنةٍ مفتوحة الآن، مرحلةً مرحلة — وأقدم ما في كل مرحلة. اضغط مرحلةً تُفتح شحناتها هنا.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($stage)
            <a href="{{ route('shipments.stages') }}" class="btn-ghost">كل المراحل</a>
        @endif
        <a href="{{ route('shipments.index') }}" class="btn-ghost">كل الشحنات</a>
    </div>
</div>

@php
    // لون عدّاد المرحلة بنبرتها: جارية، تنتظر فعلاً، واصلة، ساكنة — والفارغة باهتة
    $pill = fn (array $item) => $item['count'] === 0 ? 'bg-ink-50 text-ink-400' : match ($item['tone']) {
        'blue'  => 'bg-info-50 text-info-700',
        'amber' => 'bg-warn-50 text-warn-700',
        'green' => 'bg-ok-50 text-ok-700',
        default => 'bg-ink-100 text-ink-700',
    };
    $dot = fn (array $item) => $item['count'] === 0 ? 'bg-ink-200' : match ($item['tone']) {
        'blue'  => 'bg-info-700',
        'amber' => 'bg-warn-500',
        'green' => 'bg-ok-700',
        default => 'bg-ink-400',
    };
    $activeGroup = $stageKey ? collect($groups)->search(fn (array $group) => isset($group['stages'][$stageKey])) : null;
@endphp

@if (! $stage)
    {{-- رحلة الشحنة بخطوةٍ لكل مجموعة، بعدّادها بلا تكرار — والضغط ينزل إلى مراحلها --}}
    <ol class="mb-5 flex snap-x gap-3 overflow-x-auto pb-1 lg:grid lg:grid-cols-6 lg:overflow-visible lg:pb-0" aria-label="رحلة الشحنة">
        @foreach ($groups as $gkey => $group)
            <li class="relative min-w-36 shrink-0 snap-start lg:min-w-0 {{ $loop->last ? '' : 'lg:after:absolute lg:after:top-1/2 lg:after:-end-3 lg:after:h-px lg:after:w-3 lg:after:bg-ink-300' }}">
                <a href="#group-{{ $gkey }}"
                   @class(['flex h-full flex-col gap-2 rounded-3xl border bg-white px-4 py-3 transition hover:-translate-y-0.5 hover:border-primary-200',
                           'border-aeblack-100/80' => $group['total'] > 0, 'border-dashed border-ink-200 opacity-70' => $group['total'] === 0])>
                    <span class="flex items-center justify-between gap-2">
                        {{-- رقم الخطوة على أيقونتها: الترتيب يُقرأ بلا كلمات --}}
                        <span @class(['relative grid size-10 shrink-0 place-items-center rounded-2xl',
                                      'bg-primary-50 text-primary-600' => $group['total'] > 0, 'bg-ink-50 text-ink-400' => $group['total'] === 0])>
                            <x-icon :name="$group['icon']" class="size-5"/>
                            <span class="num absolute -top-1.5 -start-1.5 grid size-5 place-items-center rounded-full bg-aeblack-900 text-[11px] font-semibold text-white">{{ $loop->iteration }}</span>
                        </span>
                        <span class="num text-xl font-semibold {{ $group['total'] > 0 ? 'text-aeblack-950' : 'text-ink-300' }}">{{ number_format($group['total']) }}</span>
                    </span>
                    <span class="truncate text-sm font-semibold text-aeblack-950">{{ $group['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ol>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($groups as $gkey => $group)
            <section id="group-{{ $gkey }}" class="card scroll-mt-24 overflow-hidden" aria-labelledby="group-{{ $gkey }}-title">
                <header class="flex items-center gap-3 border-b border-ink-100 px-5 py-4">
                    <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-primary-50 text-primary-600">
                        <x-icon :name="$group['icon']" class="size-5"/>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 id="group-{{ $gkey }}-title" class="card-title">{{ $group['label'] }}</h2>
                        <p class="card-hint">{{ $group['hint'] }}</p>
                    </div>
                    <span class="num text-2xl font-semibold {{ $group['total'] > 0 ? 'text-aeblack-950' : 'text-ink-300' }}"
                          title="في هذه المجموعة الآن">{{ number_format($group['total']) }}</span>
                </header>
                <ul class="divide-y divide-ink-100">
                    @foreach ($group['stages'] as $key => $item)
                        @php
                            $days = $item['oldest'] ? (int) $item['oldest']->startOfDay()->diffInDays(today()) : null;
                        @endphp
                        <li>
                            <a href="{{ route('shipments.stages', ['stage' => $key]) }}"
                               class="group flex items-center gap-3 px-5 py-3 transition hover:bg-primary-50/50">
                                <span class="size-2 shrink-0 rounded-full {{ $dot($item) }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span @class(['block font-medium', 'text-aeblack-900' => $item['count'] > 0, 'text-ink-500' => $item['count'] === 0])>{{ $item['label'] }}</span>
                                    <span class="block text-xs text-ink-500">
                                        {{ $item['hint'] }}
                                        @if ($item['count'] > 0 && $days !== null && $days > 0)
                                            · <span @class(['font-semibold', 'text-bad-700' => $days >= 3, 'text-ink-600' => $days < 3])>أقدمها منذ {{ \App\Support\Arabic::days($days) }}</span>
                                        @endif
                                    </span>
                                </span>
                                <span class="num grid h-8 min-w-10 shrink-0 place-items-center rounded-full px-2.5 text-sm font-semibold {{ $pill($item) }}">
                                    {{ number_format($item['count']) }}
                                </span>
                                <x-icon name="arrow" class="size-4 shrink-0 text-ink-300 transition group-hover:text-primary-600 rtl:-scale-x-100"/>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
@else
    {{-- اللوحة تبقى بطبقتين: رحلة الشحنة بمجموعاتها، ثم مراحل المجموعة المختارة — والمختارة مضيئة --}}
    <nav class="card mb-4 p-2" aria-label="المراحل" data-stage-strip>
        <div class="flex gap-1 overflow-x-auto">
            @foreach ($groups as $gkey => $group)
                <a href="{{ route('shipments.stages', ['stage' => $group['entry']]) }}"
                   @class(['flex shrink-0 items-center gap-2 rounded-2xl px-3 py-2 text-sm transition',
                           'bg-primary-600 text-white shadow-[0_10px_22px_-14px_var(--color-primary-600)]' => $gkey === $activeGroup,
                           'text-ink-700 hover:bg-ink-50' => $gkey !== $activeGroup,
                           'opacity-60' => $group['total'] === 0 && $gkey !== $activeGroup])
                   @if ($gkey === $activeGroup) aria-current="true" data-scroll-into-view @endif>
                    <x-icon :name="$group['icon']" class="size-4"/>
                    <span class="font-medium">{{ $group['label'] }}</span>
                    <span @class(['num rounded-full px-2 py-0.5 text-xs font-semibold',
                                  'bg-white/20' => $gkey === $activeGroup, 'bg-ink-100 text-ink-600' => $gkey !== $activeGroup])>{{ number_format($group['total']) }}</span>
                </a>
            @endforeach
        </div>

        <div class="mt-2 flex flex-wrap gap-1.5 border-t border-ink-100 px-1 pt-2.5">
            @foreach ($groups[$activeGroup]['stages'] as $key => $item)
                <a href="{{ route('shipments.stages', ['stage' => $key]) }}"
                   @class(['flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition',
                           'border-primary-500 bg-primary-50 font-semibold text-primary-700' => $key === $stageKey,
                           'border-ink-200 bg-white text-ink-700 hover:border-primary-200' => $key !== $stageKey])
                   @if ($key === $stageKey) aria-current="page" @endif>
                    <span class="size-1.5 rounded-full {{ $dot($item) }}" aria-hidden="true"></span>
                    {{ $item['label'] }}
                    <span class="num rounded-full px-1.5 text-xs font-semibold {{ $pill($item) }}">{{ number_format($item['count']) }}</span>
                </a>
            @endforeach
        </div>
    </nav>

    {{-- قسم المرحلة: شحناتها وبحثها وما يُعمل بها --}}
    <section class="card mb-4 p-5" aria-labelledby="stage-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-xs text-ink-500">{{ $stage['group'] }}</div>
                <h2 id="stage-title" class="font-heading text-xl font-medium text-aeblack-950">
                    {{ $stage['label'] }}
                    <span class="num text-base font-normal text-ink-500">({{ number_format($shipments->total()) }})</span>
                </h2>
                <p class="card-hint">{{ $stage['hint'] }} — الأقدم في المرحلة أوّلاً.</p>
            </div>

            @if ($links !== [])
                <div class="flex flex-wrap gap-2">
                    @foreach ($links as $link)
                        <a href="{{ $link['url'] }}" class="btn-ghost">{{ $link['label'] }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        <form method="GET" action="{{ route('shipments.stages') }}" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4 lg:grid-cols-6">
            <input type="hidden" name="stage" value="{{ $stageKey }}">
            {{-- ما جاء به الرابط من فلاتر أخرى (من لوحة اليوم مثلاً) يبقى مع البحث --}}
            @foreach (\Illuminate\Support\Arr::except($filters, ['stage', 'q', 'merchant_id', 'governorate_id', 'courier_id']) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <div class="lg:col-span-2">
                <label class="field-label" for="q">بحث في المرحلة</label>
                <input id="q" name="q" value="{{ request('q') }}" class="field-input"
                       placeholder="رقم وصل · هاتف المستلم · رقم طلب التاجر">
            </div>
            <div>
                <label class="field-label" for="merchant_id">التاجر</label>
                <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
                    <option value="">الكل</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected((int) request('merchant_id') === $merchant->id)>{{ $merchant->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="governorate_id">المحافظة</label>
                <select id="governorate_id" name="governorate_id" class="field-input">
                    <option value="">الكل</option>
                    @foreach ($governorates as $gov)
                        <option value="{{ $gov->id }}" @selected((int) request('governorate_id') === $gov->id)>{{ $gov->name_ar }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="courier_id">المندوب</label>
                <select id="courier_id" name="courier_id" class="field-input">
                    <option value="">الكل</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <button type="submit" class="btn-primary">بحث</button>
                @if (\Illuminate\Support\Arr::except($filters, 'stage') !== [])
                    <a href="{{ route('shipments.stages', ['stage' => $stageKey]) }}" class="btn-ghost">مسح</a>
                @endif
            </div>
        </form>

        @can('shipments.export')
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('shipments.export', $filters) }}" class="btn-ghost">
                    <x-icon name="download" class="size-5"/> Excel
                </a>
                <a href="{{ route('shipments.export.print', $filters) }}" class="btn-ghost" target="_blank" rel="noopener">
                    <x-icon name="printer" class="size-5"/> PDF
                </a>
            </div>
        @endcan
    </section>

    @include('tenant.shipments._table', ['empty' => 'لا شحنات في هذه المرحلة الآن.', 'sinceStage' => true])

    @include('tenant.shipments._bulk_bar')
@endif
@endsection

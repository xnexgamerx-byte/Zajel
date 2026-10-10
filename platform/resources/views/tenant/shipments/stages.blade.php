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

@if (! $stage)
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($groups as $group)
            <section class="card p-5">
                <div class="mb-3">
                    <h2 class="card-title">{{ $group['label'] }}</h2>
                    <p class="card-hint">{{ $group['hint'] }}</p>
                </div>
                <div class="space-y-2">
                    @foreach ($group['stages'] as $key => $item)
                        @php
                            $days = $item['oldest'] ? (int) $item['oldest']->startOfDay()->diffInDays(today()) : null;
                        @endphp
                        <a href="{{ route('shipments.stages', ['stage' => $key]) }}" class="row-link">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-aeblack-900">{{ $item['label'] }}</span>
                                <span class="block text-xs text-ink-500">
                                    {{ $item['hint'] }}
                                    @if ($item['count'] > 0 && $days !== null && $days > 0)
                                        · <span @class(['font-semibold text-bad-700' => $days >= 3])>أقدمها منذ {{ \App\Support\Arabic::days($days) }}</span>
                                    @endif
                                </span>
                            </span>
                            <span @class(['num shrink-0 text-2xl font-light', 'text-ink-300' => $item['count'] === 0, 'text-aeblack-950' => $item['count'] > 0])>
                                {{ number_format($item['count']) }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@else
    {{-- اللوحة تبقى: كل مرحلةٍ بعدّادها، والمختارة مضيئة — والانتقال بينها لا يُخرج من الصفحة.
         كل مجموعةٍ في إطارها باسمها، والعدّاد بلون حال مرحلته: جارية، تنتظر فعلاً، واصلة — والفارغ باهت --}}
    @php
        $badge = fn (array $item) => $item['count'] === 0 ? 'bg-ink-100 text-ink-400' : match ($item['tone']) {
            'blue'  => 'bg-info-50 text-info-700 ring-1 ring-info-200',
            'amber' => 'bg-warn-50 text-warn-700 ring-1 ring-warn-200',
            'green' => 'bg-ok-50 text-ok-700 ring-1 ring-ok-200',
            // الراجع أحمر (docs/plan/50)
            'red'   => 'bg-bad-50 text-bad-700 ring-1 ring-bad-200',
            default => 'bg-ink-100 text-aeblack-800',
        };
    @endphp
    <nav class="card mb-4 grid grid-cols-1 gap-2 p-2.5 md:grid-cols-2 md:gap-3 md:p-3 xl:grid-cols-3" aria-label="المراحل" data-stage-strip>
        @foreach ($groups as $group)
            @php $here = isset($group['stages'][$stageKey]); @endphp
            <div @class(['min-w-0 rounded-xl border p-2.5 md:rounded-2xl md:p-3', 'border-primary-200 bg-primary-50/40' => $here, 'border-ink-100 bg-ink-50/50' => ! $here])>
                <div class="mb-1.5 flex items-center gap-2 md:mb-2">
                    <span @class(['grid size-6 shrink-0 place-items-center rounded-lg md:size-7', 'bg-primary-600 text-white' => $here, 'bg-white text-primary-600 ring-1 ring-ink-100' => ! $here])>
                        <x-icon :name="$group['icon']" class="size-3.5 md:size-4"/>
                    </span>
                    <span class="text-[13px] font-semibold text-aeblack-900 md:text-sm">{{ $group['label'] }}</span>
                </div>
                {{-- على الهاتف صفٌّ يُمرَّر أفقياً لكل مجموعة: القائمة لا تُدفع إلى أسفل الشاشة --}}
                <div class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 md:flex-wrap md:overflow-visible md:pb-0">
                    @foreach ($group['stages'] as $key => $item)
                        <a href="{{ route('shipments.stages', ['stage' => $key]) }}"
                           @class(['inline-flex h-8 shrink-0 items-center gap-2 rounded-full border ps-3 pe-1 text-[13px] whitespace-nowrap transition md:h-9 md:ps-3.5 md:pe-1.5 md:text-sm',
                                   'border-primary-600 bg-primary-600 font-semibold text-white shadow-[0_10px_20px_-12px_var(--color-primary-600)]' => $key === $stageKey,
                                   'border-ink-200 bg-white text-aeblack-800 hover:border-primary-300 hover:text-primary-700' => $key !== $stageKey && $item['count'] > 0,
                                   'border-ink-100 bg-white/70 text-ink-400 hover:text-ink-600' => $key !== $stageKey && $item['count'] === 0])
                           @if ($key === $stageKey) aria-current="page" @endif>
                            {{ $item['label'] }}
                            <span @class(['num grid h-6 min-w-6 place-items-center rounded-full px-1.5 text-xs font-semibold md:px-2',
                                          'bg-white text-primary-700' => $key === $stageKey, $badge($item) => $key !== $stageKey])>{{ number_format($item['count']) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
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

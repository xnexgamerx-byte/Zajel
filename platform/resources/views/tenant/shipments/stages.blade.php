@extends('layouts.app')
@section('title', 'كل مراحل النقل')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">كل مراحل النقل</h1>
        <p class="mt-1 text-sm text-ink-500">
            أين كل شحنةٍ مفتوحة الآن، مرحلةً مرحلة — وأقدم ما في كل مرحلة. العدد يفتح قائمته.
        </p>
    </div>
    <a href="{{ route('shipments.index') }}" class="btn-ghost">كل الشحنات</a>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
    @foreach ($groups as $key => $group)
        <section class="card p-5">
            <div class="mb-3">
                <h2 class="card-title">{{ $group['label'] }}</h2>
                <p class="card-hint">{{ $group['hint'] }}</p>
            </div>
            <div class="space-y-2">
                @foreach ($group['stages'] as $stageKey => $stage)
                    @php
                        $days = $stage['oldest'] ? (int) $stage['oldest']->startOfDay()->diffInDays(today()) : null;
                    @endphp
                    <a href="{{ route('shipments.index', ['stage' => $stageKey]) }}" class="row-link">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-aeblack-900">{{ $stage['label'] }}</span>
                            <span class="block text-xs text-ink-500">
                                {{ $stage['hint'] }}
                                @if ($stage['count'] > 0 && $days !== null && $days > 0)
                                    · <span @class(['font-semibold text-bad-700' => $days >= 3])>أقدمها منذ {{ \App\Support\Arabic::days($days) }}</span>
                                @endif
                            </span>
                        </span>
                        <span @class(['num shrink-0 text-2xl font-light', 'text-ink-300' => $stage['count'] === 0, 'text-aeblack-950' => $stage['count'] > 0])>
                            {{ number_format($stage['count']) }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
@endsection

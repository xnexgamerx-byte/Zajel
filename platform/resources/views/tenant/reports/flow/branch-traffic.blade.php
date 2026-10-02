@extends('layouts.app')
@section('title', 'شحنات الفروع القادمة والخارجة')

@php
    // عمودٌ لكل ساعةٍ من اليوم، بلونٍ واحد: السؤال حجمٌ لا هويّة
    $hours = range(0, 23);
    $sides = [
        ['title' => 'خرجت — بساعة المغادرة', 'data' => $outByHour, 'verb' => 'خرجت'],
        ['title' => 'وصلت — بساعة الوصول', 'data' => $inByHour, 'verb' => 'وصلت'],
    ];
    $peak = max(1, (int) $outByHour->max(), (int) $inByHour->max());
    $clock = fn (int $h) => sprintf('%02d:00', $h);
    $manifests = fn (int $n) => \App\Support\Arabic::count($n, ['كشف نقل واحد', 'كشفَي نقل', 'كشوف نقل', 'كشف نقل']);
@endphp

@section('content')
@include('tenant.reports.reference._head', ['title' => 'شحنات الفروع القادمة والخارجة', 'blurb' => 'ما خرج من الفرع على كشوف النقل وما وصل إليه، ومع أيّ فرع، وفي أيّ ساعةٍ من اليوم.'])

<x-report-period :period="$period">
    @unless ($limited)
        <div class="min-w-48">
            <label class="field-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id" class="field-input">
                <option value="">كل الفروع</option>
                @foreach ($branches as $option)
                    <option value="{{ $option->id }}" @selected($branch?->id === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
    @endunless
    <p class="ms-auto max-w-80 text-xs text-ink-500">الخارج بوقت مغادرة الكشف، والقادم بوقت وصوله — بتوقيت بغداد.</p>
</x-report-period>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
    <div class="stat">
        <div class="stat-label">خرجت {{ $branch ? 'من '.$branch->name : 'بين الفروع' }}</div>
        <div class="stat-value num">{{ number_format($outTotal) }}</div>
        <div class="mt-1 text-xs text-ink-500">على {{ $manifests($outManifests) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">وصلت {{ $branch ? 'إلى '.$branch->name : 'إلى الفروع' }}</div>
        <div class="stat-value num">{{ number_format($inTotal) }}</div>
        <div class="mt-1 text-xs text-ink-500">على {{ $manifests($inManifests) }}</div>
    </div>
</div>

@if ($outTotal + $inTotal === 0)
    <section class="card p-10 text-center"><p class="text-ink-500">لم يخرج كشف نقلٍ ولم يصل في هذه المدّة.</p></section>
@else
    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($sides as $side)
            <section class="card p-5">
                <h2 class="card-title mb-4">{{ $side['title'] }}</h2>
                {{-- الساعة الأولى يميناً كالصفحة؛ والمحور واحدٌ للرسمين فيُقارَنان --}}
                <div class="flex h-32 items-end gap-0.5 border-b border-ink-300" role="img"
                     aria-label="{{ $side['title'] }} — القيم في الجدول تحت الرسمين">
                    @foreach ($hours as $hour)
                        @php $value = (int) ($side['data'][$hour] ?? 0); @endphp
                        <div class="flex h-full min-w-0 flex-1 flex-col justify-end" data-tip="{{ $clock($hour) }}–{{ sprintf('%02d:59', $hour) }}&#10;{{ $side['verb'] }}: {{ \App\Support\Arabic::shipments($value) }}">
                            @if ($value)
                                <span class="block rounded-t-[4px] bg-[var(--brand)]" style="height: {{ max(2, round($value / $peak * 100, 2)) }}%"></span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-1.5 flex justify-between text-[11px] text-ink-500">
                    @foreach ([0, 6, 12, 18, 23] as $mark)<span class="num">{{ $clock($mark) }}</span>@endforeach
                </div>
                <p class="mt-2 text-xs text-ink-500">أعلى ساعة: {{ \App\Support\Arabic::shipments((int) $side['data']->max()) }}</p>
            </section>
        @endforeach
    </div>

    @if ($overview)
        <section class="card mb-5 overflow-hidden">
            <h2 class="card-title border-b border-ink-100 px-5 py-4">كل فرع</h2>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>الفرع</th><th>خرج منه</th><th>كشوف</th><th>وصل إليه</th><th>كشوف</th></tr></thead>
                    <tbody>
                        @foreach ($overview as $row)
                            <tr>
                                <td class="font-medium">{{ $row->name }}</td>
                                <td class="num">{{ number_format($row->out) }}</td>
                                <td class="num text-ink-500">{{ number_format($row->out_manifests) }}</td>
                                <td class="num">{{ number_format($row->in) }}</td>
                                <td class="num text-ink-500">{{ number_format($row->in_manifests) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ([['خرجت إلى', $outTo], ['وصلت من', $inFrom]] as [$title, $list])
                <section class="card overflow-hidden">
                    <h2 class="card-title border-b border-ink-100 px-5 py-4">{{ $title }}</h2>
                    <table class="tbl">
                        <thead><tr><th>الفرع</th><th>الشحنات</th><th>الكشوف</th></tr></thead>
                        <tbody>
                            @forelse ($list->sortByDesc('shipments') as $branchId => $row)
                                <tr>
                                    <td class="font-medium">{{ $branches[$branchId]->name ?? '—' }}</td>
                                    <td class="num">{{ number_format($row->shipments) }}</td>
                                    <td class="num text-ink-500">{{ number_format($row->manifests) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-8 text-center text-ink-500">لا شيء في هذه المدّة.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>
    @endif

    {{-- الرسمان مكتوبين: كل ساعةٍ فيها حركة --}}
    <details class="card overflow-hidden">
        <summary class="cursor-pointer px-5 py-4 font-medium">القيم بالساعة</summary>
        <table class="tbl">
            <thead><tr><th>الساعة</th><th>خرجت</th><th>وصلت</th></tr></thead>
            <tbody>
                @foreach ($hours as $hour)
                    @continue(! ($outByHour[$hour] ?? 0) && ! ($inByHour[$hour] ?? 0))
                    <tr>
                        <td class="num">{{ $clock($hour) }}</td>
                        <td class="num">{{ number_format((int) ($outByHour[$hour] ?? 0)) }}</td>
                        <td class="num">{{ number_format((int) ($inByHour[$hour] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
@endif
@endsection

@extends('layouts.app')
@section('title', 'توزيع الشحنات بالنتيجة')

@php
    /*
    | جزءٌ من كلّ: أشرطةٌ مكدّسة بأربع نتائج، ألوانها ثابتة لكل نتيجة
    | (FlowReportController::OUTCOMES) ومُتحقَّقٌ منها بمدقّق اللوحة: أسوأ زوجٍ
    | لعمى الألوان ΔE 11.0 وبالرؤية العادية 26.5. والرمادي لـ«ملغاة وغيرها»
    | باهتٌ عمداً — فالجدول تحت الرسم يحمل كل رقمٍ مكتوباً.
    */
    $keys = array_keys($outcomes);
    $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100) : 0;
    $tip = fn (string $title, object $row) => $title."\nالمجموع: ".number_format($row->total)
        .collect($keys)->map(fn ($k) => "\n".$outcomes[$k]['label'].': '.number_format($row->{$k}).' ('.$pct($row->{$k}, $row->total).'%)')->implode('');
@endphp

@section('content')
@include('tenant.reports.reference._head', ['title' => 'توزيع الشحنات بالنتيجة', 'blurb' => 'كيف توزّعت شحنات المدّة بين ما وصل وما رجع وما زال قيد التنفيذ — لكل تاجرٍ من الأكثر حجماً، أو يوماً بيوم لتاجرٍ تختاره.'])

<x-report-period :period="$period">
    <div class="min-w-56">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input">
            <option value="">الأكثر حجماً</option>
            @foreach ($choices as $choice)
                <option value="{{ $choice->id }}" @selected($merchant?->id === $choice->id)>{{ $choice->business_name }}</option>
            @endforeach
        </select>
    </div>
    <p class="ms-auto max-w-80 text-xs text-ink-500">بتاريخ إنشاء الشحنة، ونتيجتها حالها اليوم.</p>
</x-report-period>

{{-- الأرقام الكبرى: لكل نتيجةٍ عددها ونصيبها من الكلّ --}}
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
    <div class="stat col-span-2 lg:col-span-1">
        <div class="stat-label">{{ $merchant ? $merchant->business_name : 'كل الشحنات' }}</div>
        <div class="stat-value num">{{ number_format((int) $totals->total) }}</div>
    </div>
    @foreach ($outcomes as $key => $outcome)
        <div class="stat">
            <div class="stat-label flex items-center gap-2">
                <span class="inline-block size-2.5 shrink-0 rounded-sm" style="background: {{ $outcome['color'] }}"></span>
                {{ $outcome['label'] }}
            </div>
            <div class="stat-value num">{{ number_format((int) $totals->{$key}) }}</div>
            <div class="mt-1 text-xs text-ink-500"><span class="num">{{ $pct((int) $totals->{$key}, (int) $totals->total) }}%</span> من الكلّ</div>
        </div>
    @endforeach
</div>

@if ((int) $totals->total === 0)
    <section class="card p-10 text-center"><p class="text-ink-500">لا شحنات أُنشئت في هذه المدّة{{ $merchant ? ' لهذا التاجر' : '' }}.</p></section>
@else
    <section class="card mb-5 p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="card-title">
                @if ($merchant)
                    {{ $monthly ? 'شهراً بشهر' : 'يوماً بيوم' }}
                @else
                    لكل تاجر — نصيب كل نتيجةٍ من شحناته
                @endif
            </h2>
            {{-- المفتاح: أربع سلاسل، فلا يُترك اللون وحده يقول من هي --}}
            <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-600" aria-label="مفتاح الألوان">
                @foreach ($outcomes as $outcome)
                    <li class="flex items-center gap-1.5">
                        <span class="inline-block size-2.5 rounded-sm" style="background: {{ $outcome['color'] }}"></span>
                        {{ $outcome['label'] }}
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($merchant)
            @php
                // سقفٌ مستدير للمحور: ١ أو ٢ أو ٢٫٥ أو ٥ من مرتبة أعلى عمود
                $peak = max(1, (int) $buckets->max('total'));
                $magnitude = 10 ** floor(log10($peak));
                $ceiling = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($v) => $v >= $peak);
            @endphp

            {{-- الأقدم يميناً كالصفحة، والمحور في البداية. كل عمودٍ مكدّس من القاعدة --}}
            <div class="relative h-56 ps-12" role="img"
                 aria-label="شحنات {{ $merchant->business_name }} {{ $monthly ? 'شهراً بشهر' : 'يوماً بيوم' }} بالنتيجة — القيم في الجدول تحت الرسم">
                @foreach ([0, 0.5, 1] as $step)
                    <div class="pointer-events-none absolute inset-x-0 border-t {{ $step ? 'border-ink-100' : 'border-ink-300' }}"
                         style="bottom: {{ $step * 100 }}%">
                        {{-- الموضع على غلافٍ باتّجاه الصفحة: .num يسار-يمين فكان «البداية» عنده اليسار --}}
                        <span class="absolute start-0 -translate-y-1/2 text-[11px] text-ink-500"><span class="num">{{ number_format($ceiling * $step) }}</span></span>
                    </div>
                @endforeach

                <div class="absolute inset-y-0 start-12 end-0 flex items-end gap-0.5">
                    @foreach ($buckets as $bucket)
                        <div class="flex h-full max-w-6 min-w-0 flex-1 flex-col justify-end" data-tip="{{ $tip($bucket->key, $bucket) }}">
                            @if ($bucket->total)
                                <div class="flex flex-col-reverse gap-0.5 overflow-hidden rounded-t-[4px]"
                                     style="height: {{ round($bucket->total / $ceiling * 100, 2) }}%">
                                    @foreach ($keys as $key)
                                        @continue(! $bucket->{$key})
                                        <span class="block min-h-px" style="flex: {{ $bucket->{$key} }} 1 0%; background: {{ $outcomes[$key]['color'] }}"></span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="mt-2 flex justify-between ps-12 text-xs text-ink-500">
                <span class="num">{{ $buckets->first()->label }}</span>
                <span class="num">{{ $buckets->last()->label }}</span>
            </div>
        @else
            @php $rowsOf = $merchants->when($rest, fn ($c) => $c->push((object) ((array) $rest + ['name' => 'باقي التجّار', 'id' => null]))); @endphp

            <ul class="space-y-3.5">
                @foreach ($rowsOf as $row)
                    <li>
                        <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate {{ $row->id ? 'text-ink-800' : 'text-ink-500' }}">
                                @if ($row->id)
                                    <a href="{{ route('reports.distribution', $period->query() + ['merchant_id' => $row->id]) }}" class="hover:underline">{{ $row->name }}</a>
                                @else
                                    {{ $row->name }}
                                @endif
                            </span>
                            <span class="shrink-0 text-xs text-ink-500">
                                <span class="text-ink-800">{{ \App\Support\Arabic::shipments($row->total) }}</span> ·
                                وصلت <span class="num">{{ $pct($row->delivered, $row->total) }}%</span>
                            </span>
                        </div>
                        {{-- مئويّ: كل شريطٍ كلُّ شحنات تاجره، فتُقارَن النِّسب لا الأحجام --}}
                        <div class="flex h-4 gap-0.5 overflow-hidden rounded-[4px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                             tabindex="0" data-tip="{{ $tip($row->name, $row) }}" aria-label="{{ $tip($row->name, $row) }}">
                            @foreach ($keys as $key)
                                @continue(! $row->{$key})
                                <span class="block min-w-px" style="flex: {{ $row->{$key} }} 1 0%; background: {{ $outcomes[$key]['color'] }}"></span>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- الجدول: كل ما يقوله الرسم مكتوباً، بلا تحويم --}}
    @php $tableRows = $merchant ? $buckets->reverse() : $rowsOf; @endphp
    <section class="card overflow-hidden">
        <h2 class="card-title border-b border-ink-100 px-5 py-4">القيم</h2>
        <div class="max-h-[28rem] overflow-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>{{ $merchant ? ($monthly ? 'الشهر' : 'اليوم') : 'التاجر' }}</th>
                        <th>المجموع</th>
                        @foreach ($outcomes as $outcome)<th>{{ $outcome['label'] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tableRows as $row)
                        <tr>
                            <td class="{{ $merchant ? 'num' : '' }}">{{ $merchant ? $row->key : $row->name }}</td>
                            <td class="num font-semibold whitespace-nowrap {{ $row->total ? '' : 'text-ink-400' }}">{{ number_format($row->total) }}</td>
                            @foreach ($keys as $key)
                                <td class="num whitespace-nowrap {{ $row->{$key} ? '' : 'text-ink-400' }}">
                                    {{ number_format($row->{$key}) }}
                                    @if ($row->{$key})<span class="text-xs text-ink-500">· {{ $pct($row->{$key}, $row->total) }}%</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection

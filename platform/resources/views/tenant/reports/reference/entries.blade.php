@extends('layouts.app')
@section('title', 'عدد الشحنات المُدخلة')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'عدد الشحنات المُدخلة', 'blurb' => 'من أدخل الشحنات وبأيّ قناة، وفي أيّ ساعةٍ من اليوم ببغداد. '.$period->label()])

<x-report-period :period="$period" />

<div class="mb-5 card p-5">
    <div class="mb-3 flex items-baseline justify-between">
        <h2 class="text-sm font-bold">بالساعة</h2>
        <span class="text-xs text-ink-500">المجموع <span class="num font-semibold">{{ number_format($total) }}</span></span>
    </div>
    @php $peak = max(1, $byHour->max() ?? 1); @endphp
    <div class="grid grid-cols-12 gap-1 sm:grid-cols-24" dir="ltr">
        @foreach (range(0, 23) as $h)
            @php $n = (int) ($byHour[$h] ?? 0); @endphp
            <div class="flex flex-col items-center gap-1" title="الساعة {{ $h }}: {{ $n }}">
                <div class="flex h-24 w-full items-end rounded bg-ink-50">
                    <div class="w-full rounded bg-[var(--brand)]" style="height: {{ $n ? max(4, round($n / $peak * 100)) : 0 }}%"></div>
                </div>
                <span class="num text-[10px] text-ink-500">{{ $h }}</span>
            </div>
        @endforeach
    </div>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>من أدخلها</th><th>القناة</th><th>العدد</th><th>أكثر ساعاته</th></tr>
            </thead>
            <tbody>
                @forelse ($people as $person)
                    @php arsort($person->hours); $top = array_slice($person->hours, 0, 3, true); @endphp
                    <tr>
                        <td class="font-medium">{{ $names[$person->user_id] ?? '—' }}</td>
                        <td class="text-sm">{{ \App\Http\Controllers\Tenant\ReferenceReportController::SOURCES[$person->source] ?? $person->source }}</td>
                        <td class="num font-semibold">{{ number_format($person->total) }}</td>
                        <td class="text-xs text-ink-600">
                            @foreach ($top as $h => $n)
                                <span class="chip chip-mute me-1"><span class="num">{{ $h }}:00</span> · <span class="num">{{ $n }}</span></span>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-12 text-center text-ink-500">لم تُدخل شحناتٌ في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

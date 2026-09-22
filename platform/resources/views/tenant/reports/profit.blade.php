@extends('layouts.app')
@section('title', 'أرباح الشحنات')

@section('content')
<x-report-shell title="أرباح الشحنات"
                question="ما دخل من أجور وما خرج عمولاتٍ للمندوبين."
                :period="$period"
                basis="تُحسب بالشحنات التي أُقفلت في المدّة: وصلت أو رجعت.">

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="stat">
            <div class="stat-label">شحنات مُقفَلة</div>
            <div class="num mt-1 text-2xl font-bold">{{ number_format($totals->total) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">أجورنا</div>
            <div class="num mt-1 text-2xl font-bold text-ok-700">{{ number_format($totals->revenue) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">عمولات المندوبين</div>
            <div class="num mt-1 text-2xl font-bold text-warn-700">{{ number_format($totals->commission) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">الصافي</div>
            <div class="num mt-1 text-2xl font-bold {{ $totals->net < 0 ? 'text-bad-700' : 'text-[var(--brand)]' }}">
                {{ number_format($totals->net) }}
            </div>
        </div>
    </div>

    @if ($rows->isEmpty())
        <section class="card p-10 text-center"><p class="text-ink-500">لا شحنات أُقفلت في هذه المدّة.</p></section>
    @else
        <section class="card overflow-hidden">
            <h2 class="card-title border-b border-ink-100 px-5 py-4">شهراً بشهر</h2>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الشهر</th>
                            <th>شحنات</th>
                            <th>أجورنا</th>
                            <th>عمولات</th>
                            <th>الصافي</th>
                            <th>متوسّط الصافي للشحنة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="num font-semibold">{{ $row->month }}</td>
                                <td class="num">{{ number_format($row->total) }}</td>
                                <td class="num text-ok-700">{{ number_format($row->revenue) }}</td>
                                <td class="num text-warn-700">{{ number_format($row->commission) }}</td>
                                <td class="num font-semibold {{ $row->net < 0 ? 'text-bad-700' : '' }}">
                                    {{ number_format($row->net) }}
                                </td>
                                <td class="num text-ink-600">
                                    {{ $row->total ? number_format(round($row->net / $row->total)) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-report-shell>
@endsection

@extends('layouts.app')
@section('title', 'محاسبة الفروع')

@section('content')
<x-report-shell title="محاسبة الفروع"
                question="أيّ فرع يكسب، وأيّهما يجمع نقداً ليس له."
                :period="$period"
                basis="الإيراد بما أُقفل في المدّة، والمصروف بتاريخه، والنقد رصيداً الآن.">

    @php
        $totals = ['revenue' => 0, 'commission' => 0, 'expenses' => 0, 'cash' => 0];
    @endphp

    <section class="card mb-5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الفرع</th>
                        <th>شحنات مُقفَلة</th>
                        <th>أجورنا</th>
                        <th>عمولات</th>
                        <th>مصروفات</th>
                        <th>الصافي</th>
                        <th>نقدٌ في صناديقه</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($branches as $branch)
                        @php
                            $row = $revenue[$branch->id] ?? null;
                            $rev = (int) ($row->revenue ?? 0);
                            $com = (int) ($row->commission ?? 0);
                            $exp = (int) ($expenses[$branch->id] ?? 0);
                            $net = $rev - $com - $exp;
                            $totals['revenue'] += $rev; $totals['commission'] += $com;
                            $totals['expenses'] += $exp; $totals['cash'] += (int) ($cash[$branch->id] ?? 0);
                        @endphp
                        <tr>
                            <td class="font-medium">
                                {{ $branch->name }}
                                @if ($branch->is_main)
                                    <span class="chip chip-mute ms-1">رئيسي</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($row->shipments ?? 0) }}</td>
                            <td class="num text-ok-700">{{ number_format($rev) }}</td>
                            <td class="num text-warn-700">{{ number_format($com) }}</td>
                            <td class="num text-warn-700">{{ number_format($exp) }}</td>
                            <td class="num font-bold {{ $net < 0 ? 'text-bad-700' : 'text-[var(--brand)]' }}">
                                {{ number_format($net) }}
                            </td>
                            <td class="num">{{ number_format($cash[$branch->id] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-ink-300 font-bold">
                        <td>المجموع</td>
                        <td></td>
                        <td class="num">{{ number_format($totals['revenue']) }}</td>
                        <td class="num">{{ number_format($totals['commission']) }}</td>
                        <td class="num">{{ number_format($totals['expenses']) }}</td>
                        <td class="num">{{ number_format($totals['revenue'] - $totals['commission'] - $totals['expenses']) }}</td>
                        <td class="num">{{ number_format($totals['cash']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section class="card p-5">
        <h2 class="card-title">ديون بين الفروع</h2>
        <p class="card-hint mb-4">
            فرعٌ وصّل شحنةً يملكها تاجرُ فرعٍ آخر: النقد عنده والمستحقّ على الآخر.
        </p>

        @if ($debts->isEmpty())
            <p class="rounded-lg bg-ink-50 px-3 py-3 text-sm text-ink-600">
                لا تقاطع بين الفروع في هذه المدّة — كل فرع وصّل شحنات تجّاره.
            </p>
        @else
            @php $names = $branches->pluck('name', 'id'); @endphp
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>الفرع الجامع</th><th>لحساب فرع</th><th>شحنات</th><th>المبلغ المجموع</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($debts as $debt)
                            <tr>
                                <td class="font-medium">{{ $names[$debt->collector] ?? '—' }}</td>
                                <td class="text-ink-600">{{ $names[$debt->owner] ?? '—' }}</td>
                                <td class="num">{{ number_format($debt->shipments) }}</td>
                                <td class="num font-semibold text-warn-700">{{ number_format($debt->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-report-shell>
@endsection

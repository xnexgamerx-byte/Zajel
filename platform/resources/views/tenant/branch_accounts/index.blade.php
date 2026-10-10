@extends('layouts.app')
@section('title', 'محاسبة الفروع')

@section('content')
<x-report-shell title="محاسبة الفروع"
                question="أيّ فرع يكسب، وأيّهما يجمع نقداً ليس له."
                :period="$period"
                basis="الإيراد بما أُقفل في المدّة، والمصروف بتاريخه، والنقد رصيداً الآن.">

    @php
        $totals = ['revenue' => 0, 'earned' => 0, 'commission' => 0, 'branch_paid' => 0, 'expenses' => 0, 'cash' => 0];
    @endphp

    <section class="card mb-5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الفرع</th>
                        <th>شحنات مُقفَلة</th>
                        <th>أجورنا</th>
                        <th>عمولة الفرع له</th>
                        <th>عمولات المناديب</th>
                        <th>عمولات فروعٍ وصّلت</th>
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
                            $mine = $earned[$branch->id] ?? null;
                            $got = (int) ($mine->earned ?? 0);
                            // عمولات مناديبه: ما وصّلوه لفرعه بلا عمولة فرع، وما وصّلوه بعمولته هو
                            $com = (int) ($row->commission ?? 0) + (int) ($mine->couriers ?? 0);
                            $paid = (int) ($row->branch_paid ?? 0);
                            $exp = (int) ($expenses[$branch->id] ?? 0);
                            $net = $rev + $got - $com - $paid - $exp;
                            $totals['revenue'] += $rev; $totals['commission'] += $com;
                            $totals['earned'] += $got; $totals['branch_paid'] += $paid;
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
                            <td class="num text-ok-700">{{ $got ? number_format($got) : '—' }}</td>
                            <td class="num text-warn-700">{{ number_format($com) }}</td>
                            <td class="num text-warn-700">{{ $paid ? number_format($paid) : '—' }}</td>
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
                        <td class="num">{{ number_format($totals['earned']) }}</td>
                        <td class="num">{{ number_format($totals['commission']) }}</td>
                        <td class="num">{{ number_format($totals['branch_paid']) }}</td>
                        <td class="num">{{ number_format($totals['expenses']) }}</td>
                        <td class="num">{{ number_format($totals['revenue'] + $totals['earned'] - $totals['commission'] - $totals['branch_paid'] - $totals['expenses']) }}</td>
                        <td class="num">{{ number_format($totals['cash']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    {{--
        عمولة الفروع وأرباحها (docs/plan/51): ما اتّفقت عليه الشركة مع الفرع عن كلّ طلبٍ وصّله
        مناديبه، وما دفعه منها لمناديبه، والفرق ربحه — ويُطرح ممّا يسدّده للشركة.
    --}}
    @if ($earned->isNotEmpty())
        <section class="card mb-5 p-5" data-branch-profit>
            <h2 class="card-title">عمولة الفروع وأرباحها</h2>
            <p class="card-hint mb-4">عن كلّ طلبٍ وصّله مناديب الفرع: عمولته من الشركة، ناقصاً عمولة مندوبه، يبقى ربحه.</p>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>الفرع</th><th>طلبات وصّلها</th><th>عمولته</th><th>عمولات مناديبه</th><th>ربح الفرع</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($branches as $branch)
                            @continue(! isset($earned[$branch->id]))
                            @php $e = $earned[$branch->id]; @endphp
                            <tr>
                                <td class="font-medium">{{ $branch->name }}</td>
                                <td class="num">{{ number_format($e->shipments) }}</td>
                                <td class="num text-ok-700">{{ number_format($e->earned) }}</td>
                                <td class="num text-warn-700">{{ number_format($e->couriers) }}</td>
                                <td class="num font-bold {{ $e->earned - $e->couriers < 0 ? 'text-bad-700' : 'text-[var(--brand)]' }}">{{ number_format($e->earned - $e->couriers) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card p-5">
        <h2 class="card-title">ديون بين الفروع</h2>
        <p class="card-hint mb-4">
            فرعٌ وصّل شحنةً يملكها تاجرُ فرعٍ آخر: النقد عنده، وعليه للآخر ناقصاً عمولته عن كلّ طلب.
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
                        <tr><th>الفرع الجامع</th><th>لحساب فرع</th><th>شحنات</th><th>المحصَّل</th><th>عمولة الفرع</th><th>عليه</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($debts as $debt)
                            <tr>
                                <td class="font-medium">{{ $names[$debt->collector] ?? '—' }}</td>
                                <td class="text-ink-600">{{ $names[$debt->owner] ?? '—' }}</td>
                                <td class="num">{{ number_format($debt->shipments) }}</td>
                                <td class="num">{{ number_format($debt->collected) }}</td>
                                <td class="num text-ok-700">{{ $debt->commission ? '− '.number_format($debt->commission) : '—' }}</td>
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

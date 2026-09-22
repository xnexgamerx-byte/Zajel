@extends('layouts.app')
@section('title', 'أداء التجّار')

@section('content')
<x-report-shell title="أداء التجّار"
                question="حجم كل تاجر ونسبة راجعه."
                :period="$period"
                basis="تُحسب بشحنات أُنشئت في المدّة ومصيرها حتى الآن.">

    @if ($rows->isEmpty())
        <section class="card p-10 text-center"><p class="text-ink-500">لا شحنات في هذه المدّة.</p></section>
    @else
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>التاجر</th>
                            <th>الشحنات</th>
                            <th>وصلت</th>
                            <th>رجعت</th>
                            <th>ما تزال مفتوحة</th>
                            <th>نسبة الراجع</th>
                            <th>المبالغ</th>
                            <th>أجورنا</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $closed = $row->delivered + $row->returned;
                                $rate = $closed > 0 ? round($row->returned / $closed * 100) : 0;
                            @endphp
                            <tr>
                                <td class="max-w-48 truncate font-medium">{{ $row->name }}</td>
                                <td class="num font-semibold">{{ number_format($row->total) }}</td>
                                <td class="num text-ok-700">{{ number_format($row->delivered) }}</td>
                                <td class="num text-warn-700">{{ number_format($row->returned) }}</td>
                                <td class="num text-ink-500">{{ number_format($row->still_open) }}</td>
                                <td>
                                    {{-- النسبة من المُقفَل لا من الإجمالي: الشحنة المفتوحة لم يُعرَف مصيرها بعد --}}
                                    <span class="chip {{ $rate >= 20 ? 'chip-warn' : 'chip-mute' }}">
                                        {{ $closed ? $rate.'%' : '—' }}
                                    </span>
                                </td>
                                <td class="num">{{ number_format($row->cod) }}</td>
                                <td class="num text-ink-600">{{ number_format($row->fees) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-report-shell>
@endsection

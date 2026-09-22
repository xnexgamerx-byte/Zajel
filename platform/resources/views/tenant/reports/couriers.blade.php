@extends('layouts.app')
@section('title', 'أداء المندوبين')

@section('content')
<x-report-shell title="أداء المندوبين"
                question="مَن يوصّل ومَن يُرجع، وكم حصّل."
                :period="$period"
                basis="تُحسب بما أُنجز في المدّة لا بما أُنشئ فيها.">

    @if ($rows->isEmpty())
        <section class="card p-10 text-center"><p class="text-ink-500">لا نشاط في هذه المدّة.</p></section>
    @else
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>المندوب</th>
                            <th>وصلت</th>
                            <th>رجعت</th>
                            <th>فشلت</th>
                            <th>نسبة النجاح</th>
                            <th>حصّل</th>
                            <th>عمولته</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $rate = $row->total > 0 ? round($row->delivered / $row->total * 100) : 0; @endphp
                            <tr>
                                <td class="font-medium">{{ $row->name }}</td>
                                <td class="num text-ok-700">{{ number_format($row->delivered) }}</td>
                                <td class="num text-warn-700">{{ number_format($row->returned) }}</td>
                                <td class="num text-ink-600">{{ number_format($row->failed) }}</td>
                                <td class="w-40">
                                    <div class="mb-1 flex justify-between text-xs">
                                        <span class="num font-semibold">{{ $rate }}%</span>
                                        <span class="num text-ink-500">{{ number_format($row->total) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-ink-100">
                                        <div class="h-full rounded-full bg-ok-700" style="width: {{ $rate }}%"></div>
                                    </div>
                                </td>
                                <td class="num">{{ number_format($row->collected) }}</td>
                                <td class="num text-ink-600">{{ number_format($row->commission) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-report-shell>
@endsection

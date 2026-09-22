@extends('layouts.app')
@section('title', 'الأداء بالمحافظات')

@section('content')
<x-report-shell title="الأداء بالمحافظات"
                question="أين ننجح وأين نفشل جغرافياً."
                :period="$period"
                basis="تُحسب بشحنات أُنشئت في المدّة ومصيرها حتى الآن.">

    @if ($rows->isEmpty())
        <section class="card p-10 text-center"><p class="text-ink-500">لا شحنات في هذه المدّة.</p></section>
    @else
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <section class="card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>المحافظة</th>
                                    <th>الشحنات</th>
                                    <th>وصلت</th>
                                    <th>رجعت</th>
                                    <th>نسبة النجاح</th>
                                    <th>أجور التوصيل</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    @php
                                        $closed = $row->delivered + $row->returned;
                                        $rate = $closed > 0 ? round($row->delivered / $closed * 100) : null;
                                    @endphp
                                    <tr>
                                        <td class="font-medium">{{ $row->name }}</td>
                                        <td class="num font-semibold">{{ number_format($row->total) }}</td>
                                        <td class="num text-ok-700">{{ number_format($row->delivered) }}</td>
                                        <td class="num text-warn-700">{{ number_format($row->returned) }}</td>
                                        <td class="w-40">
                                            @if ($rate === null)
                                                <span class="text-sm text-ink-400">لم يُقفَل شيء بعد</span>
                                            @else
                                                <div class="mb-1 flex justify-between text-xs">
                                                    <span class="num font-semibold">{{ $rate }}%</span>
                                                    <span class="num text-ink-500">من {{ number_format($closed) }}</span>
                                                </div>
                                                <div class="h-2 overflow-hidden rounded-full bg-ink-100">
                                                    <div class="h-full rounded-full bg-ok-700" style="width: {{ $rate }}%"></div>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="num text-ink-600">{{ number_format($row->fees) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="card h-fit p-5">
                <h2 class="card-title">الحجم بالمحافظة</h2>
                <p class="card-hint mb-4">أين تتركّز الشحنات.</p>
                <div class="space-y-3">
                    @foreach ($rows->take(10) as $row)
                        <x-bar-row :label="$row->name" :value="$row->total" :max="$rows->max('total')" />
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</x-report-shell>
@endsection

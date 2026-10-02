@extends('layouts.app')
@section('title', 'أداء مندوبي الاستلام')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'أداء مندوبي الاستلام', 'blurb' => 'مصير ما استلمه كل مندوب استلامٍ من التجّار: كم وصل وكم رجع وكم ما زال في الطريق'.($money ? '، ومعه ما استحقّه عن الاستلام وما دُفع له.' : '.')])

<x-report-period :period="$period">
    <div class="min-w-48">
        <label class="field-label" for="courier_id">مندوب الاستلام</label>
        <select id="courier_id" name="courier_id" class="field-input">
            <option value="">الكل</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <p class="ms-auto max-w-80 text-xs text-ink-500">
        الشحنات بتاريخ استلامها من التاجر، فتُنسب نتيجتها إلى من استلمها.
        @if ($money) والأرباح بتاريخ احتسابها، والمدفوع بتاريخ دفعه. @endif
    </p>
</x-report-period>

<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>مندوب الاستلام</th>
                    <th>استلم</th>
                    <th>وصلت</th>
                    <th>رجعت</th>
                    <th>قيد التوصيل</th>
                    <th>مؤجّلة</th>
                    <th>نسبة الوصول</th>
                    @if ($money)
                        <th>استحقّ</th>
                        <th>دُفع له</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $rate = $row->total > 0 ? round($row->delivered / $row->total * 100) : 0; @endphp
                    <tr>
                        <td class="font-medium">{{ $row->name }}</td>
                        <td class="num">{{ number_format($row->total) }}</td>
                        <td class="num text-ok-700">{{ number_format($row->delivered) }}</td>
                        <td class="num text-warn-700">{{ number_format($row->returned) }}</td>
                        <td class="num">{{ number_format($row->in_delivery) }}</td>
                        <td class="num">{{ number_format($row->postponed) }}</td>
                        <td class="w-40">
                            @if ($row->total)
                                <div class="mb-1 text-xs"><span class="num font-semibold">{{ $rate }}%</span></div>
                                <div class="h-2 overflow-hidden rounded-full bg-ink-100">
                                    <div class="h-full rounded-full bg-ok-700" style="width: {{ $rate }}%"></div>
                                </div>
                            @else
                                <span class="text-ink-400">—</span>
                            @endif
                        </td>
                        @if ($money)
                            <td class="num">{{ number_format($row->earned) }}</td>
                            <td class="num text-ink-600">{{ number_format($row->paid) }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $money ? 9 : 7 }}" class="px-4 py-12 text-center text-ink-500">لا نشاط لمندوبي الاستلام في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
            @if ($rows->count() > 1)
                <tfoot>
                    <tr>
                        <td>المجموع</td>
                        <td class="num">{{ number_format($totals->total) }}</td>
                        <td class="num">{{ number_format($totals->delivered) }}</td>
                        <td class="num">{{ number_format($totals->returned) }}</td>
                        <td class="num">{{ number_format($totals->in_delivery) }}</td>
                        <td class="num">{{ number_format($totals->postponed) }}</td>
                        <td class="num">{{ $totals->total ? round($totals->delivered / $totals->total * 100) : 0 }}%</td>
                        @if ($money)
                            <td class="num">{{ number_format($totals->earned) }}</td>
                            <td class="num">{{ number_format($totals->paid) }}</td>
                        @endif
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
@endsection

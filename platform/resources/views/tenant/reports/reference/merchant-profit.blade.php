@extends('layouts.app')
@section('title', 'الأرباح حسب التاجر')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'الأرباح حسب التاجر', 'blurb' => '«الأرباح على أساس العملاء»: لكل تاجرٍ ما دخل من أجور شحناته المغلقة في المدّة وما خرج عمولاتٍ عليها، الأربح أوّلاً. '.$period->label()])

<x-report-period :period="$period" />

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>التاجر</th><th>الناجحة</th><th>الراجعة</th><th>الأجور</th><th>العمولات</th><th>الربح</th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $net = (int) $row->revenue - (int) $row->commission; @endphp
                    <tr>
                        <td class="font-medium">{{ $names[$row->merchant_id] ?? '—' }}</td>
                        <td class="num">{{ number_format($row->delivered) }}</td>
                        <td class="num text-ink-500">{{ number_format($row->returned) }}</td>
                        <td class="num">{{ number_format($row->revenue) }}</td>
                        <td class="num text-bad-700">{{ number_format($row->commission) }}</td>
                        <td class="num font-bold {{ $net >= 0 ? 'text-ok-700' : 'text-bad-700' }}">{{ number_format($net) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-ink-500">لا شحنات مغلقة في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($rows->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $rows->links() }}</div>
    @endif
</div>
@endsection

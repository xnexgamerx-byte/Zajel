@extends('layouts.app')
@section('title', 'دفعات لم يؤكَّد استلامها')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'دفعات لم يؤكَّد استلامها', 'blurb' => '«عملاء لم يؤكّدوا دفعات» و«مندوبو استلام لم يؤكّدوا دفعات»: ما دُفع ولم يضغط صاحبه «استلمتُها» — يُسأل قبل أن يصير الخلاف شهراً.'])

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">التجّار</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>التاجر</th><th>دفعاتٌ غير مؤكَّدة</th><th>مجموعها</th><th>آخرها</th></tr></thead>
            <tbody>
                @forelse ($merchants as $row)
                    <tr>
                        <td class="font-medium">{{ $merchantNames[$row->merchant_id] ?? '—' }}</td>
                        <td class="num">{{ number_format($row->payments) }}</td>
                        <td class="num font-semibold">{{ number_format($row->total) }}</td>
                        <td class="num text-xs text-ink-500">{{ \Illuminate\Support\Carbon::parse($row->last_paid)->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-ink-500">كل ما دُفع للتجّار أكّدوه.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">مندوبو الاستلام</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>مندوب الاستلام</th><th>دفعاتٌ غير مؤكَّدة</th><th>مجموعها</th><th>آخرها</th></tr></thead>
            <tbody>
                @forelse ($couriers as $row)
                    <tr>
                        <td class="font-medium">{{ $courierNames[$row->courier_id] ?? '—' }}</td>
                        <td class="num">{{ number_format($row->payments) }}</td>
                        <td class="num font-semibold">{{ number_format($row->total) }}</td>
                        <td class="num text-xs text-ink-500">{{ \Illuminate\Support\Carbon::parse($row->last_paid)->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-ink-500">كل دفعات الأرباح أكّدها أصحابها.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

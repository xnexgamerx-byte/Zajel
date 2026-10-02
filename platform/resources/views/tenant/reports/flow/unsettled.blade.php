@extends('layouts.app')
@section('title', 'واصلة لم يُحاسَب عليها التجّار')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'واصلة لم يُحاسَب عليها التجّار', 'blurb' => 'ما سُلّم للزبون وبقي ماله عندنا: لكل تاجرٍ عدد وصولاته وما حُصّل منها والصافي له بعد الأجور، الأقدم أوّلاً.'])

<x-report-period>
    <div class="min-w-48">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input">
            <option value="">كل التجّار</option>
            @foreach ($merchants as $merchant)
                <option value="{{ $merchant->id }}" @selected($merchantId === $merchant->id)>{{ $merchant->business_name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="days">سُلّمت قبل أكثر من</label>
        <div class="flex items-center gap-2">
            <input id="days" name="days" type="number" min="0" max="3650" class="field-input num w-24" value="{{ $days ?: '' }}" placeholder="0">
            <span class="text-sm text-ink-500">يوم</span>
        </div>
    </div>
    <p class="ms-auto max-w-80 text-xs text-ink-500">حالٌ لا مدّة: ما لم يُسوَّ يبقى هنا مهما قدُم، ويخرج حين يدخل كشف تسوية تاجره.</p>
</x-report-period>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="stat">
        <div class="stat-label">وصولات لم يُحاسَب عليها</div>
        <div class="stat-value num">{{ number_format((int) $totals->shipments) }}</div>
        <div class="mt-1 text-xs text-ink-500">عند {{ \App\Support\Arabic::merchants($rows->total()) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">المحصَّل منها</div>
        <div class="stat-value num">{{ number_format((int) $totals->collected) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">الصافي للتجّار بعد الأجور</div>
        <div class="stat-value num">{{ number_format((int) $totals->net) }}</div>
        @if ($totals->oldest)
            <div class="mt-1 text-xs text-ink-500">أقدمها منذ {{ \App\Support\Arabic::days((int) \Illuminate\Support\Carbon::parse($totals->oldest)->diffInDays(now())) }}</div>
        @endif
    </div>
</div>

<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>التاجر</th><th>الوصولات</th><th>المحصَّل</th><th>الصافي له</th><th>أقدمها سُلّم</th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $oldest = $row->oldest ? \Illuminate\Support\Carbon::parse($row->oldest) : null; @endphp
                    <tr>
                        <td class="font-medium">
                            @can('settings.merchants')
                                <a href="{{ route('merchants.show', $row->merchant_id) }}" class="hover:text-[var(--brand)] hover:underline">{{ $names[$row->merchant_id] ?? '—' }}</a>
                            @else
                                {{ $names[$row->merchant_id] ?? '—' }}
                            @endcan
                        </td>
                        <td class="num">{{ number_format((int) $row->shipments) }}</td>
                        <td class="num">{{ number_format((int) $row->collected) }}</td>
                        <td class="num font-semibold">{{ number_format((int) $row->net) }}</td>
                        <td class="num text-xs whitespace-nowrap text-ink-500">
                            @if ($oldest)
                                {{ $oldest->format('Y-m-d') }}
                                <span class="block {{ $oldest->diffInDays(now()) >= 7 ? 'font-semibold text-warn-700' : '' }}">منذ {{ \App\Support\Arabic::days((int) $oldest->diffInDays(now())) }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-ink-500">لا واصل ينتظر محاسبة تاجره بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($rows->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $rows->links() }}</div>
    @endif
</section>

@can('money.view')
    <p class="mt-4 text-sm text-ink-500">
        تُحاسب التجّار من <a href="{{ route('settlements.merchants.index') }}" class="font-medium text-[var(--brand)] hover:underline">تسوية التجّار</a>.
    </p>
@endcan
@endsection

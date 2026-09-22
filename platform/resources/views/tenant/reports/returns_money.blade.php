@extends('layouts.app')
@section('title', 'مال الرواجع')

@section('content')
<x-report-shell title="مال الرواجع"
                question="ماذا أكسبنا الراجع بعد عمولته، ومَن يُكلّفنا أكثر، وكم أجرةً معلّقة."
                :period="$period"
                basis="تُحسب بما سُلِّم للتاجر راجعاً في المدّة — فعندها تُقيَّد الأجرة والعمولة.">

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="stat">
            <span class="stat-label">رواجع سُلِّمت</span>
            <span class="stat-value num">{{ number_format($totals->total) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">أجور رجوع قُيِّدت</span>
            <span class="stat-value num text-ok-700">{{ number_format($totals->fees) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">عمولات إرجاع</span>
            <span class="stat-value num text-warn-700">{{ number_format($totals->commission) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">الصافي</span>
            <span class="stat-value num {{ $totals->net < 0 ? 'text-bad-700' : '' }}">{{ number_format($totals->net) }}</span>
        </div>
    </div>

    @if ($totals->free > 0)
        {{-- راجعٌ بلا أجرة: عمولته خرجت ولا شيء قابلها --}}
        <div class="card mb-5 border-warn-200 bg-warn-50 p-4 text-sm text-warn-700">
            <strong class="num">{{ number_format($totals->free) }}</strong>
            من الرواجع سُلِّمت بلا أجرة رجوع — دفعنا عمولتها ولم نأخذ عنها شيئاً. راجِع تسعيرات تجّارها.
        </div>
    @endif

    <section class="card mb-5">
        <div class="border-b border-ink-200 px-5 py-4">
            <h2 class="card-title">معلّقٌ الآن</h2>
            <p class="card-hint">رواجع لم تُسلَّم لتجّارها بعد، فأجرتها لم تُقيَّد — أيّاً كانت المدّة المختارة.</p>
        </div>
        <div class="grid grid-cols-2 gap-4 p-5 md:grid-cols-5">
            <div>
                <div class="text-xs text-ink-500">العدد</div>
                <div class="num text-lg font-bold">{{ number_format($pending->total) }}</div>
            </div>
            <div>
                <div class="text-xs text-ink-500">أجرة غير مُقيَّدة</div>
                <div class="num text-lg font-bold text-warn-700">{{ number_format($pending->fees) }}</div>
            </div>
            <a href="{{ route('returns.incoming') }}" class="rounded-lg transition hover:bg-ink-50">
                <div class="text-xs text-ink-500">بيد المناديب</div>
                <div class="num text-lg font-bold">{{ number_format($pending->with_courier) }}</div>
            </a>
            <a href="{{ route('returns.outgoing') }}" class="rounded-lg transition hover:bg-ink-50">
                <div class="text-xs text-ink-500">على الرفّ</div>
                <div class="num text-lg font-bold">{{ number_format($pending->on_shelf) }}</div>
            </a>
            <a href="{{ route('returns.sorting') }}" class="rounded-lg transition hover:bg-ink-50">
                <div class="text-xs text-ink-500">في أكياس</div>
                <div class="num text-lg font-bold">{{ number_format($pending->in_bag) }}</div>
            </a>
        </div>
        @if ($pending->oldest)
            @php $days = (int) $pending->oldest->diffInDays(now()); @endphp
            <p class="border-t border-ink-100 px-5 py-3 text-sm text-ink-600">
                أقدمها معلّقٌ منذ
                <span class="chip {{ $days >= 14 ? 'chip-bad' : ($days >= 3 ? 'chip-warn' : 'chip-mute') }}">
                    {{ $days === 0 ? 'اليوم' : \App\Support\Arabic::days($days) }}
                </span>
            </p>
        @endif
    </section>

    <section class="card overflow-hidden">
        <div class="border-b border-ink-200 px-5 py-4">
            <h2 class="card-title">مَن يُكلّف أكثر</h2>
            <p class="card-hint">التجّار بعدد رواجعهم في المدّة. والنسبة من المُقفَل لا من الإجمالي.</p>
        </div>
        @if ($merchants->isEmpty())
            <p class="p-10 text-center text-ink-500">لا رواجع سُلِّمت في هذه المدّة.</p>
        @else
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>التاجر</th>
                            <th>رواجع</th>
                            <th>نسبة الرجوع</th>
                            <th>أجورها</th>
                            <th>عمولاتها</th>
                            <th>الصافي</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($merchants as $row)
                            <tr>
                                <td class="max-w-48 truncate font-medium">{{ $row->name }}</td>
                                <td class="num font-semibold">{{ number_format($row->returned) }}</td>
                                <td>
                                    <span class="chip {{ $row->rate >= 30 ? 'chip-bad' : ($row->rate >= 15 ? 'chip-warn' : 'chip-mute') }}">{{ $row->rate }}%</span>
                                </td>
                                <td class="num text-ok-700">{{ number_format($row->fees) }}</td>
                                <td class="num text-warn-700">{{ number_format($row->commission) }}</td>
                                <td class="num font-semibold {{ $row->net < 0 ? 'text-bad-700' : '' }}">{{ number_format($row->net) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-report-shell>
@endsection

{{-- كشف الحساب نفسه — مشترَكٌ بين الشاشة والورقة، فلا يفترقان أبداً --}}
<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="stat avoid-break">
        <span class="stat-label">رصيدٌ افتتاحي</span>
        <span class="stat-value num">{{ number_format($totals->opening) }}</span>
    </div>
    <div class="stat avoid-break">
        <span class="stat-label">وارد</span>
        <span class="stat-value num text-ok-700">{{ number_format($totals->in) }}</span>
    </div>
    <div class="stat avoid-break">
        <span class="stat-label">صادر</span>
        <span class="stat-value num text-warn-700">{{ number_format($totals->out) }}</span>
    </div>
    <div class="stat avoid-break">
        <span class="stat-label">رصيدٌ ختامي</span>
        <span class="stat-value num">{{ number_format($totals->closing) }}</span>
    </div>
</div>

@if ($check)
    @if ($check->drift === 0)
        <p class="mb-5 text-sm text-ok-700">
            الختاميّ يطابق ما في صناديق الفرع الآن (<span class="num">{{ number_format($check->held) }}</span>).
        </p>
    @else
        {{-- فرقٌ بين الدفتر والصندوق يعني حركةً لم تمرّ بالدفتر: يُقال ولا يُخفى --}}
        <div class="card mb-5 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">
            الختاميّ <span class="num">{{ number_format($totals->closing) }}</span>
            وما في الصناديق <span class="num">{{ number_format($check->held) }}</span> —
            فرقٌ بمقدار <strong class="num">{{ number_format($check->drift) }}</strong>. راجِع جرد الصناديق من القاصة.
        </div>
    @endif
@endif

@if ($summary->isNotEmpty())
    <section class="card mb-5 overflow-hidden avoid-break">
        <div class="border-b border-ink-200 px-5 py-3">
            <h2 class="card-title">بحسب النوع</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>النوع</th><th>الحركات</th><th>وارد</th><th>صادر</th></tr>
                </thead>
                <tbody>
                    @foreach ($summary as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num text-ink-600">{{ number_format($row['count']) }}</td>
                            <td class="num text-ok-700">{{ $row['in'] ? number_format($row['in']) : '—' }}</td>
                            <td class="num text-warn-700">{{ $row['out'] ? number_format($row['out']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الوقت</th>
                    <th>الصندوق</th>
                    <th>البيان</th>
                    <th>وارد</th>
                    <th>صادر</th>
                    <th>الرصيد</th>
                </tr>
            </thead>
            <tbody>
                <tr class="bg-ink-50 font-semibold">
                    <td colspan="5">رصيدٌ افتتاحي في {{ $period->from->format('Y-m-d') }}</td>
                    <td class="num">{{ number_format($totals->opening) }}</td>
                </tr>
                @forelse ($movements as $movement)
                    <tr class="{{ $movement->internal ? 'text-ink-400' : '' }}">
                        <td class="num whitespace-nowrap text-xs">
                            {{ $movement->created_at->format('Y-m-d') }}
                            <span class="block text-ink-400">{{ $movement->created_at->format('H:i') }}</span>
                        </td>
                        <td class="text-xs">{{ $movement->cashBox?->name }}</td>
                        <td class="max-w-72 text-sm">
                            <span class="font-medium">{{ $movement->categoryLabel() }}</span>
                            @if ($movement->internal)
                                <span class="chip chip-mute ms-1">داخل الفرع</span>
                            @endif
                            @if ($movement->description)
                                <span class="block truncate text-xs text-ink-500" title="{{ $movement->description }}">{{ $movement->description }}</span>
                            @endif
                        </td>
                        <td class="num {{ $movement->internal ? '' : 'text-ok-700' }}">{{ $movement->direction === 'in' ? number_format($movement->amount) : '' }}</td>
                        <td class="num {{ $movement->internal ? '' : 'text-warn-700' }}">{{ $movement->direction === 'out' ? number_format($movement->amount) : '' }}</td>
                        <td class="num font-semibold">{{ number_format($movement->running) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-ink-500">لا حركات في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-ink-900 font-bold">
                    <td colspan="3">المجموع ثم الرصيد الختامي في {{ $period->to->format('Y-m-d') }}</td>
                    <td class="num text-ok-700">{{ number_format($totals->in) }}</td>
                    <td class="num text-warn-700">{{ number_format($totals->out) }}</td>
                    <td class="num">{{ number_format($totals->closing) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

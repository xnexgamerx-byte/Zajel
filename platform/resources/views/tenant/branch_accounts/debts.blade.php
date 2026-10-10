@extends('layouts.app')
@section('title', 'ديون على الفروع')

@section('content')
<div class="mb-5">
    <h1 class="page-title">ديون على الفروع</h1>
    <p class="mt-1 text-sm text-ink-500">
        فرعٌ سلّم شحنات تجّار فرعٍ آخر فجمع نقداً ليس له — ناقصاً عمولته عن كلّ طلبٍ وصّله، فهي مستحقّاته. يُطفأ الدَّين بما وصل فعلاً في
        <a href="{{ route('branch-accounts.remittances') }}" class="text-[var(--brand)] hover:underline">استلام المبالغ المسدّدة</a>،
        وما بالطريق يُعرض وحده.
    </p>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الفرع المدين</th><th>للفرع</th><th>شحنات</th><th>المحصَّل</th><th>عمولة الفرع</th><th>المستحقّ</th><th>المستلَم</th><th>بالطريق</th><th>الباقي</th>
                    @can('money.cash')<th>سدِّد</th>@endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($pairs as $pair)
                    <tr>
                        <td class="font-medium">{{ $branches[$pair->debtor]->name ?? '—' }}</td>
                        <td>{{ $branches[$pair->creditor]->name ?? '—' }}</td>
                        <td class="num">{{ number_format($pair->shipments) }}</td>
                        <td class="num">{{ number_format($pair->collected) }}</td>
                        <td class="num text-ok-700">{{ $pair->commission ? '− '.number_format($pair->commission) : '—' }}</td>
                        <td class="num">{{ number_format($pair->amount) }}</td>
                        <td class="num text-ok-700">{{ number_format($pair->received) }}</td>
                        <td class="num text-info-700">{{ $pair->in_transit ? number_format($pair->in_transit) : '—' }}</td>
                        <td class="num font-bold {{ $pair->remaining > 0 ? 'text-bad-700' : 'text-ink-500' }}">{{ number_format($pair->remaining) }}</td>
                        @can('money.cash')
                            <td>
                                @if ($pair->remaining > 0 && ($boxes[$pair->debtor] ?? collect())->isNotEmpty())
                                    <form method="POST" action="{{ route('branch-accounts.remit') }}" class="flex flex-wrap items-center gap-1.5">
                                        @csrf
                                        <input type="hidden" name="from_branch_id" value="{{ $pair->debtor }}">
                                        <input type="hidden" name="to_branch_id" value="{{ $pair->creditor }}">
                                        <select name="from_box_id" class="field-input w-auto py-1 text-xs" aria-label="من صندوق">
                                            @foreach ($boxes[$pair->debtor] as $box)
                                                <option value="{{ $box->id }}">{{ $box->name }} ({{ number_format($box->balance) }})</option>
                                            @endforeach
                                        </select>
                                        <input name="amount" type="number" min="1" value="{{ $pair->remaining }}" class="field-input num w-28 py-1 text-xs" aria-label="المبلغ">
                                        <button class="btn-primary py-1 text-xs">أرسل</button>
                                    </form>
                                @elseif ($pair->remaining > 0)
                                    <span class="text-xs text-ink-500">لا صندوق لفرعه</span>
                                @endif
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-16 text-center text-ink-500">لا ديون بين الفروع — كل فرعٍ سلّم شحنات تجّاره.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

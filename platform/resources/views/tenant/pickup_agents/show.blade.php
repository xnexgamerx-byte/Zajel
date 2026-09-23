@extends('layouts.app')
@section('title', 'حساب ' . $courier->name)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $courier->name }}</h1>
        <p class="num mt-1 text-sm text-ink-500">{{ $courier->phone }} · مندوب استلام</p>
    </div>
    <a href="{{ route('pickup-agents.index') }}" class="btn-ghost">رجوع</a>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3">
    <div class="stat">
        <div class="stat-label">الرصيد المستحقّ</div>
        <div class="num mt-1 text-2xl font-bold text-[var(--brand)]">
            {{ number_format($courier->commission_balance) }}
        </div>
    </div>
    <div class="stat">
        <div class="stat-label">حصّة الطرد</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($courier->commission_per_pickup ?? 0) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">طروداً في المدّة</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($shares->sum('shipments_count')) }}</div>
    </div>
</div>

@if ($courier->commission_balance > 0)
    <form method="POST" action="{{ route('pickup-agents.pay', $courier) }}"
          class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        @csrf
        <div>
            <label class="field-label" for="cash_box_id">يُدفع من</label>
            <select id="cash_box_id" name="cash_box_id" class="field-input">
                <option value="">بلا صندوق (قيد محاسبيّ فقط)</option>
                @foreach ($boxes as $box)
                    <option value="{{ $box->id }}">{{ $box->name }} ({{ number_format($box->balance) }})</option>
                @endforeach
            </select>
        </div>
        <div class="flex-1">
            <label class="field-label" for="note">ملاحظة</label>
            <input id="note" name="note" type="text" maxlength="255" class="field-input">
        </div>
        <button type="submit" class="btn-primary">ادفع {{ number_format($courier->commission_balance) }}</button>
    </form>
@endif

<section class="card overflow-hidden">
    <h2 class="card-title border-b border-ink-100 px-5 py-4">حصص الاستلام</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الطلب</th>
                    <th>التاريخ</th>
                    <th>طروداً</th>
                    <th>الحصّة</th>
                    <th>المبلغ</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shares as $share)
                    <tr>
                        <td class="num font-semibold">{{ $share->pickupRequest?->number ?? '—' }}</td>
                        <td class="text-sm text-ink-500">{{ $share->created_at->format('Y-m-d') }}</td>
                        <td class="num">
                            {{ number_format($share->shipments_count) }}
                            @if ($share->claimed_count !== null && $share->claimed_count != $share->shipments_count)
                                <span class="text-xs text-warn-700">(يقول {{ $share->claimed_count }})</span>
                            @endif
                        </td>
                        <td class="num text-ink-600">{{ number_format($share->rate) }}</td>
                        <td class="num font-semibold">
                            {{ number_format($share->finalAmount()) }}
                            @if ($share->adjustment)
                                <span class="text-xs {{ $share->adjustment > 0 ? 'text-ok-700' : 'text-bad-700' }}">
                                    ({{ $share->adjustment > 0 ? '+' : '' }}{{ number_format($share->adjustment) }})
                                </span>
                            @endif
                        </td>
                        <td><span class="chip {{ $share->statusTone() }}">{{ $share->statusLabel() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-10 text-center text-ink-500">لا حصص في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($shares->hasPages())
        <div class="border-t border-ink-100 px-5 py-4">{{ $shares->links() }}</div>
    @endif
</section>
@endsection

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
                    <option value="{{ $box->id }}" @selected($defaultBox?->id === $box->id)>{{ $box->name }} ({{ number_format($box->balance) }})</option>
                @endforeach
            </select>
        </div>
        <div class="flex-1">
            <label class="field-label" for="note">ملاحظة</label>
            <input id="note" name="note" type="text" maxlength="255" class="field-input">
        </div>
        @php $cut = $courier->centreCut((int) $courier->commission_balance); @endphp
        <button type="submit" class="btn-primary">ادفع {{ number_format($courier->commission_balance - $cut) }}</button>
        @if ($cut > 0)
            <p class="w-full text-xs text-ink-500">
                {{ $courier->partnerLabel() }}: يُقفل مستحقّه {{ number_format($courier->commission_balance) }} كلّه،
                ويبقى للمركز {{ number_format($cut) }}.
            </p>
        @endif
    </form>
@endif

@if ($payouts->isNotEmpty())
    <section class="card mb-5 overflow-hidden">
        <h2 class="card-title border-b border-ink-100 px-5 py-4">دفعات ربح لمندوب الاستلام</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>رقم العملية</th><th>نوع مبلغ الشراكة</th><th>استحقّ</th><th>للمركز</th><th>المدفوع للشريك</th><th>ملاحظات</th><th>تمّت من خلال</th><th>تاريخ التسديد</th></tr>
                </thead>
                <tbody>
                    @foreach ($payouts as $payout)
                        <tr>
                            <td class="num font-semibold">{{ $payout->number }}</td>
                            <td>{{ $payout->centreLabel() }}</td>
                            <td class="num">{{ number_format($payout->earned) }}</td>
                            <td class="num">{{ number_format($payout->centre_amount) }}</td>
                            <td class="num font-semibold text-ok-700">{{ number_format($payout->paid_amount) }}</td>
                            <td class="text-xs text-ink-600">{{ $payout->note ?? '—' }}</td>
                            <td class="text-xs">{{ $payout->paidBy?->name ?? '—' }}</td>
                            <td class="num text-xs text-ink-500">{{ $payout->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
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

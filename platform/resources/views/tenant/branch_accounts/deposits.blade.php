@extends('layouts.app')
@section('title', 'تأمينات التجّار')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">تأمينات التجّار</h1>
        <p class="mt-1 text-sm text-ink-500">
            مالُ التاجر محجوزاً لا مالُ الشركة، ولا يدخل رصيد كشف حسابه.
        </p>
    </div>
    <div class="card px-5 py-3 text-center">
        <div class="text-xs text-ink-500">المحجوز إجمالاً</div>
        <div class="num mt-0.5 text-2xl font-bold text-[var(--brand)]">{{ number_format($held) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
        <section class="card overflow-hidden">
            <h2 class="card-title border-b border-ink-100 px-5 py-4">أرصدة التأمين</h2>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>التاجر</th><th>التأمين المحجوز</th><th>رصيد حسابه</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($merchants->where('deposit_balance', '>', 0) as $merchant)
                            <tr>
                                <td class="font-medium">
                                    {{ $merchant->business_name }}
                                    <span class="num text-xs text-ink-500">{{ $merchant->code }}</span>
                                </td>
                                <td class="num font-semibold text-[var(--brand)]">
                                    {{ number_format($merchant->deposit_balance) }}
                                </td>
                                <td class="num text-ink-600">{{ number_format($merchant->balance) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-10 text-center text-ink-500">لا تأمينات محجوزة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card overflow-hidden">
            <h2 class="card-title border-b border-ink-100 px-5 py-4">آخر الحركات</h2>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>التاريخ</th><th>التاجر</th><th>الحركة</th><th>المبلغ</th><th>الرصيد بعدها</th><th>السبب</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($movements as $row)
                            <tr>
                                <td class="whitespace-nowrap text-sm text-ink-500">{{ $row->created_at->format('Y-m-d H:i') }}</td>
                                <td class="max-w-40 truncate">{{ $row->merchant?->business_name }}</td>
                                <td>
                                    <span class="chip {{ $row->direction === 'in' ? 'chip-ok' : ($row->kind === 'forfeit' ? 'chip-bad' : 'chip-warn') }}">
                                        {{ $row->kindLabel() }}
                                    </span>
                                </td>
                                <td class="num">{{ number_format($row->amount) }}</td>
                                <td class="num font-semibold">{{ number_format($row->balance_after) }}</td>
                                <td class="max-w-48 truncate text-xs text-ink-500">{{ $row->reason ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-ink-500">لا حركات بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card h-fit p-5">
        <h2 class="card-title">حركة تأمين</h2>
        <form method="POST" action="{{ route('branch-accounts.deposits.store') }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label class="field-label" for="merchant_id">التاجر</label>
                <select id="merchant_id" name="merchant_id" class="field-input" required>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}">
                            {{ $merchant->business_name }} ({{ number_format($merchant->deposit_balance) }})
                        </option>
                    @endforeach
                </select>
                @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="kind">الحركة</label>
                <select id="kind" name="kind" class="field-input" required
                        onchange="document.getElementById('deposit-reason').required = this.value === 'forfeit'">
                    <option value="deposit">إيداع تأمين</option>
                    <option value="refund">ردّ تأمين</option>
                    <option value="forfeit">خصم من التأمين</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="amount">المبلغ</label>
                <input id="amount" name="amount" type="number" min="1" step="1" required class="field-input num">
                @error('amount') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="cash_box_id">الصندوق</label>
                <select id="cash_box_id" name="cash_box_id" class="field-input">
                    <option value="">بلا صندوق (قيد فقط)</option>
                    @foreach ($boxes as $box)
                        <option value="{{ $box->id }}">{{ $box->name }} ({{ number_format($box->balance) }})</option>
                    @endforeach
                </select>
                <p class="field-hint">الخصم لا يمرّ بالصندوق: المال بقي عندنا.</p>
            </div>
            <div>
                <label class="field-label" for="deposit-reason">السبب</label>
                <input id="deposit-reason" name="reason" type="text" maxlength="255" class="field-input"
                       placeholder="إلزاميّ عند الخصم">
                @error('reason') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary w-full">نفّذ</button>
        </form>
    </section>
</div>
@endsection

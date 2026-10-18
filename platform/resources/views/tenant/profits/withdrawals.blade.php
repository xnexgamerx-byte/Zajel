@extends('layouts.app')
@section('title', 'سحب الأرباح')

@section('content')
@include('tenant.profits._tabs', ['title' => 'سحب الأرباح', 'lead' => 'سحب الأرباح عمليةٌ منفصلة عن تحصيل المناديب: يُسجَّل المبلغ الذي قرّرت سحبه، ومن صندوقٍ إن اخترت، ويبقى سجلّ كل سحب.'])

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    @include('tenant.profits._figure', ['label' => 'الربح المتحقّق نقداً', 'value' => $summary['realized'], 'tone' => 'text-ok-700', 'hint' => 'من الوجبات المعتمدة.'])
    @include('tenant.profits._figure', ['label' => 'ما سُحب', 'value' => $summary['withdrawn'], 'tone' => 'text-bad-700'])
    @include('tenant.profits._figure', ['label' => 'المتاح للسحب', 'value' => $summary['available'], 'tone' => $summary['available'] >= 0 ? 'text-ok-700' : 'text-bad-700',
        'hint' => 'والربح المحاسبيّ '.number_format($summary['profit']).' — منه '.number_format($summary['unrealized']).' لم يُحصَّل نقداً بعد.'])
</div>

@can('money.withdraw')
    <form method="POST" action="{{ route('profits.withdrawals.store') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        @csrf
        <div>
            <label class="field-label" for="amount">المبلغ</label>
            <input id="amount" type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" required class="field-input w-40 text-left" dir="ltr">
            @error('amount') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="withdrawn_on">تاريخ السحب</label>
            <input id="withdrawn_on" type="date" name="withdrawn_on" value="{{ old('withdrawn_on', now()->toDateString()) }}" required class="field-input">
        </div>
        <div class="min-w-40">
            <label class="field-label" for="withdrawn_by">استلمه</label>
            <input id="withdrawn_by" name="withdrawn_by" value="{{ old('withdrawn_by', auth()->user()->name) }}" maxlength="120" class="field-input">
        </div>
        <div class="min-w-44">
            <label class="field-label" for="cash_box_id">من الصندوق</label>
            <select id="cash_box_id" name="cash_box_id" class="field-input">
                <option value="">لا يخرج من صندوق (يُسجَّل فقط)</option>
                @foreach ($boxes as $box)
                    <option value="{{ $box->id }}" @selected((int) old('cash_box_id') === $box->id)>{{ $box->name }} — {{ number_format($box->balance) }}</option>
                @endforeach
            </select>
            @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="min-w-48 flex-1">
            <label class="field-label" for="note">ملاحظة</label>
            <input id="note" name="note" value="{{ old('note') }}" maxlength="255" class="field-input">
        </div>
        <button class="btn-primary" data-confirm="تسجيل سحبٍ من أرباح الشركة؟">تسجيل السحب</button>
    </form>
@endcan

@error('withdrawal') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror

<div class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">سجل عمليات السحب</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الرقم</th><th>التاريخ</th><th>المبلغ</th><th>استلمه</th><th>الصندوق</th><th>ملاحظة</th><th>سجّله</th><th></th></tr></thead>
            <tbody>
                @forelse ($withdrawals as $w)
                    <tr @class(['text-ink-400' => $w->isCancelled()])>
                        <td class="num font-semibold">{{ $w->number }}</td>
                        <td class="num">{{ $w->withdrawn_on->format('Y-m-d') }}</td>
                        <td @class(['num font-bold', 'line-through' => $w->isCancelled()])>{{ number_format($w->amount) }}</td>
                        <td>{{ $w->withdrawn_by ?? '—' }}</td>
                        <td>{{ $w->cashBox?->name ?? '—' }}</td>
                        <td class="text-xs">{{ $w->note ?? '—' }}</td>
                        <td class="text-xs">{{ $w->createdBy?->name }} <div class="num text-ink-500">{{ $w->created_at->format('Y-m-d H:i') }}</div></td>
                        <td>
                            @if ($w->isCancelled())
                                <span class="chip chip-bad">ملغى</span>
                                <div class="text-xs">{{ $w->cancel_reason }}</div>
                            @elseif (auth()->user()->can('money.withdraw') && \App\Actions\Money\UndoWithinDay::open($w->created_at))
                                <form method="POST" action="{{ route('profits.withdrawals.cancel', $w) }}" class="flex items-center gap-1"
                                      data-confirm="يُلغى السحب {{ $w->number }}؟{{ $w->cash_box_id ? ' ويعود مبلغه إلى الصندوق.' : '' }}">
                                    @csrf
                                    <input name="reason" required maxlength="255" placeholder="سبب الإلغاء" class="field-input w-32 py-1" aria-label="سبب الإلغاء">
                                    <button class="btn-ghost py-1 text-xs">إلغاء</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-14 text-center text-ink-500">لا سحب بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($withdrawals->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $withdrawals->links() }}</div>
    @endif
</div>
@endsection

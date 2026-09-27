@extends('layouts.app')
@section('title', 'صندوقي')

@section('content')
<div class="mb-5">
    <h1 class="page-title">صندوقي</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما قبضته بيدك — من المناديب عند إقفال كشوفهم — وما دفعت منه. سلِّمه للقاصة آخر الدوام فيُكتب باسمك.
    </p>
</div>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">{{ $box->name }} — الرصيد الآن</div>
        <div class="mt-1 text-2xl font-bold text-[var(--brand)]"><span class="num">{{ number_format($box->balance) }}</span> <span class="text-sm font-medium text-ink-500">د.ع</span></div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">دخل اليوم</div>
        <div class="num mt-1 text-2xl font-bold text-ok-700">{{ number_format((int) ($today['in'] ?? 0)) }}</div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">خرج اليوم</div>
        <div class="num mt-1 text-2xl font-bold text-bad-700">{{ number_format((int) ($today['out'] ?? 0)) }}</div>
    </div>
</div>

@if ($box->balance > 0 && $targets->isNotEmpty())
    <form method="POST" action="{{ route('cash.mine.handover') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        @csrf
        <div class="min-w-44">
            <label class="field-label" for="to_box_id">سلِّم إلى</label>
            <select id="to_box_id" name="to_box_id" class="field-input">
                @foreach ($targets as $target)
                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label" for="amount">المبلغ</label>
            <input id="amount" name="amount" type="number" min="1" max="{{ $box->balance }}" class="field-input num w-40"
                   value="{{ old('amount', $box->balance) }}">
        </div>
        <div class="min-w-48 flex-1">
            <label class="field-label" for="note">ملاحظة</label>
            <input id="note" name="note" maxlength="200" class="field-input" value="{{ old('note') }}">
        </div>
        <button type="submit" class="btn-primary">سلّمت للقاصة</button>
    </form>
@endif

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>الوقت</th><th>البيان</th><th>النوع</th><th>داخل</th><th>خارج</th><th>الرصيد بعدها</th></tr>
            </thead>
            <tbody>
                @forelse ($movements as $move)
                    <tr>
                        <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $move->created_at->format('Y-m-d H:i') }}</td>
                        <td class="text-sm">{{ $move->description }}</td>
                        <td class="text-xs">{{ $move->categoryLabel() }}</td>
                        <td class="num text-ok-700">{{ $move->direction === 'in' ? number_format($move->amount) : '' }}</td>
                        <td class="num text-bad-700">{{ $move->direction === 'out' ? number_format($move->amount) : '' }}</td>
                        <td class="num font-semibold">{{ number_format($move->balance_after) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-ink-500">لا حركة في صندوقك بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($movements->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $movements->links() }}</div>
    @endif
</div>
@endsection

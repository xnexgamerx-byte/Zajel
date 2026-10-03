@extends('layouts.app')
@section('title', 'قبض ودفع الموظّفين')

@section('content')
<div class="mb-5">
    <h1 class="page-title">قبض ودفع الموظّفين</h1>
    <p class="mt-1 text-sm text-ink-500">ما قبضه كل موظّفٍ وما دفعه في المدّة، بأنواعه — من دفتر القاصة نفسه. {{ $period->label() }}</p>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <div class="min-w-48">
        <label class="field-label" for="user_id">المستخدم</label>
        <select id="user_id" name="user_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($everyone as $person)
                <option value="{{ $person->id }}" @selected($userId === $person->id)>{{ $person->name }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-primary">طبّق</button>
</form>

<div class="card mb-5 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>المستخدم</th><th>النوع</th><th>عدد الحركات</th><th>قبض</th><th>دفع</th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $uid => $lines)
                    @foreach ($lines as $line)
                        <tr>
                            @if ($loop->first)
                                <td rowspan="{{ $lines->count() + 1 }}" class="align-top font-medium">
                                    <a href="{{ route('money.accountants', ['user_id' => $uid, 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()]) }}" class="hover:underline">{{ $users[$uid]->name ?? '—' }}</a>
                                </td>
                            @endif
                            <td class="text-sm">{{ \App\Models\CashMovement::labelFor($line->category) }}</td>
                            <td class="num">{{ number_format($line->moves) }}</td>
                            <td class="num text-ok-700">{{ $line->total_in ? number_format($line->total_in) : '' }}</td>
                            <td class="num text-bad-700">{{ $line->total_out ? number_format($line->total_out) : '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="bg-ink-50 font-semibold">
                        <td class="text-sm">المجموع</td>
                        <td class="num">{{ number_format($lines->sum('moves')) }}</td>
                        <td class="num text-ok-700">{{ number_format($lines->sum('total_in')) }}</td>
                        <td class="num text-bad-700">{{ number_format($lines->sum('total_out')) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-ink-500">لا حركات قاصة في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($movements)
    <div class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">حركات {{ $users[$userId]->name ?? '' }}</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>الوقت</th><th>الصندوق</th><th>البيان</th><th>قبض</th><th>دفع</th></tr></thead>
                <tbody>
                    @forelse ($movements as $move)
                        <tr>
                            <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $move->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-sm">{{ $move->cashBox?->name }}</td>
                            <td class="text-sm">{{ $move->description }}</td>
                            <td class="num text-ok-700">{{ $move->direction === 'in' ? number_format($move->amount) : '' }}</td>
                            <td class="num text-bad-700">{{ $move->direction === 'out' ? number_format($move->amount) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-ink-500">لا حركات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($movements->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $movements->links() }}</div>
        @endif
    </div>
@endif
@endsection

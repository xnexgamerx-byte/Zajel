@extends('layouts.app')
@section('title', 'كشف حساب الفرع')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">كشف حساب {{ $branch?->name ?? 'الفرع' }}</h1>
        <p class="mt-1 text-sm text-ink-500">
            كل دينارٍ دخل صناديق الفرع أو خرج منها، بالترتيب، برصيدٍ جارٍ من الافتتاحيّ إلى الختاميّ.
        </p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('branch-accounts.statement.print', array_merge($period->query(), ['branch_id' => $branch?->id])) }}" class="btn-ghost">اطبع الكشف</a>
        <a href="{{ route('branch-accounts.index', $period->query()) }}" class="btn-ghost">محاسبة الفروع</a>
    </div>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    @if ($branches->count() > 1)
        <div>
            <label class="field-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id" class="field-input">
                @foreach ($branches as $option)
                    <option value="{{ $option->id }}" @selected($branch?->id === $option->id)>
                        {{ $option->name }}{{ $option->deleted_at ? ' (محذوف)' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <button type="submit" class="btn-primary">طبّق</button>
    <p class="ms-auto max-w-80 text-xs text-ink-500">
        صناديق الفرع: {{ $boxes->pluck('name')->implode('، ') ?: 'لا صناديق' }}.
        المناقلة بين صندوقين فيه تُعرَض ولا تُجمَع.
    </p>
</form>

@include('tenant.branch_accounts._statement')
@endsection

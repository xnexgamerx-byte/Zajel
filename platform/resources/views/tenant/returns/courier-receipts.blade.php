@extends('layouts.app')
@section('title', 'إيصالات راجع المندوبين')

@section('content')
<div class="mb-5">
    <h1 class="page-title">إيصالات راجع المندوبين</h1>
    <p class="mt-1 text-sm text-ink-500">
        كل استلامٍ لراجع مندوبٍ بالمخزن بإيصاله: ما سلّمه المندوب، ومن استلمه، ومتى.
    </p>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-48 flex-1">
        <label class="field-label" for="q">رقم الإيصال أو الوصل</label>
        <input id="q" name="q" value="{{ $term }}" class="field-input" autocomplete="off" dir="ltr">
    </div>
    <div class="min-w-48 flex-1">
        <label class="field-label" for="courier_id">المندوب</label>
        <select id="courier_id" name="courier_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ request('from') }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ request('to') }}" class="field-input">
    </div>
    <button type="submit" class="btn-primary">بحث</button>
    <a href="{{ route('courier-return-receipts.index') }}" class="btn-ghost">مسح البحث</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الإيصال</th>
                    <th>المندوب</th>
                    <th>الشحنات</th>
                    <th>المبالغ</th>
                    <th>استُلم</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($receipts as $receipt)
                    <tr>
                        <td class="num font-semibold">{{ $receipt->number }}</td>
                        <td>{{ $receipt->courier?->name ?? '—' }}</td>
                        <td class="num">{{ number_format($receipt->shipments_count) }}</td>
                        <td class="num">{{ number_format($receipt->cod_total) }}</td>
                        <td class="text-xs text-ink-500">
                            <span class="num">{{ $receipt->received_at->format('Y-m-d H:i') }}</span>
                            @if ($receipt->receivedBy)<div>{{ $receipt->receivedBy->name }}</div>@endif
                        </td>
                        <td>
                            <a href="{{ route('courier-return-receipts.print', $receipt) }}" target="_blank" class="btn-ghost py-1">
                                <x-icon name="printer" class="size-4"/> الإيصال
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-16 text-center text-ink-500">لا إيصال راجعٍ بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($receipts->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $receipts->links() }}</div>
    @endif
</div>
@endsection

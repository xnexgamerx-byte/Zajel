@extends('layouts.app')
@section('title', 'القيد '.$entry->number)

@section('content')
@include('tenant.finance._tabs', ['title' => 'القيد رقم '.$entry->number])

<div class="card mb-4 grid grid-cols-1 gap-x-8 gap-y-2 p-5 text-sm md:grid-cols-2">
    <div><span class="text-ink-500">الحدث:</span> {{ $entry->eventLabel() }}</div>
    <div><span class="text-ink-500">التاريخ:</span> <span class="num">{{ $entry->entry_date->format('Y-m-d') }}</span> · كُتب <span class="num">{{ $entry->created_at?->format('Y-m-d H:i') }}</span></div>
    <div><span class="text-ink-500">البيان:</span> {{ $entry->description ?? '—' }}</div>
    <div><span class="text-ink-500">المصدر:</span>
        @if ($source[1])<a href="{{ $source[1] }}" class="text-[var(--brand)] hover:underline">{{ $source[0] }}</a>@else {{ $source[0] }} @endif
        @if ($entry->createdBy) · {{ $entry->createdBy->name }}@endif
    </div>
    <div class="md:col-span-2 text-xs text-ink-500">البصمة: <span class="num break-all" dir="ltr">{{ $entry->hash }}</span></div>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الحساب</th><th>على</th><th>مدين</th><th>دائن</th></tr></thead>
            <tbody>
                @foreach ($entry->lines as $line)
                    <tr>
                        <td><a href="{{ route('finance.ledger', ['account' => $line->account]) }}" class="text-[var(--brand)] hover:underline"><span class="num text-xs">{{ $line->account }}</span> {{ $line->accountName() }}</a></td>
                        <td class="text-xs">
                            @if ($line->shipment)<a href="{{ route('shipments.show', $line->shipment_id) }}" class="num text-[var(--brand)] hover:underline">{{ $line->shipment->number }}</a>@endif
                            {{ $line->courier?->name }} {{ $line->merchant?->business_name }} {{ $line->cashBox?->name }}
                        </td>
                        <td class="num">{{ $line->debit ? number_format($line->debit) : '' }}</td>
                        <td class="num">{{ $line->credit ? number_format($line->credit) : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold"><td colspan="2">المجموع</td><td class="num">{{ number_format($entry->lines->sum('debit')) }}</td><td class="num">{{ number_format($entry->lines->sum('credit')) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection

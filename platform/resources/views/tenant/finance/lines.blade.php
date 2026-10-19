@extends('layouts.app')
@section('title', 'دفتر القيود')

@section('content')
@include('tenant.finance._tabs', [
    'title' => 'دفتر القيود',
    'lead'  => 'كل سطر قيدٍ بحسابه وأبعاده ومصدره. صفِّ بحسابٍ أو مندوبٍ أو تاجرٍ أو صندوقٍ أو رقم وصل لتعرف من أين جاء أي رقم.',
])

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-52">
        <label class="field-label" for="account">الحساب</label>
        <select id="account" name="account" class="field-input">
            <option value="">كل الحسابات</option>
            @foreach (\App\Support\Finance\Accounts::ALL as $code => [$name])
                <option value="{{ $code }}" @selected($account === (string) $code)>{{ $code }} — {{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="shipment">رقم الوصل</label>
        <input id="shipment" name="shipment" value="{{ request('shipment') }}" class="field-input" dir="ltr" autocomplete="off">
    </div>
    <div class="min-w-44">
        <label class="field-label" for="courier_id">المندوب</label>
        <select id="courier_id" name="courier_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($couriers as $c)<option value="{{ $c->id }}" @selected((int) request('courier_id') === $c->id)>{{ $c->name }}</option>@endforeach
        </select>
    </div>
    <div class="min-w-44">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($merchants as $m)<option value="{{ $m->id }}" @selected((int) request('merchant_id') === $m->id)>{{ $m->business_name }}</option>@endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="cash_box_id">الصندوق</label>
        <select id="cash_box_id" name="cash_box_id" class="field-input">
            <option value="">الكل</option>
            @foreach ($boxes as $b)<option value="{{ $b->id }}" @selected((int) request('cash_box_id') === $b->id)>{{ $b->name }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ $period->from->toDateString() }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ $period->to->toDateString() }}" class="field-input">
    </div>
    <button type="submit" class="btn-primary">عرض</button>
    <a href="{{ route('finance.ledger') }}" class="btn-ghost">مسح</a>
</form>

<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="card p-4"><div class="text-xs text-ink-500">مجموع المدين</div><div class="num mt-1 text-xl font-bold">{{ number_format($totals->debit) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-500">مجموع الدائن</div><div class="num mt-1 text-xl font-bold">{{ number_format($totals->credit) }}</div></div>
    <div class="card p-4"><div class="text-xs text-ink-500">الصافي (مدين − دائن)</div><div class="num mt-1 text-xl font-bold">{{ number_format($totals->debit - $totals->credit) }}</div></div>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>التاريخ</th><th>القيد</th><th>الحدث</th><th>الحساب</th><th>على</th><th>مدين</th><th>دائن</th></tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        <td class="num whitespace-nowrap text-xs">{{ $line->entry_date->format('Y-m-d') }}</td>
                        <td><a href="{{ route('finance.entries.show', $line->journal_entry_id) }}" class="num font-semibold text-[var(--brand)] hover:underline">{{ $line->entry?->number }}</a></td>
                        <td class="text-xs">
                            {{ $line->entry?->eventLabel() }}
                            @if ($line->entry?->description)<div class="text-ink-500">{{ \Illuminate\Support\Str::limit($line->entry->description, 70) }}</div>@endif
                        </td>
                        <td class="whitespace-nowrap text-xs"><span class="num text-ink-500">{{ $line->account }}</span> {{ $line->accountName() }}</td>
                        <td class="text-xs">
                            @if ($line->shipment)<a href="{{ route('shipments.show', $line->shipment_id) }}" class="num text-[var(--brand)] hover:underline">{{ $line->shipment->number }}</a>@endif
                            {{ $line->courier?->name }} {{ $line->merchant?->business_name }} {{ $line->cashBox?->name }}
                        </td>
                        <td class="num">{{ $line->debit ? number_format($line->debit) : '' }}</td>
                        <td class="num">{{ $line->credit ? number_format($line->credit) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-14 text-center text-ink-500">لا قيود بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($lines->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $lines->links() }}</div>
    @endif
</div>
@endsection

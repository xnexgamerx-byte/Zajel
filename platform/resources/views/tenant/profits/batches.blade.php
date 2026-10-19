@extends('layouts.app')
@section('title', 'سجل وجبات التحصيل')

@section('content')
@include('tenant.profits._tabs', ['title' => 'سجل وجبات التحصيل', 'lead' => 'كل وجبة برقمها ومدّتها وفرعها ومناديبها، وما حُصِّل فيها، وصافي ربحها، وحال مطابقتها.'])

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-40 flex-1">
        <label class="field-label" for="q">رقم الوجبة</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input" autocomplete="off" dir="ltr" placeholder="COL-0001">
    </div>
    <div>
        <label class="field-label" for="status">الحال</label>
        <select id="status" name="status" class="field-input">
            <option value="">الكل</option>
            <option value="open" @selected(request('status') === 'open')>كل ما لم يُعتمد</option>
            @foreach (\App\Models\CollectionBatch::STATUSES as $key => $label)
                <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
            @endforeach
            <option value="differences" @selected(request('status') === 'differences')>فيها عجزٌ أو زيادة</option>
        </select>
    </div>
    @if ($branches->isNotEmpty())
        <div>
            <label class="field-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id" class="field-input">
                <option value="">الكل</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) request('branch_id') === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ request('from') }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ request('to') }}" class="field-input">
    </div>
    <button type="submit" class="btn-primary">بحث</button>
    <a href="{{ route('profits.batches.index') }}" class="btn-ghost">مسح البحث</a>
    <a href="{{ route('profits.batches.export', request()->query()) }}" class="btn-ghost ms-auto"><x-icon name="download" class="size-4"/> تصدير Excel</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الوجبة</th>
                    <th>التاريخ</th>
                    <th>الفرع</th>
                    <th>المناديب</th>
                    <th>الشحنات</th>
                    <th>إجمالي المحصَّل</th>
                    <th>صافي الربح</th>
                    <th>المطابقة</th>
                    <th>الحال</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td><a href="{{ route('profits.batches.show', $batch) }}" class="num whitespace-nowrap font-semibold text-[var(--brand)] hover:underline">{{ $batch->number }}</a></td>
                        <td class="text-xs">
                            <span class="num whitespace-nowrap">{{ $batch->from_date->format('Y-m-d') }} — {{ $batch->to_date->format('Y-m-d') }}</span>
                            <div class="text-ink-500">{{ $batch->periodLabel() }}</div>
                        </td>
                        <td>{{ $batch->branch?->name ?? 'كل الفروع' }}</td>
                        <td class="num">{{ number_format($batch->couriers_count) }}</td>
                        <td class="num">{{ number_format($batch->shipments_count) }}</td>
                        <td class="num">{{ number_format($batch->received_cash) }}</td>
                        <td class="num font-semibold">{{ number_format($batch->net_profit) }}</td>
                        <td>
                            <span @class(['chip', 'chip-ok' => (int) $batch->discrepancy === 0, 'chip-bad' => $batch->discrepancy < 0, 'chip-info' => $batch->discrepancy > 0])>{{ $batch->reconciliationLabel() }}</span>
                        </td>
                        <td class="text-xs">
                            <span @class(['chip', 'chip-ok' => $batch->isApproved(), 'chip-mute' => $batch->isCancelled(), 'chip-bad' => $batch->status === 'discrepancy',
                                'chip-warn' => $batch->isOpen() && $batch->status !== 'discrepancy'])>{{ $batch->statusLabel() }}</span>
                            @if ($batch->isApproved())
                                <div class="num mt-1 text-ink-500">{{ $batch->approved_at->format('Y-m-d H:i') }}</div>
                                @if ($batch->approvedBy)<div class="text-ink-500">{{ $batch->approvedBy->name }}</div>@endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-16 text-center text-ink-500">لا وجبة بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($batches->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $batches->links() }}</div>
    @endif
</div>
@endsection

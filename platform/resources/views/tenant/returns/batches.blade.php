@extends('layouts.app')
@section('title', 'دفعات الراجع')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">دفعات الراجع</h1>
        <p class="mt-1 text-sm text-ink-500">
            كل تسليمٍ لرواجع تاجرٍ بإيصاله: من المخزن، أو مع مندوب الاستلام — ومتى استلمها فعلاً.
        </p>
    </div>
    @if ($waiting)
        <a href="{{ route('return-batches.index', ['received' => 'no']) }}" class="chip chip-warn">
            لم يؤكَّد استلامها: {{ number_format($waiting) }}
        </a>
    @endif
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-48 flex-1">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($merchants as $merchant)
                <option value="{{ $merchant->id }}" @selected((int) request('merchant_id') === $merchant->id)>{{ $merchant->business_name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="via">راجع عن طريق</label>
        <select id="via" name="via" class="field-input">
            <option value="">الكل</option>
            @foreach (\App\Models\ReturnBatch::VIA as $key => $label)
                <option value="{{ $key }}" @selected(request('via') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="courier_id">مندوب الاستلام</label>
        <select id="courier_id" name="courier_id" class="field-input">
            <option value="">الكل</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-36">
        <label class="field-label" for="received">الاستلام الفعليّ</label>
        <select id="received" name="received" class="field-input">
            <option value="">الكل</option>
            <option value="no" @selected(request('received') === 'no')>لم يؤكَّد</option>
            <option value="yes" @selected(request('received') === 'yes')>مؤكَّد</option>
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
    <a href="{{ route('return-batches.index') }}" class="btn-ghost">مسح البحث</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الإيصال</th>
                    <th>التاجر</th>
                    <th>الشحنات</th>
                    <th>أجرة الراجع</th>
                    <th>راجع عن طريق</th>
                    <th>سُلّم</th>
                    <th>الاستلام الفعليّ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td class="num font-semibold">{{ $batch->number }}</td>
                        <td>{{ $batch->merchant?->business_name }}</td>
                        <td class="num">{{ number_format($batch->shipments_count) }}</td>
                        <td class="num">{{ number_format($batch->return_fees_total) }}</td>
                        <td>{{ $batch->viaLabel() }}</td>
                        <td class="text-xs text-ink-500">
                            <span class="num">{{ $batch->handed_at->format('Y-m-d H:i') }}</span>
                            @if ($batch->handedBy)<div>{{ $batch->handedBy->name }}</div>@endif
                        </td>
                        <td class="text-xs">
                            @if ($batch->isReceived())
                                <span class="num">{{ $batch->received_at->format('Y-m-d H:i') }}</span>
                                <div class="text-ink-500">{{ $batch->received_by }}</div>
                            @else
                                <form method="POST" action="{{ route('return-batches.confirm', $batch) }}" class="flex items-center gap-2">
                                    @csrf
                                    <span class="chip chip-warn">لم يؤكَّد</span>
                                    <button class="btn-ghost py-1 text-xs">أكِّد الاستلام</button>
                                </form>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('return-batches.print', $batch) }}" target="_blank" class="btn-ghost py-1">
                                <x-icon name="printer" class="size-4"/> الإيصال
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-16 text-center text-ink-500">لا دفعة راجع بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($batches->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $batches->links() }}</div>
    @endif
</div>
@endsection

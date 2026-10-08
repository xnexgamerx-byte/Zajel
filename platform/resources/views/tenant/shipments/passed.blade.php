@extends('layouts.app')
@section('title', 'شحنات مرّت على مخزني')

@section('content')
<div class="mb-5">
    <h1 class="page-title">شحنات مرّت على مخزني</h1>
    <p class="mt-1 text-sm text-ink-500">
        كل شحنةٍ دخلت مراكز الفرع ولو خرجت منها — من سجلّها، حدثاً حدثاً.
    </p>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-40">
        <label class="field-label" for="branch_id">المخزن</label>
        <select id="branch_id" name="branch_id" class="field-input" @disabled($locked)>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40 flex-1">
        <label class="field-label" for="q">رقم الوصل أو هاتف المستلم</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input">
    </div>
    <div class="min-w-40">
        <label class="field-label" for="status">المرحلة</label>
        <select id="status" name="status" class="field-input">
            <option value="">الكل</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="created_in">أُنشئت في فرع</label>
        <select id="created_in" name="created_in" class="field-input">
            <option value="">الكل</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((int) request('created_in') === $branch->id)>{{ $branch->name }}</option>
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
    <button type="submit" class="btn-primary">عرض</button>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الوصل</th><th>التاجر</th><th>أُنشئت في فرع</th><th>حالياً في</th>
                    <th>المبلغ</th><th>المحافظة</th><th>المرحلة</th><th>دخلتها</th><th>أُنشئت</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    <tr>
                        <td class="num font-semibold whitespace-nowrap" dir="ltr">
                            @if ($visible->has($shipment->id))
                                <a href="{{ route('shipments.show', $shipment) }}" class="text-[var(--brand)] hover:underline">{{ $shipment->number }}</a>
                            @else
                                {{ $shipment->number }}
                            @endif
                        </td>
                        <td>{{ $shipment->merchant?->business_name }}</td>
                        <td class="whitespace-nowrap">{{ $shipment->branch?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap">{{ $shipment->hub?->branch?->name ?? $shipment->hub?->name ?? '—' }}</td>
                        <td class="num whitespace-nowrap">{{ number_format($shipment->cod_amount) }}</td>
                        <td>{{ $shipment->governorate?->name_ar }}</td>
                        <td><x-status-badge :status="$shipment->status" :shipment="$shipment" /></td>
                        <td class="num whitespace-nowrap text-xs text-ink-500">{{ $shipment->status_changed_at?->format('Y-m-d H:i') }}</td>
                        <td class="num whitespace-nowrap text-xs text-ink-500">{{ $shipment->created_at->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-16 text-center text-ink-500">لم تمرّ شحنةٌ بهذا البحث على هذا المخزن.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
    @endif
</div>
<p class="mt-3 text-xs text-ink-500">إجمالي النتائج: {{ number_format($shipments->total()) }}</p>
@endsection

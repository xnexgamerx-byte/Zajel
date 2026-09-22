@extends('layouts.portal')
@section('title', 'شحناتي')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-bold">شحناتي</h1>
    <a href="{{ route('portal.shipments.create') }}" class="btn-primary">+ شحنة جديدة</a>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-56 flex-1">
        <label class="field-label" for="q">بحث</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input"
               placeholder="رقم وصل · هاتف الزبون · رقم طلبك">
    </div>
    <div>
        <label class="field-label" for="status">الحالة</label>
        <select id="status" name="status" class="field-input">
            <option value="">الكل</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                    {{ $status->label() }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ request('from') }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ request('to') }}">
    </div>
    <button type="submit" class="btn-primary">تطبيق</button>
    <a href="{{ route('portal.shipments.index') }}" class="btn-ghost">مسح</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">رقم الوصل</th>
                    <th class="px-4 py-3 text-start font-semibold">الزبون</th>
                    <th class="px-4 py-3 text-start font-semibold">الوجهة</th>
                    <th class="px-4 py-3 text-start font-semibold">المطلوب</th>
                    <th class="px-4 py-3 text-start font-semibold">الأجرة</th>
                    <th class="px-4 py-3 text-start font-semibold">لك</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                    <th class="px-4 py-3 text-start font-semibold">التاريخ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($shipments as $shipment)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('portal.shipments.show', $shipment) }}"
                               class="font-mono font-semibold text-brand-700 hover:underline" dir="ltr">
                                {{ $shipment->number }}
                            </a>
                            @if ($shipment->merchant_reference)
                                <div class="text-xs text-slate-400" dir="ltr">{{ $shipment->merchant_reference }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $shipment->recipient_name }}</div>
                            <div class="text-xs text-slate-500" dir="ltr">{{ $shipment->recipient_phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $shipment->governorate->name_ar }}
                            @if ($shipment->city)
                                <span class="text-xs text-slate-400">· {{ $shipment->city->name_ar }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ number_format($shipment->total_fees) }}</td>
                        <td class="px-4 py-3 font-bold text-brand-700" dir="ltr">
                            {{ number_format($shipment->merchant_due) }}
                        </td>
                        <td class="px-4 py-3"><x-status-badge :status="$shipment->status" /></td>
                        <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">
                            {{ $shipment->created_at->format('Y-m-d') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-16 text-center">
                            <div class="text-slate-500">لا شحنات مطابقة.</div>
                            <a href="{{ route('portal.shipments.create') }}" class="btn-primary mt-4">شحنة جديدة</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($shipments->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $shipments->links() }}</div>
    @endif
</div>

<p class="mt-3 text-xs text-slate-500">الإجمالي: {{ number_format($shipments->total()) }} شحنة</p>
@endsection

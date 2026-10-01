@extends('layouts.app')
@section('title', 'شحنات متأخرة')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'شحنات متأخرة', 'blurb' => '«المعلّقة في جميع المراحل» و«العالقة في فرعي»: ما لم تتحرّك مرحلته منذ أكثر من الساعات المختارة، الأقدم أوّلاً.'])

<x-report-period>
    <div>
        <label class="field-label" for="hours">ساعات التأخير أكثر من</label>
        <input id="hours" name="hours" type="number" min="1" max="1440" class="field-input num w-28" value="{{ $hours }}">
    </div>
    <div class="min-w-40">
        <label class="field-label" for="status">المرحلة</label>
        <select id="status" name="status" class="field-input">
            <option value="">كل المراحل المفتوحة</option>
            @foreach (\App\Enums\ShipmentStatus::cases() as $case)
                @continue(! in_array($case->value, \App\Enums\ShipmentStatus::openValues(), true))
                <option value="{{ $case->value }}" @selected($status === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="branch_id">في فرع</label>
        <select id="branch_id" name="branch_id" class="field-input">
            <option value="">كل الفروع</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((int) request('branch_id') === $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="governorate_id">المحافظة</label>
        <select id="governorate_id" name="governorate_id" class="field-input">
            <option value="">الكل</option>
            @foreach ($governorates as $gov)
                <option value="{{ $gov->id }}" @selected((int) request('governorate_id') === $gov->id)>{{ $gov->name_ar }}</option>
            @endforeach
        </select>
    </div>
</x-report-period>

<div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-3 text-sm text-ink-600">
        معلّقةٌ أكثر من {{ \App\Support\Arabic::hours($hours) }}: {{ \App\Support\Arabic::shipments($shipments->total()) }}
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>رقم الوصل</th><th>التاجر</th><th>أُنشئت في فرع</th><th>المرحلة</th><th>في فرع</th><th>دخلت مرحلتها</th><th>مندوب التوصيل</th><th>المبلغ</th><th>الوجهة</th><th>ساعات التأخير</th></tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    <tr>
                        <td class="num font-semibold"><a href="{{ route('shipments.show', $shipment) }}" class="text-[var(--brand)] hover:underline">{{ $shipment->number }}</a></td>
                        <td>{{ $shipment->merchant?->business_name }}</td>
                        <td class="text-sm">{{ $shipment->branch?->name ?? '—' }}</td>
                        <td><x-status-badge :status="$shipment->status" /></td>
                        <td class="text-sm">{{ $shipment->hub?->branch?->name ?? '—' }}</td>
                        <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $shipment->status_changed_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-sm">{{ $shipment->deliveryCourier?->name ?? '—' }}</td>
                        <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                        <td class="text-sm">{{ $shipment->governorate?->name_ar }}</td>
                        <td class="num font-bold text-bad-700">{{ number_format((int) $shipment->status_changed_at?->diffInHours(now())) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-12 text-center text-ink-500">لا شحنة معلّقة أكثر من {{ \App\Support\Arabic::hours($hours) }} بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
    @endif
</div>
@endsection

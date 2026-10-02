@extends('layouts.app')
@section('title', 'تغيّرت أسعارها ولم يُحاسَب التاجر')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'تغيّرت أسعارها ولم يُحاسَب التاجر', 'blurb' => 'شحناتٌ عُدّلت أجورها بعد إنشائها ولم تدخل تسوية تاجرها بعد: الأجور قبل التعديل وبعده، والفرق، ومن عدّل — ليُراجَع قبل أن يُدفع.'])

<x-report-period :period="$period">
    <div class="min-w-48">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input">
            <option value="">كل التجّار</option>
            @foreach ($merchants as $merchant)
                <option value="{{ $merchant->id }}" @selected($merchantId === $merchant->id)>{{ $merchant->business_name }}</option>
            @endforeach
        </select>
    </div>
    <p class="ms-auto max-w-80 text-xs text-ink-500">بتاريخ التعديل. تعديلٌ أعاد الأجور كما كانت لا يظهر.</p>
</x-report-period>

@if ($truncated)
    <div class="alert mb-5 bg-warn-50 text-warn-700" role="status">
        تعديلات هذه المدّة أكثر من {{ number_format(\App\Http\Controllers\Tenant\FlowReportController::REPRICED_EVENTS_CAP) }} — عُرض أحدثها. ضيّق المدّة أو اختر تاجراً لترى كل شيء.
    </div>
@endif

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="stat">
        <div class="stat-label">شحنات تغيّرت أجورها</div>
        <div class="stat-value num">{{ number_format($rows->total()) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">صافي الفرق على التجّار</div>
        <div class="stat-value num" dir="ltr">{{ ($net > 0 ? '+' : '').number_format($net) }}</div>
        <div class="mt-1 text-xs text-ink-500">الموجب زيادةٌ في أجورنا، والسالب نقصٌ منها.</div>
    </div>
    <div class="stat">
        <div class="stat-label">تجّار تأثّروا</div>
        <div class="stat-value num">{{ number_format($byMerchant->count()) }}</div>
    </div>
</div>

@if ($byMerchant->count() > 1)
    <section class="card mb-5 overflow-hidden">
        <h2 class="card-title border-b border-ink-100 px-5 py-4">لكل تاجر</h2>
        <div class="max-h-72 overflow-y-auto">
            <table class="tbl">
                <thead><tr><th>التاجر</th><th>الشحنات</th><th>صافي الفرق</th></tr></thead>
                <tbody>
                    @foreach ($byMerchant as $row)
                        <tr>
                            <td class="font-medium">{{ $row->name }}</td>
                            <td class="num">{{ number_format($row->shipments) }}</td>
                            <td class="num font-semibold {{ $row->diff > 0 ? 'text-ok-700' : 'text-bad-700' }}" dir="ltr">{{ ($row->diff > 0 ? '+' : '').number_format($row->diff) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>رقم الوصل</th><th>التاجر</th><th>الحالة</th><th>الأجور قبل</th><th>بعد</th><th>الفرق</th><th>عدّلها</th><th>آخر تعديل</th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="num font-semibold"><a href="{{ route('shipments.show', $row->shipment) }}" class="text-[var(--brand)] hover:underline">{{ $row->shipment->number }}</a></td>
                        <td>{{ $row->shipment->merchant?->business_name }}</td>
                        <td><x-status-badge :status="$row->shipment->status" /></td>
                        <td class="num text-ink-600">{{ number_format($row->before) }}</td>
                        <td class="num">{{ number_format($row->after) }}</td>
                        <td class="num font-semibold {{ $row->diff > 0 ? 'text-ok-700' : 'text-bad-700' }}" dir="ltr">{{ ($row->diff > 0 ? '+' : '').number_format($row->diff) }}</td>
                        <td class="text-sm">
                            {{ $row->by ?: 'النظام' }}
                            @if ($row->edits > 1)<span class="block text-xs text-ink-500">{{ \App\Support\Arabic::count($row->edits, ['تعديل واحد', 'تعديلان', 'تعديلات', 'تعديلاً']) }}</span>@endif
                        </td>
                        <td class="num text-xs whitespace-nowrap text-ink-500">{{ $row->at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-ink-500">لم تتغيّر أجور شحنةٍ تنتظر تسوية تاجرها في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($rows->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $rows->links() }}</div>
    @endif
</section>
@endsection

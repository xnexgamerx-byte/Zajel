@extends('layouts.app')
@section('title', 'تحت المراجعة')

@section('content')
<div class="mb-5">
    <h1 class="page-title">تحت المراجعة</h1>
    <p class="mt-1 text-sm text-ink-500">
        شحنات تجّارٍ معلّقين للتدقيق: لا تخرج مع مندوبٍ حتى تُجاز. راجعها ثم أجِزها — واحدةً أو كلّها.
    </p>
</div>

<form method="POST" action="{{ route('control.review.approve') }}" class="card overflow-hidden">
    @csrf
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th class="w-10"><input type="checkbox" data-select-all class="size-4 accent-[var(--brand)]" aria-label="الكلّ"></th>
                    <th>رقم الوصل</th><th>التاجر</th><th>المستلم</th><th>الوجهة</th><th>المبلغ</th><th>المرحلة</th><th>معلَّقة منذ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    <tr>
                        <td><input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" data-row-select class="size-4 accent-[var(--brand)]"></td>
                        <td class="num font-semibold" dir="ltr"><a href="{{ route('shipments.show', $shipment) }}" class="text-[var(--brand)] hover:underline">{{ $shipment->number }}</a></td>
                        <td>{{ $shipment->merchant?->business_name }}</td>
                        <td>{{ $shipment->recipient_name }}<div class="text-xs text-ink-500"><x-phone :number="$shipment->recipient_phone" :name="$shipment->recipient_name" /></div></td>
                        <td>{{ $shipment->governorate?->name_ar }}@if ($shipment->city) · <span class="text-xs text-ink-500">{{ $shipment->city->name_ar }}</span>@endif</td>
                        <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                        <td><x-status-badge :status="$shipment->status" /></td>
                        <td class="num text-xs text-ink-500">{{ $shipment->review_hold_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-16 text-center text-ink-500">لا شيء ينتظر المراجعة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($shipments->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 px-5 py-3">
            <button type="submit" class="btn-primary">أجِز المحدَّد</button>
            <span class="text-xs text-ink-500">تُجاز فتخرج مع المندوب بالإسناد المعتاد، ويُكتب في سجلّها من أجازها.</span>
        </div>
    @endif
    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
    @endif
</form>
@endsection

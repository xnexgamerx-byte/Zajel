@extends('layouts.courier')
@section('title', 'اليوم')

@section('content')
<div class="mb-4 grid grid-cols-2 gap-3">
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">سلّمت اليوم</div>
        <div class="mt-0.5 text-3xl font-bold text-emerald-700">{{ number_format($delivered) }}</div>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">حصّلت اليوم</div>
        <div class="mt-0.5 text-2xl font-bold" dir="ltr">{{ number_format($collected) }}</div>
    </div>
</div>

@forelse ($done as $shipment)
    <a href="{{ route('courier.shipments.show', $shipment) }}"
       class="mb-2 block rounded-xl bg-white p-4 shadow-sm active:bg-slate-50">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate font-bold">{{ $shipment->recipient_name }}</div>
                <div class="text-xs text-slate-500">
                    {{ $shipment->governorate->name_ar }}
                    · <span dir="ltr">{{ $shipment->status_changed_at?->format('H:i') }}</span>
                </div>
            </div>
            <x-status-badge :status="$shipment->status" />
        </div>

        @if ($shipment->collected_amount)
            <div class="mt-2 text-sm font-bold text-emerald-700" dir="ltr">
                {{ number_format($shipment->collected_amount) }} د.ع
            </div>
        @elseif ($shipment->lastFailureReason)
            <div class="mt-2 text-sm text-amber-700">{{ $shipment->lastFailureReason->name_ar }}</div>
        @endif
    </a>
@empty
    <div class="rounded-xl bg-white p-10 text-center shadow-sm">
        <p class="font-semibold text-slate-700">لم تسجّل شيئاً اليوم بعد.</p>
    </div>
@endforelse
@endsection

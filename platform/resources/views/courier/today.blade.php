@extends('layouts.courier')
@section('title', 'اليوم')

@section('content')
<div class="mb-4 grid grid-cols-2 gap-3">
    <div class="card p-4">
        <div class="text-xs text-ink-500">سلّمت اليوم</div>
        <div class="mt-0.5 text-3xl font-bold text-ok-700">{{ number_format($delivered) }}</div>
    </div>
    <div class="card p-4">
        <div class="text-xs text-ink-500">حصّلت اليوم</div>
        <div class="mt-0.5 text-2xl font-bold"><span class="num">{{ number_format($collected) }}</span></div>
    </div>
</div>

@forelse ($done as $shipment)
    <a href="{{ route('courier.shipments.show', $shipment) }}"
       class="mb-2 block card p-4 active:bg-ink-50">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate font-bold">{{ $shipment->recipient_name }}</div>
                <div class="text-xs text-ink-500">
                    {{ $shipment->governorate->name_ar }}
                    · <span dir="ltr">{{ $shipment->status_changed_at?->format('H:i') }}</span>
                </div>
            </div>
            <x-status-badge :status="$shipment->status" :shipment="$shipment" />
        </div>

        @if ($shipment->collected_amount)
            <div class="mt-2 text-sm font-bold text-ok-700"><span class="num">{{ number_format($shipment->collected_amount) }}</span> د.ع
            </div>
        @elseif ($shipment->lastFailureReason)
            <div class="mt-2 text-sm text-warn-700">{{ $shipment->lastFailureReason->name_ar }}</div>
        @endif
    </a>
@empty
    <div class="card p-10 text-center">
        <p class="font-semibold text-ink-700">لم تسجّل شيئاً اليوم بعد.</p>
    </div>
@endforelse
@endsection

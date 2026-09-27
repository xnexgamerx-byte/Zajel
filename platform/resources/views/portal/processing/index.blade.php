@extends('layouts.portal')
@section('title', 'للمعالجة')

@section('content')
<div class="mb-5">
    <h1 class="page-title">للمعالجة</h1>
    <p class="mt-1 text-sm text-ink-500">
        شحناتٌ لم يستلمها زبونك من المحاولة. كلِّمه وقرِّر: إعادة التوصيل، أو تأجيلٌ إلى يومٍ اتّفقتما عليه، أو إرجاعها إليك.
    </p>
</div>

<div class="space-y-3">
    @forelse ($shipments as $shipment)
        <form method="POST" action="{{ route('portal.processing.store', $shipment) }}" class="card p-4">
            @csrf
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <a href="{{ route('portal.shipments.show', $shipment) }}" class="num font-semibold text-[var(--brand)] hover:underline">{{ $shipment->number }}</a>
                    <span class="ms-2 font-medium">{{ $shipment->recipient_name }}</span>
                    <div class="mt-0.5 text-xs text-ink-500">
                        {{ collect([$shipment->governorate?->name_ar, $shipment->city?->name_ar])->filter()->implode(' · ') }}
                        · <span class="num" dir="ltr">{{ $shipment->recipient_phone }}</span>
                        · منذ {{ $shipment->status_changed_at?->diffForHumans(null, true) }}
                    </div>
                </div>
                @if ($shipment->lastFailureReason)
                    <span class="chip chip-warn">{{ $shipment->lastFailureReason->name_ar }}</span>
                @endif
            </div>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <div>
                    <label class="field-label" for="action-{{ $shipment->id }}">القرار</label>
                    <select id="action-{{ $shipment->id }}" name="action" class="field-input">
                        @foreach ($actions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="until-{{ $shipment->id }}">إلى يوم (للتأجيل)</label>
                    <input id="until-{{ $shipment->id }}" name="until" type="date" class="field-input" min="{{ today()->toDateString() }}">
                </div>
                <div class="min-w-48 flex-1">
                    <label class="field-label" for="note-{{ $shipment->id }}">ما قاله الزبون</label>
                    <input id="note-{{ $shipment->id }}" name="note" maxlength="255" class="field-input" placeholder="مثال: يستلم بعد الساعة ٤">
                </div>
                <button type="submit" class="btn-primary">سجِّل القرار</button>
            </div>
        </form>
    @empty
        <section class="card p-10 text-center text-sm text-ink-500">لا شحنة تنتظر قرارك الآن.</section>
    @endforelse
</div>

@if ($shipments->hasPages())
    <div class="mt-4">{{ $shipments->links() }}</div>
@endif
@endsection

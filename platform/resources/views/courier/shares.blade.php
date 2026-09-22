@extends('layouts.courier')
@section('title', 'حصصي')

@section('content')
<div class="mb-4">
    <h1 class="text-lg font-bold">حصصي من الاستلام</h1>
    <p class="mt-1 text-sm text-ink-500">
        رصيدك المستحقّ الآن
        <span class="num font-bold text-[var(--brand)]">{{ number_format($courier->commission_balance) }}</span> د.ع
    </p>
</div>

<div class="space-y-3">
    @forelse ($shares as $share)
        <section class="card p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div class="num font-semibold">{{ $share->pickupRequest?->number ?? '—' }}</div>
                    <div class="text-xs text-ink-500">{{ $share->created_at->format('Y-m-d') }}</div>
                </div>
                <div class="text-end">
                    <div class="num text-lg font-bold">{{ number_format($share->finalAmount()) }}</div>
                    <div class="text-xs text-ink-500">
                        <span class="num">{{ $share->shipments_count }}</span> طرداً ×
                        <span class="num">{{ number_format($share->rate) }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-2">
                <span class="chip {{ $share->statusTone() }}">{{ $share->statusLabel() }}</span>
                @if ($share->resolution_note)
                    <span class="text-xs text-ink-500">{{ $share->resolution_note }}</span>
                @endif
            </div>

            @if ($share->status === 'accrued')
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm font-semibold text-[var(--brand)]">
                        العدد غير صحيح؟ اعترض
                    </summary>
                    <form method="POST" action="{{ route('courier.shares.object', $share) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label class="field-label" for="claimed-{{ $share->id }}">كم طرداً جمعتَ فعلاً؟</label>
                            <input id="claimed-{{ $share->id }}" name="claimed_count" type="number" min="0" step="1"
                                   required class="field-input num" value="{{ $share->shipments_count }}">
                        </div>
                        <div>
                            <label class="field-label" for="reason-{{ $share->id }}">لماذا؟</label>
                            <input id="reason-{{ $share->id }}" name="reason" type="text" maxlength="255" required
                                   class="field-input" placeholder="جمعتُ طردين إضافيين لم يُسجَّلا">
                        </div>
                        <button type="submit" class="btn-primary w-full">أرسل الاعتراض</button>
                    </form>
                </details>
            @endif
        </section>
    @empty
        <section class="card p-8 text-center">
            <p class="text-ink-500">لا حصص بعد.</p>
        </section>
    @endforelse
</div>
@endsection

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

@if ($payouts->isNotEmpty())
    {{-- ما دُفع له: يؤكّد استلامه بنفسه --}}
    <section class="card mb-4 p-4">
        <h2 class="mb-2 text-sm font-bold">دفعات أرباحي</h2>
        <div class="divide-y divide-ink-100 text-sm">
            @foreach ($payouts as $payout)
                <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div>
                        <span class="num font-semibold">{{ $payout->number }}</span>
                        <span class="text-xs text-ink-500">{{ $payout->created_at->format('Y-m-d') }}</span>
                        @if ($payout->centre_amount > 0)
                            <div class="text-xs text-ink-500">استحققتَ {{ number_format($payout->earned) }}، وللمركز {{ number_format($payout->centre_amount) }}</div>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="num font-bold">{{ number_format($payout->paid_amount) }}</span>
                        @if ($payout->confirmed_at)
                            <span class="chip chip-ok">استلمتُها</span>
                        @else
                            <form method="POST" action="{{ route('courier.payouts.confirm', $payout) }}">
                                @csrf
                                <button class="btn-primary py-1 text-xs">استلمتُها</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

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

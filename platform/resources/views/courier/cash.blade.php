@extends('layouts.courier')
@section('title', 'حسابي')

@section('content')
<div class="mb-4 rounded-xl border border-ink-200 bg-white p-5 shadow-xs">
    <div class="text-xs text-ink-500">الواجب تسليمه للشركة</div>
    <div class="mt-1 text-4xl font-bold text-[var(--brand)]" dir="ltr">
        {{ number_format($courier->netDue()) }} <span class="text-lg text-ink-500">د.ع</span>
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-ink-100 pt-4 text-sm">
        <div>
            <dt class="text-xs text-ink-500">نقد بيدك</dt>
            <dd class="text-xl font-bold {{ $courier->hasReachedCashLimit() ? 'text-bad-700' : '' }}" dir="ltr">
                {{ number_format($courier->cash_in_hand) }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-ink-500">عمولتك</dt>
            <dd class="text-xl font-bold text-ok-700" dir="ltr">
                {{ number_format($courier->commission_balance) }}
            </dd>
        </div>
    </dl>

    @if ($courier->hasReachedCashLimit())
        <div class="mt-3 rounded-lg bg-bad-50 px-3 py-2 text-sm font-semibold text-bad-700">
            تجاوزت سقف النقد. سلّم للشركة قبل أن تُسنَد إليك شحنات جديدة.
        </div>
    @endif
</div>

@if ($settlements->isNotEmpty())
    <h2 class="mb-2 px-1 text-sm font-bold text-ink-600">كشوفاتك</h2>
    <div class="mb-4 space-y-2">
        @foreach ($settlements as $settlement)
            <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-xs font-semibold" dir="ltr">{{ $settlement->code }}</span>
                    <x-settlement-status :status="$settlement->status" />
                </div>
                <div class="mt-1 flex items-center justify-between">
                    <span class="text-xs text-ink-500">
                        {{ \App\Support\Arabic::shipments((int) $settlement->shipments_count) }}
                        · <span dir="ltr">{{ $settlement->created_at->format('Y-m-d') }}</span>
                    </span>
                    <span class="font-bold" dir="ltr">{{ number_format($settlement->net_amount) }}</span>
                </div>
            </div>
        @endforeach
    </div>
@endif

<h2 class="mb-2 px-1 text-sm font-bold text-ink-600">آخر الحركات</h2>

@forelse ($transactions as $tx)
    <div class="mb-2 flex items-center gap-3 rounded-xl border border-ink-200 bg-white p-3.5 shadow-xs">
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-medium">
                {{ ['cod_collected' => 'تحصيل', 'commission' => 'عمولة', 'commission_paid' => 'قبض عمولة',
                    'cash_handover' => 'تسليم نقد', 'deduction' => 'خصم'][$tx->category] ?? $tx->category }}
                @if ($tx->shipment)
                    <span class="font-mono text-xs text-ink-400" dir="ltr">{{ $tx->shipment->number }}</span>
                @endif
            </div>
            <div class="text-xs text-ink-400" dir="ltr">{{ $tx->created_at->format('Y-m-d H:i') }}</div>
        </div>
        <div class="shrink-0 font-bold {{ $tx->direction === 'credit' ? 'text-ok-700' : 'text-ink-900' }}"
             dir="ltr">
            {{ number_format($tx->amount) }}
        </div>
    </div>
@empty
    <div class="rounded-xl border border-ink-200 bg-white p-8 text-center shadow-xs">
        <p class="text-sm text-ink-500">لا حركات بعد.</p>
    </div>
@endforelse
@endsection

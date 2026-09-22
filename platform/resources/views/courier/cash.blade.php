@extends('layouts.courier')
@section('title', 'حسابي')

@section('content')
<div class="mb-4 rounded-xl bg-white p-5 shadow-sm">
    <div class="text-xs text-slate-500">الواجب تسليمه للشركة</div>
    <div class="mt-1 text-4xl font-bold text-brand-700" dir="ltr">
        {{ number_format($courier->netDue()) }} <span class="text-lg text-slate-500">د.ع</span>
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
        <div>
            <dt class="text-xs text-slate-500">نقد بيدك</dt>
            <dd class="text-xl font-bold {{ $courier->hasReachedCashLimit() ? 'text-red-600' : '' }}" dir="ltr">
                {{ number_format($courier->cash_in_hand) }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500">عمولتك</dt>
            <dd class="text-xl font-bold text-emerald-700" dir="ltr">
                {{ number_format($courier->commission_balance) }}
            </dd>
        </div>
    </dl>

    @if ($courier->hasReachedCashLimit())
        <div class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
            تجاوزت سقف النقد. سلّم للشركة قبل أن تُسنَد إليك شحنات جديدة.
        </div>
    @endif
</div>

@if ($settlements->isNotEmpty())
    <h2 class="mb-2 px-1 text-sm font-bold text-slate-600">كشوفاتك</h2>
    <div class="mb-4 space-y-2">
        @foreach ($settlements as $settlement)
            <div class="rounded-xl bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-xs font-semibold" dir="ltr">{{ $settlement->code }}</span>
                    <x-settlement-status :status="$settlement->status" />
                </div>
                <div class="mt-1 flex items-center justify-between">
                    <span class="text-xs text-slate-500">
                        {{ $settlement->shipments_count }} شحنة
                        · <span dir="ltr">{{ $settlement->created_at->format('Y-m-d') }}</span>
                    </span>
                    <span class="font-bold" dir="ltr">{{ number_format($settlement->net_amount) }}</span>
                </div>
            </div>
        @endforeach
    </div>
@endif

<h2 class="mb-2 px-1 text-sm font-bold text-slate-600">آخر الحركات</h2>

@forelse ($transactions as $tx)
    <div class="mb-2 flex items-center gap-3 rounded-xl bg-white p-3.5 shadow-sm">
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-medium">
                {{ ['cod_collected' => 'تحصيل', 'commission' => 'عمولة', 'commission_paid' => 'قبض عمولة',
                    'cash_handover' => 'تسليم نقد', 'deduction' => 'خصم'][$tx->category] ?? $tx->category }}
                @if ($tx->shipment)
                    <span class="font-mono text-xs text-slate-400" dir="ltr">{{ $tx->shipment->number }}</span>
                @endif
            </div>
            <div class="text-xs text-slate-400" dir="ltr">{{ $tx->created_at->format('Y-m-d H:i') }}</div>
        </div>
        <div class="shrink-0 font-bold {{ $tx->direction === 'credit' ? 'text-emerald-700' : 'text-slate-900' }}"
             dir="ltr">
            {{ number_format($tx->amount) }}
        </div>
    </div>
@empty
    <div class="rounded-xl bg-white p-8 text-center shadow-sm">
        <p class="text-sm text-slate-500">لا حركات بعد.</p>
    </div>
@endforelse
@endsection

@extends('layouts.portal')
@section('title', 'حسابي')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">حسابي مع {{ $company->name }}</h1>
        <p class="mt-1 text-sm text-ink-500">
            كل سطر هنا نتيجة حدث على شحنة — لا إدخال يدوي.
        </p>
    </div>
    <div class="card px-5 py-3 text-center">
        <div class="text-xs text-ink-500">{{ $merchant->balance >= 0 ? 'لك' : 'عليك' }}</div>
        <div class="text-2xl font-bold {{ $merchant->balance >= 0 ? 'text-[var(--brand)]' : 'text-bad-700' }}" dir="ltr">
            {{ number_format(abs($merchant->balance)) }} د.ع
        </div>
    </div>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
            <div>
                <label class="field-label" for="from">من تاريخ</label>
                <input id="from" name="from" type="date" class="field-input" value="{{ request('from') }}">
            </div>
            <div>
                <label class="field-label" for="to">إلى تاريخ</label>
                <input id="to" name="to" type="date" class="field-input" value="{{ request('to') }}">
            </div>
            <button type="submit" class="btn-primary">تطبيق</button>
            <a href="{{ route('portal.statement') }}" class="btn-ghost">مسح</a>
        </form>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th >التاريخ</th>
                            <th >البيان</th>
                            <th >لك</th>
                            <th >عليك</th>
                            <th >الرصيد</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @forelse ($transactions as $tx)
                            <tr class="hover:bg-ink-50">
                                <td class="px-4 py-2.5 text-xs text-ink-500" dir="ltr">
                                    {{ $tx->created_at->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-4 py-2.5">
                                    {{ $tx->description }}
                                    @if ($tx->shipment)
                                        <a href="{{ route('portal.shipments.show', $tx->shipment) }}"
                                           class="ms-1 font-mono text-xs text-[var(--brand)] hover:underline" dir="ltr">
                                            {{ $tx->shipment->number }}
                                        </a>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-ok-700" dir="ltr">
                                    {{ $tx->direction === 'credit' ? number_format($tx->amount) : '' }}
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-bad-700" dir="ltr">
                                    {{ $tx->direction === 'debit' ? number_format($tx->amount) : '' }}
                                </td>
                                <td class="px-4 py-2.5 font-semibold" dir="ltr">
                                    {{ number_format($tx->balance_after) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-16 text-center text-ink-500">
                                    لا حركات في هذه الفترة.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($transactions->hasPages())
                <div class="border-t border-ink-100 px-4 py-3">{{ $transactions->links() }}</div>
            @endif
        </div>
    </div>

    <div>
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">كشوفات الدفع</h2>

            @if ($settlements->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">لا كشوفات بعد.</p>
            @else
                <div class="divide-y divide-ink-100">
                    @foreach ($settlements as $settlement)
                        <div class="py-2.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-xs font-semibold" dir="ltr">{{ $settlement->code }}</span>
                                <x-settlement-status :status="$settlement->status" />
                            </div>
                            <div class="mt-1 flex items-center justify-between text-sm">
                                <span class="text-xs text-ink-500" dir="ltr">
                                    {{ $settlement->from_date?->format('Y-m-d') }} — {{ $settlement->to_date?->format('Y-m-d') }}
                                </span>
                                <span class="font-bold text-[var(--brand)]" dir="ltr">
                                    {{ number_format($settlement->net_amount) }}
                                </span>
                            </div>
                            @if ($settlement->status === 'paid')
                                <div class="mt-0.5 text-xs text-ok-700">
                                    دُفع {{ $settlement->paid_at?->format('Y-m-d') }}
                                    @if ($settlement->payout_reference)
                                        · <span dir="ltr">{{ $settlement->payout_reference }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
@endsection

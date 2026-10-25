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
</div>

<x-merchant-balance :merchant="$merchant" class="mb-5" />

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
                            @if ($settlement->advance_deduction)
                                {{-- خُصم منه ما بقي من سلفته (docs/plan/38) --}}
                                <div class="mt-0.5 text-xs text-warn-700">
                                    خُصم منه <span class="num">{{ number_format($settlement->advance_deduction) }}</span> سداداً لسلفتك
                                </div>
                            @endif
                            @if ($settlement->status === 'paid')
                                <div class="mt-0.5 text-xs text-ok-700">
                                    دُفع {{ $settlement->paid_at?->format('Y-m-d') }}
                                    @if ($settlement->payout_reference)
                                        · <span dir="ltr">{{ $settlement->payout_reference }}</span>
                                    @endif
                                </div>
                                @if ($settlement->merchant_confirmed_at)
                                    <div class="text-xs text-ink-500">أكّدتَ استلامها {{ $settlement->merchant_confirmed_at->format('Y-m-d') }}</div>
                                @elseif ($scanRequired)
                                    {{-- يُؤكَّد بمسح الوصل الذي وصل مع المبلغ (docs/plan/69) --}}
                                    <a href="{{ route('portal.receive.index') }}" class="btn-primary mt-1 inline-flex py-1 text-xs">
                                        <x-icon name="qr" class="size-4" /> امسح الوصل لتأكيد استلامها</a>
                                @else
                                    <form method="POST" action="{{ route('portal.settlements.confirm', $settlement) }}" class="mt-1">
                                        @csrf
                                        <button class="btn-primary py-1 text-xs">استلمتُها</button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- «كشوفات الرواجع»: ما سُلّم له من رواجع بإيصالاتها (docs/plan/69) --}}
        <section class="card mt-5 p-5" aria-labelledby="return-statements">
            <div class="mb-4 flex items-center justify-between gap-2">
                <h2 id="return-statements" class="text-sm font-bold">كشوفات الرواجع</h2>
                <a href="{{ route('portal.requests.index') }}" class="text-xs font-semibold text-[var(--brand)] hover:underline">كلّها</a>
            </div>

            @if ($returnBatches->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">لم تُسلَّم لك رواجع بإيصال بعد.</p>
            @else
                <div class="divide-y divide-ink-100">
                    @foreach ($returnBatches as $batch)
                        <div class="py-2.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="num text-xs font-semibold">{{ $batch->number }}</span>
                                <span class="text-xs text-ink-500">{{ \App\Support\Arabic::shipments($batch->shipments_count) }} · {{ $batch->viaLabel(true) }}</span>
                            </div>
                            @if ($batch->isReceived())
                                <div class="mt-0.5 text-xs text-ok-700">وصلتك {{ $batch->received_at->format('Y-m-d') }}</div>
                            @elseif ($scanRequired)
                                <a href="{{ route('portal.receive.index') }}" class="btn-primary mt-1 inline-flex py-1 text-xs">
                                    <x-icon name="qr" class="size-4" /> امسح الإيصال لتأكيد استلامها</a>
                            @else
                                <div class="mt-0.5 text-xs text-warn-700">بانتظار تأكيدك — من «طلباتي»</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'تسويات التجّار')

@section('content')
<div class="mb-5">
    <h1 class="page-title">تسويات التجّار</h1>
    <p class="mt-1 text-sm text-ink-500">
        كشف حساب ثم دفع — خطوتان لا واحدة، فالكشف يُتّفق عليه قبل أن يتحرّك المال.
    </p>
</div>

@if ($pending->isNotEmpty())
    <section class="card mb-5 p-5">
        <h2 class="mb-3 text-sm font-bold">تجّار لهم أو عليهم رصيد</h2>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($pending as $merchant)
                <form method="POST" action="{{ route('settlements.merchants.store') }}"
                      class="rounded-lg border border-ink-200 p-4">
                    @csrf
                    <input type="hidden" name="merchant_id" value="{{ $merchant->id }}">

                    <div class="font-semibold">{{ $merchant->business_name }}</div>
                    <div class="text-xs text-ink-500" dir="ltr">{{ $merchant->code }}</div>

                    <div class="mt-3 flex items-baseline justify-between">
                        <span class="text-sm text-ink-500">
                            {{ $merchant->balance > 0 ? 'له عند الشركة' : 'عليه للشركة' }}
                        </span>
                        <span class="text-lg font-bold {{ $merchant->balance > 0 ? 'text-[var(--brand)]' : 'text-bad-700' }}"
                              dir="ltr">{{ number_format(abs($merchant->balance)) }}</span>
                    </div>

                    <button type="submit" class="btn-primary mt-3 w-full">افتح كشفاً</button>
                </form>
            @endforeach
        </div>
    </section>
@endif

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th >الكشف</th>
                    <th >التاجر</th>
                    <th >شحنات</th>
                    <th >رواجع</th>
                    <th >المحصَّل</th>
                    <th >الأجور</th>
                    <th >الصافي له</th>
                    <th >الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($settlements as $settlement)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('settlements.merchants.show', $settlement) }}"
                               class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">
                                {{ $settlement->code }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ $settlement->merchant->business_name }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format($settlement->shipments_count) }}</td>
                        <td class="px-4 py-3 text-warn-700" dir="ltr">{{ number_format($settlement->returned_count) }}</td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($settlement->cod_total) }}</td>
                        <td class="px-4 py-3 text-ink-600" dir="ltr">
                            {{ number_format($settlement->delivery_fees_total + $settlement->return_fees_total + $settlement->cod_fees_total) }}
                        </td>
                        <td class="px-4 py-3 font-bold {{ $settlement->net_amount >= 0 ? 'text-[var(--brand)]' : 'text-bad-700' }}"
                            dir="ltr">{{ number_format($settlement->net_amount) }}</td>
                        <td class="px-4 py-3"><x-settlement-status :status="$settlement->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-16 text-center text-ink-500">لا كشوفات بعد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($settlements->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $settlements->links() }}</div>
    @endif
</div>
@endsection

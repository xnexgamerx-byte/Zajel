@extends('layouts.app')
@section('title', 'تسويات التجّار')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">تسويات التجّار</h1>
    <p class="mt-1 text-sm text-slate-500">
        كشف حساب ثم دفع — خطوتان لا واحدة، فالكشف يُتّفق عليه قبل أن يتحرّك المال.
    </p>
</div>

@if ($pending->isNotEmpty())
    <section class="card mb-5 p-5">
        <h2 class="mb-3 text-sm font-bold">تجّار لهم أو عليهم رصيد</h2>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($pending as $merchant)
                <form method="POST" action="{{ route('settlements.merchants.store') }}"
                      class="rounded-lg border border-slate-200 p-4">
                    @csrf
                    <input type="hidden" name="merchant_id" value="{{ $merchant->id }}">

                    <div class="font-semibold">{{ $merchant->business_name }}</div>
                    <div class="text-xs text-slate-500" dir="ltr">{{ $merchant->code }}</div>

                    <div class="mt-3 flex items-baseline justify-between">
                        <span class="text-sm text-slate-500">
                            {{ $merchant->balance > 0 ? 'له عند الشركة' : 'عليه للشركة' }}
                        </span>
                        <span class="text-lg font-bold {{ $merchant->balance > 0 ? 'text-brand-700' : 'text-red-600' }}"
                              dir="ltr">{{ number_format(abs($merchant->balance)) }}</span>
                    </div>

                    <button class="btn-primary mt-3 w-full">افتح كشفاً</button>
                </form>
            @endforeach
        </div>
    </section>
@endif

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">الكشف</th>
                    <th class="px-4 py-3 text-start font-semibold">التاجر</th>
                    <th class="px-4 py-3 text-start font-semibold">شحنات</th>
                    <th class="px-4 py-3 text-start font-semibold">رواجع</th>
                    <th class="px-4 py-3 text-start font-semibold">المحصَّل</th>
                    <th class="px-4 py-3 text-start font-semibold">الأجور</th>
                    <th class="px-4 py-3 text-start font-semibold">الصافي له</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($settlements as $settlement)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('settlements.merchants.show', $settlement) }}"
                               class="font-mono font-semibold text-brand-700 hover:underline" dir="ltr">
                                {{ $settlement->code }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ $settlement->merchant->business_name }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format($settlement->shipments_count) }}</td>
                        <td class="px-4 py-3 text-amber-700" dir="ltr">{{ number_format($settlement->returned_count) }}</td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($settlement->cod_total) }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">
                            {{ number_format($settlement->delivery_fees_total + $settlement->return_fees_total + $settlement->cod_fees_total) }}
                        </td>
                        <td class="px-4 py-3 font-bold {{ $settlement->net_amount >= 0 ? 'text-brand-700' : 'text-red-600' }}"
                            dir="ltr">{{ number_format($settlement->net_amount) }}</td>
                        <td class="px-4 py-3"><x-settlement-status :status="$settlement->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-16 text-center text-slate-500">لا كشوفات بعد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($settlements->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $settlements->links() }}</div>
    @endif
</div>
@endsection

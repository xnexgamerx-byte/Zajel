@extends('layouts.app')
@section('title', 'تسويات التجّار')

@section('content')
<div class="mb-5">
    <h1 class="page-title">تسويات التجّار</h1>
    <p class="mt-1 text-sm text-ink-500">
        كشف حساب ثم دفع — خطوتان لا واحدة، فالكشف يُتّفق عليه قبل أن يتحرّك المال.
    </p>
</div>

<x-settlement-filters :filters="$filters" :actors="$actors" :action="route('settlements.merchants.index')"
                      placeholder="ابحث عن تاجر بالاسم أو الكود أو الهاتف" />

@if ($filters->q !== '' && $pending->isEmpty())
    <p class="card mb-5 p-5 text-center text-sm text-ink-500">لا التاجر بهذا البحث بحاجة إلى تسوية.</p>
@endif

@if ($pending->isNotEmpty())
    <section class="card mb-5 p-5">
        <h2 class="mb-3 text-sm font-bold">تجّار لهم أو عليهم رصيد</h2>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($pending as $merchant)
                @php $b = $balances[$merchant->id]; @endphp
                <form method="POST" action="{{ route('settlements.merchants.store') }}"
                      class="rounded-lg border border-ink-200 p-4" data-merchant-card="{{ $merchant->code }}">
                    @csrf
                    <input type="hidden" name="merchant_id" value="{{ $merchant->id }}">

                    <div class="font-semibold">{{ $merchant->business_name }}</div>
                    <div class="text-xs text-ink-500" dir="ltr">{{ $merchant->code }}</div>

                    <div class="mt-3 flex items-baseline justify-between">
                        <span class="text-sm text-ink-500">
                            {{ $merchant->balance > 0 ? 'إجمالي مستحقّاته' : 'عليه للشركة' }}
                        </span>
                        <span class="text-lg font-bold {{ $merchant->balance > 0 ? 'text-ink-900' : 'text-bad-700' }}"
                              dir="ltr">{{ number_format(abs($merchant->balance)) }}</span>
                    </div>

                    {{--
                        كلّ رقمٍ هنا يقابله شيءٌ يُفتح (docs/plan/51): «المتاح للتسوية» صافي الكشف نفسه —
                        من شرطه، بعد اقتطاع السلف — لا الإجمالي ناقصاً ما قيد المطابقة.
                    --}}
                    @if ($b->ready !== 0 || $b->draft)
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm text-ink-500">المتاح للتسوية{{ $b->readyCount ? ' — '.\App\Support\Arabic::shipments($b->readyCount) : '' }}</span>
                            <span class="font-bold {{ $b->ready - $b->advanceDeduction() < 0 ? 'text-bad-700' : 'text-ok-700' }}" dir="ltr" data-ready>{{ number_format($b->ready - $b->advanceDeduction()) }}</span>
                        </div>
                    @endif
                    @if ($b->confirmed !== 0)
                        <div class="flex items-baseline justify-between">
                            <a href="{{ route('settlements.merchants.show', $b->confirmedFirst) }}" class="text-sm text-[var(--brand)] hover:underline">
                                بانتظار الدفع — {{ $b->confirmedCount > 1 ? $b->confirmedCount.' كشوف مُقفلة' : 'كشف '.$b->confirmedFirst->code }}
                            </a>
                            <span class="font-bold text-ink-900" dir="ltr" data-awaiting-payment>{{ number_format($b->confirmed) }}</span>
                        </div>
                    @endif
                    @if ($b->pending !== 0)
                        <p class="mt-1 flex items-center gap-1 text-xs text-warn-700">
                            <x-icon name="clock" class="size-4" />
                            <span><span class="num">{{ number_format($b->pending) }}</span> قيد المطابقة — {{ \App\Support\Arabic::shipments($b->pendingCount) }} لم يُحاسَب مندوبها</span>
                        </p>
                    @endif
                    @if ($b->advances > 0)
                        <p class="mt-1 flex items-center gap-1 text-xs text-bad-700">
                            <x-icon name="cash" class="size-4" />
                            <span>سلفة عليه <span class="num">{{ number_format($b->advances) }}</span> — يُقتطع منها {{ number_format($b->advanceDeduction()) }} عند إقفال الكشف</span>
                        </p>
                    @endif
                    @if ($b->unexplained() !== 0)
                        <p class="mt-1 rounded-md bg-bad-50 px-2 py-1 text-xs text-bad-700" data-unexplained>
                            فرق <span class="num font-bold" dir="ltr">{{ number_format($b->unexplained()) }}</span> لا يقابله كشفٌ ولا شحنة — راجعه في «مطابقة الدفتر».
                        </p>
                    @endif

                    @if ($b->draft)
                        <a href="{{ route('settlements.merchants.show', $b->draft) }}" class="btn-primary mt-3 w-full">
                            افتح المسودّة {{ $b->draft->code }}
                        </a>
                    @elseif ($b->readyCount > 0)
                        <button type="submit" class="btn-primary mt-3 w-full">افتح كشفاً</button>
                    @else
                        <p class="mt-3 text-center text-xs text-ink-500">لا شيء جاهز لكشفٍ جديد الآن.</p>
                    @endif
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
                    <th >بواسطة</th>
                    <th >التاريخ</th>
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
                        <td class="px-4 py-3">
                            <x-settlement-status :status="$settlement->status" />
                        </td>
                        <td class="px-4 py-3 text-xs text-ink-600">{{ $settlement->paidBy?->name ?? $settlement->confirmedBy?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-ink-500" dir="ltr">{{ $settlement->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-16 text-center text-ink-500">{{ $filters->active() ? 'لا كشوف بهذا البحث.' : 'لا كشوفات بعد.' }}</td>
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

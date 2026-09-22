@extends('layouts.app')
@section('title', 'تسويات المندوبين')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">تسويات المندوبين</h1>
    <p class="mt-1 text-sm text-slate-500">
        إقفال حساب المندوب: يسلّم النقد، يقبض عمولته، ويُغلَق الكشف فلا يُعدَّل.
    </p>
</div>

@if ($pending->isNotEmpty())
    <section class="card mb-5 p-5">
        <h2 class="mb-3 text-sm font-bold">مندوبون بحاجة إلى تسوية</h2>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($pending as $courier)
                <form method="POST" action="{{ route('settlements.couriers.store') }}"
                      class="rounded-lg border border-slate-200 p-4">
                    @csrf
                    <input type="hidden" name="courier_id" value="{{ $courier->id }}">

                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold">{{ $courier->name }}</div>
                            <div class="text-xs text-slate-500" dir="ltr">{{ $courier->code }}</div>
                        </div>
                        @if ($courier->hasReachedCashLimit())
                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-red-200">
                                تجاوز السقف
                            </span>
                        @endif
                    </div>

                    <dl class="mt-3 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">نقد بيده</dt>
                            <dd class="font-semibold" dir="ltr">{{ number_format($courier->cash_in_hand) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">عمولته</dt>
                            <dd class="font-semibold text-emerald-700" dir="ltr">
                                {{ number_format($courier->commission_balance) }}
                            </dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-100 pt-1">
                            <dt class="font-semibold">الواجب تسليمه</dt>
                            <dd class="font-bold text-brand-700" dir="ltr">{{ number_format($courier->netDue()) }}</dd>
                        </div>
                    </dl>

                    <button type="submit" class="btn-primary mt-3 w-full">افتح كشفاً</button>
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
                    <th class="px-4 py-3 text-start font-semibold">المندوب</th>
                    <th class="px-4 py-3 text-start font-semibold">شحنات</th>
                    <th class="px-4 py-3 text-start font-semibold">المحصَّل</th>
                    <th class="px-4 py-3 text-start font-semibold">العمولة</th>
                    <th class="px-4 py-3 text-start font-semibold">الصافي</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                    <th class="px-4 py-3 text-start font-semibold">التاريخ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($settlements as $settlement)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('settlements.couriers.show', $settlement) }}"
                               class="font-mono font-semibold text-brand-700 hover:underline" dir="ltr">
                                {{ $settlement->code }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ $settlement->courier->name }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format($settlement->shipments_count) }}</td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($settlement->cod_total) }}</td>
                        <td class="px-4 py-3 text-emerald-700" dir="ltr">{{ number_format($settlement->commission_total) }}</td>
                        <td class="px-4 py-3 font-bold text-brand-700" dir="ltr">{{ number_format($settlement->net_amount) }}</td>
                        <td class="px-4 py-3">
                            <x-settlement-status :status="$settlement->status" />
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">
                            {{ $settlement->created_at->format('Y-m-d H:i') }}
                        </td>
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

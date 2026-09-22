@extends('layouts.app')
@section('title', 'نقد المندوبين')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">النقد بيد المندوبين</h1>
        <p class="mt-1 text-sm text-ink-500">
            ما حُصِّل من الزبائن ولم يُسلَّم للشركة بعد، والعمولة المستحقّة لكل مندوب.
            الأرقام مشتقّة من دفتر الحركات لا مُدخَلة يدوياً.
        </p>
    </div>
    <div class="flex gap-3">
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-ink-500">نقد معلّق</div>
            <div class="text-2xl font-bold text-warn-700"><span class="num">{{ number_format($total) }}</span> د.ع</div>
        </div>
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-ink-500">عمولات مستحقّة</div>
            <div class="text-2xl font-bold text-ok-700"><span class="num">{{ number_format($commission) }}</span> د.ع</div>
        </div>
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-ink-500">صافي الواجب تسليمه</div>
            <div class="text-2xl font-bold text-[var(--brand)]"><span class="num">{{ number_format($total - $commission) }}</span> د.ع</div>
        </div>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th >المندوب</th>
                    <th >الهاتف</th>
                    <th >النقد بيده</th>
                    <th >عمولته</th>
                    <th >الواجب تسليمه</th>
                    <th >السقف</th>
                    <th >الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($couriers as $courier)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3 font-medium">{{ $courier->name }}</td>
                        <td class="px-4 py-3 text-ink-600" dir="ltr">{{ $courier->phone }}</td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($courier->cash_in_hand) }}</td>
                        <td class="px-4 py-3 text-ok-700" dir="ltr">
                            {{ number_format($courier->commission_balance) }}
                        </td>
                        <td class="px-4 py-3 font-bold text-[var(--brand)]" dir="ltr">
                            {{ number_format($courier->netDue()) }}
                        </td>
                        <td class="px-4 py-3 text-ink-600" dir="ltr">
                            {{ $courier->cash_limit ? number_format($courier->cash_limit) : 'بلا سقف' }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($courier->hasReachedCashLimit())
                                <span class="inline-flex rounded-full bg-bad-50 px-2.5 py-0.5 text-xs font-semibold text-bad-700 ring-1 ring-bad-200">
                                    تجاوز السقف — يجب التسوية
                                </span>
                            @else
                                <span class="inline-flex rounded-full bg-ok-50 px-2.5 py-0.5 text-xs font-semibold text-ok-700 ring-1 ring-ok-200">
                                    ضمن الحد
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-16 text-center text-ink-500">
                            لا يوجد نقد معلّق عند أي مندوب.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'نقد المندوبين')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">النقد بيد المندوبين</h1>
        <p class="mt-1 text-sm text-slate-500">
            ما حُصِّل من الزبائن ولم يُسلَّم للشركة بعد، والعمولة المستحقّة لكل مندوب.
            الأرقام مشتقّة من دفتر الحركات لا مُدخَلة يدوياً.
        </p>
    </div>
    <div class="flex gap-3">
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-slate-500">نقد معلّق</div>
            <div class="text-2xl font-bold text-amber-700" dir="ltr">{{ number_format($total) }} د.ع</div>
        </div>
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-slate-500">عمولات مستحقّة</div>
            <div class="text-2xl font-bold text-emerald-700" dir="ltr">{{ number_format($commission) }} د.ع</div>
        </div>
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-slate-500">صافي الواجب تسليمه</div>
            <div class="text-2xl font-bold text-brand-700" dir="ltr">{{ number_format($total - $commission) }} د.ع</div>
        </div>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">المندوب</th>
                    <th class="px-4 py-3 text-start font-semibold">الهاتف</th>
                    <th class="px-4 py-3 text-start font-semibold">النقد بيده</th>
                    <th class="px-4 py-3 text-start font-semibold">عمولته</th>
                    <th class="px-4 py-3 text-start font-semibold">الواجب تسليمه</th>
                    <th class="px-4 py-3 text-start font-semibold">السقف</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($couriers as $courier)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium">{{ $courier->name }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $courier->phone }}</td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($courier->cash_in_hand) }}</td>
                        <td class="px-4 py-3 text-emerald-700" dir="ltr">
                            {{ number_format($courier->commission_balance) }}
                        </td>
                        <td class="px-4 py-3 font-bold text-brand-700" dir="ltr">
                            {{ number_format($courier->netDue()) }}
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">
                            {{ $courier->cash_limit ? number_format($courier->cash_limit) : 'بلا سقف' }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($courier->hasReachedCashLimit())
                                <span class="inline-flex rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-red-200">
                                    تجاوز السقف — يجب التسوية
                                </span>
                            @else
                                <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                    ضمن الحد
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-16 text-center text-slate-500">
                            لا يوجد نقد معلّق عند أي مندوب.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

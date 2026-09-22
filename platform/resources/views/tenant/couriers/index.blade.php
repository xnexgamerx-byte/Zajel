@extends('layouts.app')
@section('title', 'المندوبون')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">المندوبون</h1>
        <p class="mt-1 text-sm text-slate-500">
            مندوب الاستلام ومندوب التوصيل وظيفتان مختلفتان — والنوع هنا يحدّد ما يُسنَد إليه.
        </p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('couriers.cash') }}" class="btn-ghost">نقد المندوبين</a>
        <a href="{{ route('couriers.create') }}" class="btn-primary">+ مندوب جديد</a>
    </div>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-64 flex-1">
        <label class="field-label" for="q">بحث</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input" placeholder="الاسم · الهاتف · الرمز">
    </div>
    <div>
        <label class="field-label" for="type">النوع</label>
        <select id="type" name="type" class="field-input">
            <option value="">الكل</option>
            @foreach (['delivery' => 'توصيل', 'pickup' => 'استلام', 'both' => 'الاثنان'] as $value => $label)
                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="status">الحالة</label>
        <select id="status" name="status" class="field-input">
            <option value="">الكل</option>
            @foreach (['active' => 'مفعّل', 'suspended' => 'موقوف', 'inactive' => 'غير نشط'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-primary">تطبيق</button>
    <a href="{{ route('couriers.index') }}" class="btn-ghost">مسح</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">الرمز</th>
                    <th class="px-4 py-3 text-start font-semibold">الاسم</th>
                    <th class="px-4 py-3 text-start font-semibold">الهاتف</th>
                    <th class="px-4 py-3 text-start font-semibold">النوع</th>
                    <th class="px-4 py-3 text-start font-semibold">بيده الآن</th>
                    <th class="px-4 py-3 text-start font-semibold">نقد</th>
                    <th class="px-4 py-3 text-start font-semibold">عمولته</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($couriers as $courier)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $courier->code }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('couriers.show', $courier) }}"
                               class="font-semibold text-brand-700 hover:underline">{{ $courier->name }}</a>
                            @if ($courier->branch)
                                <div class="text-xs text-slate-500">{{ $courier->branch->name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $courier->phone }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                                {{ ['delivery' => 'توصيل', 'pickup' => 'استلام', 'both' => 'الاثنان'][$courier->type] }}
                            </span>
                        </td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format($courier->open_count) }}</td>
                        <td class="px-4 py-3 font-semibold {{ $courier->hasReachedCashLimit() ? 'text-red-600' : '' }}"
                            dir="ltr">
                            {{ number_format($courier->cash_in_hand) }}
                        </td>
                        <td class="px-4 py-3 text-emerald-700" dir="ltr">
                            {{ number_format($courier->commission_balance) }}
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $tone = match ($courier->status) {
                                    'active'    => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    'suspended' => 'bg-red-50 text-red-700 ring-red-200',
                                    default     => 'bg-slate-100 text-slate-600 ring-slate-200',
                                };
                            @endphp
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $tone }}">
                                {{ ['active' => 'مفعّل', 'suspended' => 'موقوف', 'inactive' => 'غير نشط'][$courier->status] }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-16 text-center">
                            <div class="text-slate-500">لا يوجد مندوبون بعد.</div>
                            <a href="{{ route('couriers.create') }}" class="btn-primary mt-4">أضف أول مندوب</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($couriers->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $couriers->links() }}</div>
    @endif
</div>
@endsection

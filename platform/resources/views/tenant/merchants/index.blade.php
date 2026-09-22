@extends('layouts.app')
@section('title', 'التجّار')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">التجّار</h1>
        <p class="mt-1 text-sm text-slate-500">زبائن شركتك — من يرسل الشحنات.</p>
    </div>
    <div class="flex items-center gap-3">
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-slate-500">مستحقّات لم تُدفَع</div>
            <div class="text-xl font-bold text-brand-700" dir="ltr">{{ number_format($owed) }} د.ع</div>
        </div>
        <a href="{{ route('merchants.create') }}" class="btn-primary">+ تاجر جديد</a>
    </div>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-64 flex-1">
        <label class="field-label" for="q">بحث</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input"
               placeholder="اسم المتجر · صاحبه · الهاتف · الرمز">
    </div>
    <div>
        <label class="field-label" for="status">الحالة</label>
        <select id="status" name="status" class="field-input">
            <option value="">الكل</option>
            @foreach (['active' => 'مفعّل', 'suspended' => 'موقوف', 'pending' => 'بانتظار التفعيل'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn-primary">تطبيق</button>
    <a href="{{ route('merchants.index') }}" class="btn-ghost">مسح</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">الرمز</th>
                    <th class="px-4 py-3 text-start font-semibold">المتجر</th>
                    <th class="px-4 py-3 text-start font-semibold">الهاتف</th>
                    <th class="px-4 py-3 text-start font-semibold">المحافظة</th>
                    <th class="px-4 py-3 text-start font-semibold">شحنات</th>
                    <th class="px-4 py-3 text-start font-semibold">الرصيد</th>
                    <th class="px-4 py-3 text-start font-semibold">التسوية</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($merchants as $merchant)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $merchant->code }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('merchants.show', $merchant) }}"
                               class="font-semibold text-brand-700 hover:underline">
                                {{ $merchant->business_name }}
                            </a>
                            @if ($merchant->owner_name)
                                <div class="text-xs text-slate-500">{{ $merchant->owner_name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $merchant->phone }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $merchant->governorate?->name_ar ?? '—' }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format($merchant->shipments_count) }}</td>
                        <td class="px-4 py-3 font-semibold {{ $merchant->balance > 0 ? 'text-brand-700' : ($merchant->balance < 0 ? 'text-red-600' : 'text-slate-400') }}"
                            dir="ltr">
                            {{ number_format($merchant->balance) }}
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">
                            {{ ['daily' => 'يومي', 'weekly' => 'أسبوعي', 'biweekly' => 'كل أسبوعين',
                                'monthly' => 'شهري', 'on_demand' => 'عند الطلب'][$merchant->settlement_cycle] ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $tone = match ($merchant->status) {
                                    'active'    => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    'suspended' => 'bg-red-50 text-red-700 ring-red-200',
                                    default     => 'bg-amber-50 text-amber-800 ring-amber-200',
                                };
                                $label = ['active' => 'مفعّل', 'suspended' => 'موقوف', 'pending' => 'بانتظار'][$merchant->status] ?? $merchant->status;
                            @endphp
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $tone }}">
                                {{ $label }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-16 text-center">
                            <div class="text-slate-500">لا يوجد تجّار بعد.</div>
                            <a href="{{ route('merchants.create') }}" class="btn-primary mt-4">أضف أول تاجر</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($merchants->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $merchants->links() }}</div>
    @endif
</div>
@endsection

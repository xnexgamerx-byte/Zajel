@extends('layouts.app')
@section('title', 'التجّار')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">التجّار</h1>
        <p class="mt-1 text-sm text-ink-500">زبائن شركتك — من يرسل الشحنات.</p>
    </div>
    <div class="flex items-center gap-3">
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-ink-500">مستحقّات لم تُدفَع</div>
            <div class="text-xl font-bold text-[var(--brand)]"><span class="num">{{ number_format($owed) }}</span> د.ع</div>
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
    <button type="submit" class="btn-primary">تطبيق</button>
    <a href="{{ route('merchants.index') }}" class="btn-ghost">مسح</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th >الرمز</th>
                    <th >المتجر</th>
                    <th >الهاتف</th>
                    <th >المحافظة</th>
                    <th >شحنات</th>
                    <th >الرصيد</th>
                    <th >التسوية</th>
                    <th >الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($merchants as $merchant)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3 font-mono text-ink-500" dir="ltr">{{ $merchant->code }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('merchants.show', $merchant) }}"
                               class="font-semibold text-[var(--brand)] hover:underline">
                                {{ $merchant->business_name }}
                            </a>
                            @if ($merchant->owner_name)
                                <div class="text-xs text-ink-500">{{ $merchant->owner_name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-600" dir="ltr">{{ $merchant->phone }}</td>
                        <td class="px-4 py-3 text-ink-600">{{ $merchant->governorate?->name_ar ?? '—' }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format($merchant->shipments_count) }}</td>
                        <td class="px-4 py-3 font-semibold {{ $merchant->balance > 0 ? 'text-[var(--brand)]' : ($merchant->balance < 0 ? 'text-bad-700' : 'text-ink-400') }}"
                            dir="ltr">
                            {{ number_format($merchant->balance) }}
                        </td>
                        <td class="px-4 py-3 text-xs text-ink-500">
                            {{ ['daily' => 'يومي', 'weekly' => 'أسبوعي', 'biweekly' => 'كل أسبوعين',
                                'monthly' => 'شهري', 'on_demand' => 'عند الطلب'][$merchant->settlement_cycle] ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $tone = match ($merchant->status) {
                                    'active'    => 'bg-ok-50 text-ok-700 ring-ok-200',
                                    'suspended' => 'bg-bad-50 text-bad-700 ring-bad-200',
                                    default     => 'bg-warn-50 text-warn-700 ring-warn-200',
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
                            <div class="text-ink-500">لا يوجد تجّار بعد.</div>
                            <a href="{{ route('merchants.create') }}" class="btn-primary mt-4">أضف أول تاجر</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($merchants->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $merchants->links() }}</div>
    @endif
</div>
@endsection

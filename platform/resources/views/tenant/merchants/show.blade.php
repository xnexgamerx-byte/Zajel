@extends('layouts.app')
@section('title', $merchant->business_name)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">{{ $merchant->business_name }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            <span class="font-mono" dir="ltr">{{ $merchant->code }}</span>
            @if ($merchant->owner_name) · {{ $merchant->owner_name }} @endif
            · <span dir="ltr">{{ $merchant->phone }}</span>
        </p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('shipments.index', ['merchant_id' => $merchant->id]) }}" class="btn-ghost">شحناته</a>
        <a href="{{ route('merchants.edit', $merchant) }}" class="btn-ghost">تعديل</a>
        <a href="{{ route('shipments.create') }}" class="btn-primary">شحنة جديدة</a>
    </div>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">رصيده الحالي</div>
        <div class="mt-1 text-2xl font-bold {{ $merchant->balance >= 0 ? 'text-brand-700' : 'text-red-600' }}" dir="ltr">
            {{ number_format($merchant->balance) }}
            <span class="text-sm font-medium text-slate-500">د.ع</span>
        </div>
        <div class="mt-1 text-xs text-slate-500">
            {{ $merchant->balance > 0 ? 'له عند الشركة' : ($merchant->balance < 0 ? 'عليه للشركة' : 'مسوَّى') }}
        </div>
    </div>

    @foreach ([
        ['مسلَّمة', 'delivered', 'text-emerald-700'],
        ['قيد التنفيذ', null, 'text-sky-700'],
        ['راجعة', 'returned', 'text-slate-600'],
    ] as [$label, $key, $tone])
        @php
            $terminal = array_map(fn ($s) => $s->value, \App\Enums\ShipmentStatus::terminal());
            $value = $key
                ? (int) $byStatus->where('status', $key)->sum('c')
                : (int) $byStatus->whereNotIn('status', $terminal)->sum('c');
        @endphp
        <div class="card p-4">
            <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">كشف الحساب — آخر 30 حركة</h2>

            @if ($transactions->isEmpty())
                <p class="py-8 text-center text-sm text-slate-500">لا حركات بعد.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="py-2 text-start font-semibold">التاريخ</th>
                                <th class="py-2 text-start font-semibold">البيان</th>
                                <th class="py-2 text-start font-semibold">له</th>
                                <th class="py-2 text-start font-semibold">عليه</th>
                                <th class="py-2 text-start font-semibold">الرصيد</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($transactions as $tx)
                                <tr>
                                    <td class="py-2 text-xs text-slate-500" dir="ltr">
                                        {{ $tx->created_at->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="py-2">{{ $tx->description }}</td>
                                    <td class="py-2 font-semibold text-emerald-700" dir="ltr">
                                        {{ $tx->direction === 'credit' ? number_format($tx->amount) : '' }}
                                    </td>
                                    <td class="py-2 font-semibold text-red-600" dir="ltr">
                                        {{ $tx->direction === 'debit' ? number_format($tx->amount) : '' }}
                                    </td>
                                    <td class="py-2 font-semibold" dir="ltr">{{ number_format($tx->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    كل سطر هنا نتيجة حدث حقيقي على شحنة — لا إدخال يدوي، ولا تعديل بعد الكتابة.
                </p>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">آخر شحناته</h2>
            @if ($recent->isEmpty())
                <p class="py-8 text-center text-sm text-slate-500">لا شحنات بعد.</p>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($recent as $shipment)
                        <a href="{{ route('shipments.show', $shipment) }}"
                           class="flex items-center justify-between gap-3 py-2.5 hover:bg-slate-50">
                            <span class="font-mono text-sm font-semibold text-brand-700" dir="ltr">
                                {{ $shipment->number }}
                            </span>
                            <span class="flex-1 truncate text-sm text-slate-600">{{ $shipment->recipient_name }}</span>
                            <span class="text-sm font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</span>
                            <x-status-badge :status="$shipment->status" />
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">البيانات</h2>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">المحافظة</dt>
                    <dd class="text-end font-medium">{{ $merchant->governorate?->name_ar ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">المنطقة</dt>
                    <dd class="text-end font-medium">{{ $merchant->city?->name_ar ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">العنوان</dt>
                    <dd class="text-end font-medium">{{ $merchant->address ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">نقطة دالّة</dt>
                    <dd class="text-end font-medium">{{ $merchant->landmark ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-t border-slate-100 pt-2.5">
                    <dt class="text-slate-500">التسعيرة</dt>
                    <dd class="text-end font-medium">{{ $merchant->priceList?->name ?? 'الافتراضية' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">دورة التسوية</dt>
                    <dd class="text-end font-medium">
                        {{ ['daily' => 'يومي', 'weekly' => 'أسبوعي', 'biweekly' => 'كل أسبوعين',
                            'monthly' => 'شهري', 'on_demand' => 'عند الطلب'][$merchant->settlement_cycle] ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">طريقة الدفع</dt>
                    <dd class="text-end font-medium">
                        {{ ['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                            'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                            'bank_transfer' => 'حوالة مصرفية'][$merchant->payout_method] ?? '—' }}
                    </dd>
                </div>
                @if ($merchant->payout_account)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">الحساب</dt>
                        <dd class="text-end font-medium" dir="ltr">{{ $merchant->payout_account }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        @if ($merchant->notes)
            <section class="card p-5">
                <h2 class="mb-2 text-sm font-bold">ملاحظات</h2>
                <p class="text-sm text-slate-600">{{ $merchant->notes }}</p>
            </section>
        @endif
    </div>
</div>
@endsection

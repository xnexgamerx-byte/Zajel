@extends('layouts.app')
@section('title', $courier->name)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">{{ $courier->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            <span class="font-mono" dir="ltr">{{ $courier->code }}</span>
            · <span dir="ltr">{{ $courier->phone }}</span>
            · {{ ['delivery' => 'مندوب توصيل', 'pickup' => 'مندوب استلام', 'both' => 'توصيل واستلام'][$courier->type] }}
        </p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('shipments.index', ['courier_id' => $courier->id]) }}" class="btn-ghost">شحناته</a>
        <a href="{{ route('couriers.edit', $courier) }}" class="btn-ghost">تعديل</a>
    </div>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">شحنات بيده الآن</div>
        <div class="mt-1 text-2xl font-bold text-sky-700">{{ number_format($open->count()) }}</div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">نقد بيده</div>
        <div class="mt-1 text-2xl font-bold {{ $courier->hasReachedCashLimit() ? 'text-red-600' : 'text-amber-700' }}"
             dir="ltr">{{ number_format($courier->cash_in_hand) }}</div>
        @if ($courier->cash_limit)
            <div class="mt-1 text-xs text-slate-500" dir="ltr">
                السقف {{ number_format($courier->cash_limit) }}
            </div>
        @endif
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">عمولة مستحقّة له</div>
        <div class="mt-1 text-2xl font-bold text-emerald-700" dir="ltr">
            {{ number_format($courier->commission_balance) }}
        </div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">الواجب تسليمه</div>
        <div class="mt-1 text-2xl font-bold text-brand-700" dir="ltr">{{ number_format($courier->netDue()) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">شحنات معه الآن</h2>
            @if ($open->isEmpty())
                <p class="py-8 text-center text-sm text-slate-500">لا شحنات بيده حالياً.</p>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($open as $shipment)
                        <a href="{{ route('shipments.show', $shipment) }}"
                           class="flex flex-wrap items-center gap-3 py-2.5 hover:bg-slate-50">
                            <span class="font-mono text-sm font-semibold text-brand-700" dir="ltr">
                                {{ $shipment->number }}
                            </span>
                            <span class="flex-1 truncate text-sm">{{ $shipment->recipient_name }}</span>
                            <span class="text-xs text-slate-500">{{ $shipment->governorate->name_ar }}</span>
                            <span class="text-sm font-semibold" dir="ltr">
                                {{ number_format($shipment->cod_amount) }} د.ع
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">حركات حسابه — آخر 30</h2>
            @if ($transactions->isEmpty())
                <p class="py-8 text-center text-sm text-slate-500">لا حركات بعد.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="py-2 text-start font-semibold">التاريخ</th>
                                <th class="py-2 text-start font-semibold">البيان</th>
                                <th class="py-2 text-start font-semibold">النوع</th>
                                <th class="py-2 text-start font-semibold">المبلغ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($transactions as $tx)
                                <tr>
                                    <td class="py-2 text-xs text-slate-500" dir="ltr">
                                        {{ $tx->created_at->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="py-2">{{ $tx->description }}</td>
                                    <td class="py-2 text-xs text-slate-500">
                                        {{ ['cod_collected' => 'تحصيل نقد', 'commission' => 'عمولة',
                                            'commission_paid' => 'دفع عمولة', 'cash_handover' => 'تسليم نقد',
                                            ][$tx->category] ?? $tx->category }}
                                    </td>
                                    <td class="py-2 font-semibold {{ $tx->direction === 'credit' ? 'text-emerald-700' : 'text-slate-900' }}"
                                        dir="ltr">{{ number_format($tx->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">مناطق التغطية</h2>
            @if ($courier->zones->isEmpty())
                <p class="text-sm text-slate-500">لم تُحدَّد مناطق.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($courier->zones as $zone)
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                            {{ $zone->governorate->name_ar }}{{ $zone->city ? ' — ' . $zone->city->name_ar : '' }}
                        </span>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">العمولات</h2>
            <dl class="space-y-2.5 text-sm">
                @foreach ([
                    ['عمولة التوصيل', $courier->commission_per_delivery],
                    ['عمولة الاستلام', $courier->commission_per_pickup],
                    ['عمولة الإرجاع', $courier->commission_per_return],
                ] as [$label, $value])
                    <div class="flex justify-between">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="font-medium" dir="ltr">{{ $value === null ? '—' : number_format($value) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between border-t border-slate-100 pt-2.5">
                    <dt class="text-slate-500">حساب الدخول</dt>
                    <dd class="font-medium">{{ $courier->user ? 'موجود' : 'لا يوجد' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-500">المركبة</dt>
                    <dd class="font-medium">
                        {{ ['motorcycle' => 'دراجة نارية', 'car' => 'سيارة', 'van' => 'فان',
                            'truck' => 'شاحنة', 'on_foot' => 'على الأقدام'][$courier->vehicle_type] ?? '—' }}
                        {{ $courier->vehicle_number ? '· ' . $courier->vehicle_number : '' }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</div>
@endsection

@extends('layouts.portal')
@section('title', 'الرئيسية')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">أهلاً {{ $merchant->owner_name ?: $merchant->business_name }}</h1>
        <p class="mt-1 text-sm text-slate-500">وضع شحناتك وحسابك مع {{ $company->name }}.</p>
    </div>
    <a href="{{ route('portal.shipments.create') }}" class="btn-primary">+ شحنة جديدة</a>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">
            {{ $merchant->balance >= 0 ? 'لك عند الشركة' : 'عليك للشركة' }}
        </div>
        <div class="mt-1 text-2xl font-bold {{ $merchant->balance >= 0 ? 'text-brand-700' : 'text-red-600' }}"
             dir="ltr">
            {{ number_format(abs($merchant->balance)) }}
            <span class="text-sm font-medium text-slate-500">د.ع</span>
        </div>
        <a href="{{ route('portal.statement') }}" class="mt-1 inline-block text-xs text-brand-700 hover:underline">
            كشف الحساب
        </a>
    </div>

    @foreach ([
        ['قيد التوصيل', $counts['open'], 'text-sky-700'],
        ['مسلَّمة', $counts['delivered'], 'text-emerald-700'],
        ['راجعة', $counts['returned'], 'text-slate-600'],
    ] as [$label, $value, $tone])
        <div class="card p-4">
            <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        @if ($attention->isNotEmpty())
            <section class="card border-r-4 border-amber-400 p-5">
                <h2 class="mb-1 text-sm font-bold">تحتاج انتباهك</h2>
                <p class="mb-4 text-xs text-slate-500">
                    شحنات تعثّرت. أحياناً مكالمة منك للزبون تحلّ ما لا تحلّه محاولة ثانية.
                </p>

                <div class="divide-y divide-slate-100">
                    @foreach ($attention as $shipment)
                        <a href="{{ route('portal.shipments.show', $shipment) }}"
                           class="flex flex-wrap items-center gap-3 py-2.5 hover:bg-slate-50">
                            <span class="font-mono text-sm font-semibold text-brand-700" dir="ltr">
                                {{ $shipment->number }}
                            </span>
                            <span class="flex-1 truncate text-sm">{{ $shipment->recipient_name }}</span>
                            @if ($shipment->lastFailureReason)
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800">
                                    {{ $shipment->lastFailureReason->name_ar }}
                                </span>
                            @endif
                            <x-status-badge :status="$shipment->status" />
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="card p-5">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-bold">آخر شحناتك</h2>
                <a href="{{ route('portal.shipments.index') }}"
                   class="text-sm font-semibold text-brand-700 hover:underline">الكل</a>
            </div>

            @if ($recent->isEmpty())
                <div class="py-10 text-center">
                    <p class="text-slate-500">لم ترسل شحنة بعد.</p>
                    <a href="{{ route('portal.shipments.create') }}" class="btn-primary mt-4">أنشئ أول شحنة</a>
                </div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($recent as $shipment)
                        <a href="{{ route('portal.shipments.show', $shipment) }}"
                           class="flex flex-wrap items-center gap-3 py-2.5 hover:bg-slate-50">
                            <span class="font-mono text-sm font-semibold text-brand-700" dir="ltr">
                                {{ $shipment->number }}
                            </span>
                            <span class="min-w-32 flex-1 truncate text-sm">{{ $shipment->recipient_name }}</span>
                            <span class="text-xs text-slate-500">{{ $shipment->governorate->name_ar }}</span>
                            <span class="text-sm font-semibold" dir="ltr">
                                {{ number_format($shipment->cod_amount) }}
                            </span>
                            <x-status-badge :status="$shipment->status" />
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">طلب استلام</h2>

            @if ($pickups->isNotEmpty())
                @foreach ($pickups as $pickup)
                    <div class="mb-3 rounded-lg bg-sky-50 px-3 py-2.5 text-sm ring-1 ring-sky-200">
                        <div class="font-semibold">
                            طلب {{ $pickup->number }} — {{ $pickup->expected_count }} طرد
                        </div>
                        <div class="mt-0.5 text-xs text-sky-800">
                            {{ ['pending' => 'بانتظار إسناد مندوب', 'assigned' => 'أُسند لمندوب',
                                'in_progress' => 'المندوب في الطريق'][$pickup->status] ?? $pickup->status }}
                            @if ($pickup->courier) — {{ $pickup->courier->name }} @endif
                        </div>
                    </div>
                @endforeach
            @else
                <form method="POST" action="{{ route('portal.pickups.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="field-label" for="expected_count">كم طرداً جاهز؟</label>
                        <input id="expected_count" name="expected_count" type="number" min="1"
                               class="field-input text-left" dir="ltr" required value="{{ old('expected_count') }}">
                    </div>
                    <div>
                        <label class="field-label" for="scheduled_at">الموعد المفضّل</label>
                        <input id="scheduled_at" name="scheduled_at" type="date" class="field-input"
                               value="{{ old('scheduled_at') }}">
                    </div>
                    <button type="submit" class="btn-primary w-full">اطلب مندوب استلام</button>
                </form>
            @endif

            <a href="{{ route('portal.pickups.index') }}"
               class="mt-3 inline-block text-xs text-brand-700 hover:underline">كل الطلبات</a>
        </section>

        <section class="card p-5">
            <h2 class="mb-3 text-sm font-bold">عنوان الاستلام</h2>
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-slate-500">العنوان</dt>
                    <dd class="font-medium">{{ $merchant->address ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">نقطة دالّة</dt>
                    <dd class="font-medium">{{ $merchant->landmark ?: '—' }}</dd>
                </div>
                <div class="border-t border-slate-100 pt-2">
                    <dt class="text-slate-500">دورة التسوية</dt>
                    <dd class="font-medium">
                        {{ ['daily' => 'يومي', 'weekly' => 'أسبوعي', 'biweekly' => 'كل أسبوعين',
                            'monthly' => 'شهري', 'on_demand' => 'عند الطلب'][$merchant->settlement_cycle] ?? '—' }}
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">
                لتعديل هذه البيانات راجع {{ $company->name }}.
            </p>
        </section>
    </div>
</div>
@endsection

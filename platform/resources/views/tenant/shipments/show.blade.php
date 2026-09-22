@extends('layouts.app')
@section('title', 'شحنة ' . $shipment->number)

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="font-mono text-2xl font-bold" dir="ltr">{{ $shipment->number }}</h1>
            <x-status-badge :status="$shipment->status" />
        </div>
        <p class="mt-1 text-sm text-slate-500">
            أُنشئت {{ $shipment->created_at->format('Y-m-d H:i') }}
            @if ($shipment->merchant_reference)
                · رقم التاجر: <span dir="ltr">{{ $shipment->merchant_reference }}</span>
            @endif
        </p>
    </div>
    <a href="{{ route('shipments.index') }}" class="btn-ghost">رجوع للقائمة</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">المستلم</h2>
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">الاسم</dt>
                     <dd class="font-medium">{{ $shipment->recipient_name }}</dd></div>
                <div><dt class="text-slate-500">الهاتف</dt>
                     <dd class="font-medium" dir="ltr">{{ $shipment->recipient_phone }}</dd></div>
                @if ($shipment->recipient_phone_alt)
                    <div><dt class="text-slate-500">هاتف بديل</dt>
                         <dd class="font-medium" dir="ltr">{{ $shipment->recipient_phone_alt }}</dd></div>
                @endif
                <div><dt class="text-slate-500">المحافظة / المنطقة</dt>
                     <dd class="font-medium">{{ $shipment->governorate->name_ar }}
                         @if ($shipment->city) — {{ $shipment->city->name_ar }} @endif
                     </dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">العنوان</dt>
                     <dd class="font-medium">{{ $shipment->address }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">أقرب نقطة دالّة</dt>
                     <dd class="font-medium text-brand-700">{{ $shipment->landmark }}</dd></div>
            </dl>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الطرد</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <div><dt class="text-slate-500">التاجر</dt>
                     <dd class="font-medium">{{ $shipment->merchant->business_name }}</dd></div>
                <div><dt class="text-slate-500">القطع</dt>
                     <dd class="font-medium" dir="ltr">{{ $shipment->pieces_count }}</dd></div>
                <div><dt class="text-slate-500">الوزن</dt>
                     <dd class="font-medium" dir="ltr">{{ number_format($shipment->weight_grams / 1000, 2) }} كغم</dd></div>
                <div><dt class="text-slate-500">المحاولات</dt>
                     <dd class="font-medium" dir="ltr">{{ $shipment->attempts_count }}</dd></div>
                @if ($shipment->description)
                    <div class="col-span-2 sm:col-span-4"><dt class="text-slate-500">المحتوى</dt>
                         <dd class="font-medium">{{ $shipment->description }}</dd></div>
                @endif
                @if ($shipment->notes)
                    <div class="col-span-2 sm:col-span-4"><dt class="text-slate-500">ملاحظات</dt>
                         <dd class="font-medium">{{ $shipment->notes }}</dd></div>
                @endif
                @if ($shipment->lastFailureReason)
                    <div class="col-span-2 sm:col-span-4">
                        <dt class="text-slate-500">آخر سبب فشل</dt>
                        <dd class="font-medium text-amber-700">
                            {{ $shipment->lastFailureReason->name_ar }}
                            <span class="text-xs text-slate-500">
                                (مسؤولية: {{ $shipment->lastFailureReason->categoryLabel() }})
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- خطّ الزمن: مصدر الحقيقة، لا ملخّص --}}
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">سجلّ الشحنة</h2>

            <ol class="relative space-y-5 border-s-2 border-slate-100 ps-5">
                @foreach ($shipment->events as $event)
                    @php $status = \App\Enums\ShipmentStatus::tryFrom($event->to_status); @endphp
                    <li class="relative">
                        <span class="absolute -start-[1.6rem] top-1 grid h-3 w-3 place-items-center rounded-full
                                     {{ $loop->last ? 'bg-brand-600 ring-4 ring-brand-100' : 'bg-slate-300' }}"></span>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold">
                                {{ $status?->label() ?? $event->to_status }}
                            </span>
                            <span class="text-xs text-slate-400" dir="ltr">
                                {{ $event->created_at->format('Y-m-d H:i') }}
                            </span>
                        </div>

                        <div class="mt-0.5 text-xs text-slate-500">
                            {{ $event->actor_name ?? 'النظام' }}
                            @if ($event->courier) · المندوب: {{ $event->courier->name }} @endif
                            @if ($event->failureReason) · {{ $event->failureReason->name_ar }} @endif
                        </div>

                        @if ($event->note)
                            <p class="mt-1 rounded bg-slate-50 px-2 py-1 text-xs text-slate-700">{{ $event->note }}</p>
                        @endif

                        @if ($event->amount !== null)
                            <p class="mt-1 text-xs font-semibold text-emerald-700" dir="ltr">
                                {{ number_format($event->amount) }} د.ع
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الحساب</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-600">المطلوب من الزبون</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-600">المحصَّل فعلاً</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($shipment->collected_amount) }}</dd>
                </div>
                <div class="flex justify-between border-t border-slate-100 pt-2">
                    <dt class="text-slate-600">أجرة التوصيل</dt>
                    <dd dir="ltr">{{ number_format($shipment->delivery_fee) }}</dd>
                </div>
                @if ($shipment->extra_fee)
                    <div class="flex justify-between">
                        <dt class="text-slate-600">رسوم إضافية</dt>
                        <dd dir="ltr">{{ number_format($shipment->extra_fee) }}</dd>
                    </div>
                @endif
                @if ($shipment->cod_fee)
                    <div class="flex justify-between">
                        <dt class="text-slate-600">عمولة التحصيل</dt>
                        <dd dir="ltr">{{ number_format($shipment->cod_fee) }}</dd>
                    </div>
                @endif
                @if ($shipment->discount)
                    <div class="flex justify-between text-emerald-700">
                        <dt>خصم</dt>
                        <dd dir="ltr">−{{ number_format($shipment->discount) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-slate-200 pt-2">
                    <dt class="text-slate-600">مجموع الأجور</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($shipment->total_fees) }}</dd>
                </div>
                <div class="flex justify-between border-t-2 border-slate-300 pt-2">
                    <dt class="font-bold">مستحقّ التاجر</dt>
                    <dd class="text-base font-bold text-brand-700" dir="ltr">
                        {{ number_format($shipment->merchant_due) }} د.ع
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">
                الأجرة على: {{ $shipment->fees_paid_by === 'customer' ? 'الزبون' : 'التاجر' }}
            </p>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">المسؤولية والموقع</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-600">مندوب التوصيل</dt>
                    <dd class="font-medium">{{ $shipment->deliveryCourier?->name ?? 'لم يُسنَد' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-600">مندوب الاستلام</dt>
                    <dd class="font-medium">{{ $shipment->pickupCourier?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-600">المركز الحالي</dt>
                    <dd class="font-medium">{{ $shipment->hub?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-600">الفرع</dt>
                    <dd class="font-medium">{{ $shipment->branch?->name ?? '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</div>
@endsection

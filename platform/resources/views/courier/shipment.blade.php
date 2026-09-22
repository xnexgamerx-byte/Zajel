@extends('layouts.courier')
@section('title', 'شحنة ' . $shipment->number)

@section('content')
<div class="mb-3 rounded-xl bg-white p-4 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="text-lg font-bold">{{ $shipment->recipient_name }}</div>
            <div class="font-mono text-xs text-slate-400" dir="ltr">{{ $shipment->number }}</div>
        </div>
        <x-status-badge :status="$shipment->status" />
    </div>

    {{-- الاتصال أولاً: هذا أول ما يفعله المندوب عند الوصول.
         الزرّ الثاني يظهر فقط إن كان يقود إلى شيء مختلف فعلاً. --}}
    @php
        $secondary = match (true) {
            (bool) ($shipment->lat && $shipment->lng) => [
                'الخريطة',
                'https://www.google.com/maps/search/?api=1&query='.$shipment->lat.','.$shipment->lng,
                'bg-slate-800 text-white active:bg-slate-900',
                true,
            ],
            (bool) $shipment->recipient_phone_alt => [
                'الرقم البديل',
                'tel:'.$shipment->recipient_phone_alt,
                'bg-slate-200 text-slate-700 active:bg-slate-300',
                false,
            ],
            default => null,
        };
    @endphp

    <div class="mt-3 {{ $secondary ? 'grid grid-cols-2 gap-2' : '' }}">
        <a href="tel:{{ $shipment->recipient_phone }}"
           class="block rounded-xl bg-emerald-600 px-4 py-3 text-center text-base font-bold text-white active:bg-emerald-700">
            اتصل بالزبون
        </a>

        @if ($secondary)
            @php([$label, $href, $classes, $external] = $secondary)
            <a href="{{ $href }}"
               @if ($external) target="_blank" rel="noopener" @endif
               class="block rounded-xl px-4 py-3 text-center text-base font-bold {{ $classes }}">
                {{ $label }}
            </a>
        @endif
    </div>
</div>

<div class="mb-3 rounded-xl bg-white p-4 shadow-sm">
    <div class="text-xs text-slate-500">المطلوب من الزبون</div>
    <div class="text-3xl font-bold text-amber-700" dir="ltr">
        {{ number_format($shipment->cod_amount) }} <span class="text-base text-slate-500">د.ع</span>
    </div>

    <dl class="mt-3 space-y-2 border-t border-slate-100 pt-3 text-sm">
        <div>
            <dt class="text-xs text-slate-500">العنوان</dt>
            <dd class="font-medium">{{ $shipment->address }}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500">نقطة دالّة</dt>
            <dd class="font-bold text-brand-700">{{ $shipment->landmark }}</dd>
        </div>
        @if ($shipment->notes)
            <div class="rounded-lg bg-amber-50 px-3 py-2">
                <dt class="text-xs text-amber-800">ملاحظة التاجر</dt>
                <dd class="font-medium text-amber-900">{{ $shipment->notes }}</dd>
            </div>
        @endif
        <div class="flex justify-between border-t border-slate-100 pt-2">
            <dt class="text-slate-500">التاجر</dt>
            <dd class="font-medium">{{ $shipment->merchant->business_name }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">القطع</dt>
            <dd class="font-medium" dir="ltr">{{ $shipment->pieces_count }}</dd>
        </div>
        @if ($shipment->lastFailureReason)
            <div class="flex justify-between">
                <dt class="text-slate-500">آخر محاولة</dt>
                <dd class="font-medium text-amber-700">{{ $shipment->lastFailureReason->name_ar }}</dd>
            </div>
        @endif
    </dl>
</div>

@if ($canAct)
    <form method="POST" action="{{ route('courier.shipments.act', $shipment) }}"
          class="space-y-3" data-courier-form>
        @csrf
        <input type="hidden" name="lat" data-geo-lat>
        <input type="hidden" name="lng" data-geo-lng>

        {{-- تسليم: الفعل الأكثر تكراراً، فهو الأكبر والأول --}}
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <label class="field-label" for="collected_amount">المبلغ المستلم</label>
            <div class="relative">
                <input id="collected_amount" name="collected_amount" type="number" min="0" step="250"
                       class="field-input pe-12 text-left text-lg" dir="ltr"
                       value="{{ old('collected_amount', $shipment->cod_amount) }}">
                <span class="absolute inset-y-0 end-3 flex items-center text-xs text-slate-400">د.ع</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">
                إن استلمت أقل، عدّل الرقم واختر "تسليم جزئي". الرقم لا يُعدَّل بعد الحفظ.
            </p>

            <button type="submit" name="action" value="delivered"
                    class="mt-3 w-full rounded-xl bg-emerald-600 px-4 py-4 text-lg font-bold text-white active:bg-emerald-700">
                تم التسليم
            </button>

            <button type="submit" name="action" value="partially_delivered"
                    class="mt-2 w-full rounded-xl bg-white px-4 py-3 text-base font-semibold text-slate-700 ring-1 ring-slate-300 active:bg-slate-50">
                تسليم جزئي
            </button>
        </div>

        {{-- لم يُسلَّم --}}
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <label class="field-label" for="failure_reason_id">إن لم يُسلَّم — السبب</label>
            <select id="failure_reason_id" name="failure_reason_id" class="field-input text-base">
                <option value="">اختر السبب</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason->id }}" @selected((int) old('failure_reason_id') === $reason->id)>
                        {{ $reason->name_ar }}
                    </option>
                @endforeach
            </select>

            <label class="field-label mt-3" for="note">ملاحظة</label>
            <textarea id="note" name="note" rows="2" class="field-input text-base"
                      placeholder="مثال: اتصلت ثلاث مرات ولم يرد">{{ old('note') }}</textarea>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="submit" name="action" value="failed_attempt"
                        class="rounded-xl bg-red-600 px-4 py-3.5 text-base font-bold text-white active:bg-red-700">
                    محاولة فاشلة
                </button>
                <button type="submit" name="action" value="postponed"
                        class="rounded-xl bg-amber-500 px-4 py-3.5 text-base font-bold text-white active:bg-amber-600">
                    تأجيل
                </button>
            </div>
        </div>
    </form>
@else
    <div class="rounded-xl bg-white p-6 text-center shadow-sm">
        <p class="font-semibold text-slate-700">هذه الشحنة لم تعد بيدك.</p>
        <p class="mt-1 text-sm text-slate-500">حالتها الآن: {{ $shipment->status->label() }}</p>
    </div>
@endif

<a href="{{ route('courier.tasks') }}"
   class="mt-4 block rounded-xl bg-white px-4 py-3 text-center text-sm font-semibold text-slate-600 shadow-sm">
    رجوع لمهامي
</a>
@endsection

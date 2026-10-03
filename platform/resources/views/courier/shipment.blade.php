@extends('layouts.courier')
@section('title', 'شحنة ' . $shipment->number)

@section('content')
<div class="mb-3 rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="text-lg font-bold">{{ $shipment->recipient_name }}</div>
            <div class="font-mono text-xs text-ink-400" dir="ltr">{{ $shipment->number }}</div>
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
                'bg-ink-800 text-white active:bg-ink-900',
                true,
            ],
            (bool) $shipment->recipient_phone_alt => [
                'الرقم البديل',
                'tel:'.$shipment->recipient_phone_alt,
                'bg-ink-200 text-ink-700 active:bg-ink-300',
                false,
            ],
            default => null,
        };
    @endphp

    <div class="mt-3 {{ $secondary ? 'grid grid-cols-2 gap-2' : '' }}">
        <a href="tel:{{ $shipment->recipient_phone }}"
           class="block rounded-xl bg-ok-700 px-4 py-3 text-center text-base font-bold text-white active:brightness-110">
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

<div class="mb-3 rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
    <div class="text-xs text-ink-500">المطلوب من الزبون</div>
    <div class="text-3xl font-bold text-warn-700">
                <span class="num">{{ number_format($shipment->cod_amount) }}</span>
                <span class="text-base text-ink-500">د.ع</span>
    </div>

    <dl class="mt-3 space-y-2 border-t border-ink-100 pt-3 text-sm">
        <div>
            <dt class="text-xs text-ink-500">المنطقة</dt>
            <dd class="font-medium">{{ $shipment->governorate?->name_ar }}@if ($shipment->city) — {{ $shipment->city->name_ar }}@endif</dd>
        </div>
        @if (filled($shipment->address))
            <div>
                <dt class="text-xs text-ink-500">العنوان</dt>
                <dd class="font-medium">{{ $shipment->address }}</dd>
            </div>
        @endif
        @if (filled($shipment->landmark))
            <div>
                <dt class="text-xs text-ink-500">نقطة دالّة</dt>
                <dd class="font-bold text-[var(--brand)]">{{ $shipment->landmark }}</dd>
            </div>
        @endif
        @if ($shipment->type === 'exchange')
            {{-- يُسلَّم الجديد ويُستلَم القديم: يعرفه المندوب قبل أن يطرق الباب --}}
            <div class="rounded-lg bg-warn-50 px-3 py-2">
                <dt class="text-xs text-warn-700">نوع الطلب</dt>
                <dd class="font-bold text-warn-700">استبدال — سلّم الجديد واستلم القديم من الزبون</dd>
            </div>
        @endif
        @if ($shipment->notes)
            <div class="rounded-lg bg-warn-50 px-3 py-2">
                <dt class="text-xs text-warn-700">ملاحظة التاجر</dt>
                <dd class="font-medium text-warn-700">{{ $shipment->notes }}</dd>
            </div>
        @endif
        <div class="flex justify-between border-t border-ink-100 pt-2">
            <dt class="text-ink-500">التاجر</dt>
            <dd class="font-medium">{{ $shipment->merchant->business_name }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-ink-500">القطع</dt>
            <dd class="font-medium" dir="ltr">{{ $shipment->pieces_count }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-ink-500">الحجم</dt>
            <dd @class(['font-medium', 'font-bold text-warn-700' => $shipment->size === 'large'])>{{ \App\Models\Shipment::SIZES[$shipment->size] ?? $shipment->size }}</dd>
        </div>
        @if ($shipment->description)
            <div class="flex justify-between gap-3">
                <dt class="shrink-0 text-ink-500">نوع البضاعة</dt>
                <dd class="text-end font-medium">{{ $shipment->description }}</dd>
            </div>
        @endif
        @if ($shipment->lastFailureReason)
            <div class="flex justify-between">
                <dt class="text-ink-500">آخر محاولة</dt>
                <dd class="font-medium text-warn-700">{{ $shipment->lastFailureReason->name_ar }}</dd>
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
        <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
            <label class="field-label" for="collected_amount">المبلغ المستلم</label>
            <div class="relative">
                <input id="collected_amount" name="collected_amount" type="number" min="0" step="1"
                       class="field-input ps-12 text-left text-lg" dir="ltr"
                       value="{{ old('collected_amount', $shipment->cod_amount) }}">
                <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
            </div>
            <p class="mt-1 text-xs text-ink-500">
                إن استلمت أقل، عدّل الرقم واختر «واصل جزئي». الرقم لا يُعدَّل بعد الحفظ.
            </p>

            {{-- تاجرٌ يطلب كود التسليم: الزبون يعطيه للمندوب عند الباب، ولا تسليم بدونه --}}
            @if ($shipment->delivery_code)
                <label class="field-label mt-3" for="delivery_code">كود التسليم من الزبون</label>
                <input id="delivery_code" name="delivery_code" class="field-input text-center text-lg tracking-[0.4em]" dir="ltr"
                       inputmode="numeric" autocomplete="off" maxlength="6" placeholder="••••">
                @error('delivery_code') <p class="field-error">{{ $message }}</p> @enderror
            @endif

            <button type="submit" name="action" value="delivered"
                    class="mt-3 w-full rounded-xl bg-ok-700 px-4 py-4 text-lg font-bold text-white active:brightness-110">
                واصل
            </button>

            <button type="submit" name="action" value="partially_delivered"
                    class="mt-2 w-full rounded-xl bg-white px-4 py-3 text-base font-semibold text-ink-700 ring-1 ring-ink-300 active:bg-ink-50">
                واصل جزئي
            </button>
        </div>

        {{-- لم يُسلَّم --}}
        <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
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
                        class="rounded-xl bg-bad-700 px-4 py-3.5 text-base font-bold text-white active:brightness-110">
                    لم يُسلَّم
                </button>
                <button type="submit" name="action" value="postponed"
                        class="rounded-xl bg-warn-500 px-4 py-3.5 text-base font-bold text-white active:brightness-110">
                    مؤجل
                </button>
            </div>
        </div>
    </form>
@else
    <div class="rounded-xl border border-ink-200 bg-white p-6 text-center shadow-xs">
        <p class="font-semibold text-ink-700">هذه الشحنة لم تعد بيدك.</p>
        <p class="mt-1 text-sm text-ink-500">حالتها الآن: {{ $shipment->status->label() }}</p>
    </div>
@endif

<a href="{{ route('courier.tasks') }}"
   class="mt-4 block rounded-xl border border-ink-200 bg-white px-4 py-3 text-center text-sm font-semibold text-ink-600 shadow-xs">
    رجوع لمهامي
</a>
@endsection

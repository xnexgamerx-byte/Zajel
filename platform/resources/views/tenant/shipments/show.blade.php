@extends('layouts.app')
@section('title', 'شحنة ' . $shipment->number)

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="font-mono text-2xl font-bold" dir="ltr">{{ $shipment->number }}</h1>
            <x-status-badge :status="$shipment->status" />
        </div>
        <p class="mt-1 text-sm text-ink-500">
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
                <div><dt class="text-ink-500">الاسم</dt>
                     <dd class="font-medium">{{ $shipment->recipient_name }}</dd></div>
                <div><dt class="text-ink-500">الهاتف</dt>
                     <dd class="font-medium"><x-phone :number="$shipment->recipient_phone"
                                                     :name="$shipment->recipient_name" /></dd></div>
                @if ($shipment->recipient_phone_alt)
                    <div><dt class="text-ink-500">هاتف بديل</dt>
                         <dd class="font-medium"><x-phone :number="$shipment->recipient_phone_alt"
                                                         :name="$shipment->recipient_name" /></dd></div>
                @endif
                <div><dt class="text-ink-500">المحافظة / المنطقة</dt>
                     <dd class="font-medium">{{ $shipment->governorate->name_ar }}
                         @if ($shipment->city) — {{ $shipment->city->name_ar }} @endif
                     </dd></div>
                <div class="sm:col-span-2"><dt class="text-ink-500">العنوان</dt>
                     <dd class="font-medium">{{ $shipment->address }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-ink-500">أقرب نقطة دالّة</dt>
                     <dd class="font-medium text-[var(--brand)]">{{ $shipment->landmark }}</dd></div>
            </dl>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الطرد</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <div><dt class="text-ink-500">التاجر</dt>
                     <dd class="font-medium">{{ $shipment->merchant->business_name }}</dd></div>
                <div><dt class="text-ink-500">القطع</dt>
                     <dd class="font-medium" dir="ltr">{{ $shipment->pieces_count }}</dd></div>
                <div><dt class="text-ink-500">الوزن</dt>
                     <dd class="font-medium" dir="ltr">{{ number_format($shipment->weight_grams / 1000, 2) }} كغم</dd></div>
                <div><dt class="text-ink-500">المحاولات</dt>
                     <dd class="font-medium" dir="ltr">{{ $shipment->attempts_count }}</dd></div>
                @if ($shipment->description)
                    <div class="col-span-2 sm:col-span-4"><dt class="text-ink-500">المحتوى</dt>
                         <dd class="font-medium">{{ $shipment->description }}</dd></div>
                @endif
                @if ($shipment->notes)
                    <div class="col-span-2 sm:col-span-4"><dt class="text-ink-500">ملاحظات</dt>
                         <dd class="font-medium">{{ $shipment->notes }}</dd></div>
                @endif
                @if ($shipment->lastFailureReason)
                    <div class="col-span-2 sm:col-span-4">
                        <dt class="text-ink-500">آخر سبب فشل</dt>
                        <dd class="font-medium text-warn-700">
                            {{ $shipment->lastFailureReason->name_ar }}
                            <span class="text-xs text-ink-500">
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

            <ol class="relative space-y-5 border-s-2 border-ink-100 ps-5">
                @foreach ($shipment->events as $event)
                    @php $status = \App\Enums\ShipmentStatus::tryFrom($event->to_status); @endphp
                    <li class="relative">
                        <span class="absolute -start-[1.6rem] top-1 grid h-3 w-3 place-items-center rounded-full
                                     {{ $loop->last ? 'bg-[var(--brand)] ring-4 ring-[var(--brand-line)]' : 'bg-ink-300' }}"></span>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold">
                                {{ $status?->label() ?? $event->to_status }}
                            </span>
                            <span class="text-xs text-ink-400" dir="ltr">
                                {{ $event->created_at->format('Y-m-d H:i') }}
                            </span>
                        </div>

                        <div class="mt-0.5 text-xs text-ink-500">
                            {{ $event->actor_name ?? 'النظام' }}
                            @if ($event->courier) · المندوب: {{ $event->courier->name }} @endif
                            @if ($event->failureReason) · {{ $event->failureReason->name_ar }} @endif
                        </div>

                        @if ($event->note)
                            <p class="mt-1 rounded bg-ink-50 px-2 py-1 text-xs text-ink-700">{{ $event->note }}</p>
                        @endif

                        @if ($event->amount !== null)
                            <p class="mt-1 text-xs font-semibold text-ok-700" dir="ltr">
                                {{ number_format($event->amount) }} د.ع
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    <div class="space-y-5">
        {{-- لوحة الإجراء: الحالات المعروضة هي المسموحة فعلاً، لا كل الحالات --}}
        @if (auth()->user()->isStaff())
        <section class="card p-5" id="action-panel">
            <h2 class="mb-4 text-sm font-bold">الإجراء التالي</h2>

            @if ($shipment->is_forced)
                <p class="mb-4 rounded-lg bg-warn-50 px-3 py-2 text-xs text-warn-700">
                    غُيّرت هذه الشحنة إجبارياً:
                    <span class="font-semibold">{{ $shipment->forced_reason }}</span>
                    — {{ $shipment->forcedBy?->name ?? 'النظام' }}
                </p>
            @endif

            @if (empty($nextStatuses))
                <p class="rounded-lg bg-ink-50 px-3 py-4 text-center text-sm text-ink-500">
                    الشحنة في حالة نهائية — «{{ $shipment->status->label() }}». لا إجراء بعدها.
                </p>

                @if (auth()->user()->isStaff() && $shipment->status->isOpen())
                    <x-forced-status :shipment="$shipment" />
                @endif
            @else
                <form method="POST" action="{{ route('shipments.status', $shipment) }}" class="space-y-4"
                      data-status-form>
                    @csrf

                    <div>
                        <label class="field-label" for="status">الحالة الجديدة</label>
                        <select id="status" name="status" class="field-input" required>
                            <option value="">اختر</option>
                            @foreach ($nextStatuses as $next)
                                <option value="{{ $next->value }}" @selected(old('status') === $next->value)>
                                    {{ $next->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('status') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div data-when="out_for_delivery">
                        <label class="field-label" for="courier_id">المندوب</label>
                        <select id="courier_id" name="courier_id" class="field-input">
                            <option value="">
                                {{ $shipment->deliveryCourier?->name ?? 'اختر المندوب' }}
                            </option>
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}"
                                        @selected((int) old('courier_id') === $courier->id)>
                                    {{ $courier->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('courier_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div data-when="at_hub in_transit">
                        <label class="field-label" for="hub_id">المركز</label>
                        <select id="hub_id" name="hub_id" class="field-input">
                            <option value="">بلا تغيير</option>
                            @foreach ($hubs as $hub)
                                <option value="{{ $hub->id }}" @selected((int) old('hub_id') === $hub->id)>
                                    {{ $hub->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('hub_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div data-when="failed_attempt">
                        <label class="field-label" for="failure_reason_id">سبب الفشل</label>
                        <select id="failure_reason_id" name="failure_reason_id" class="field-input">
                            <option value="">اختر السبب</option>
                            @foreach ($reasons as $reason)
                                <option value="{{ $reason->id }}"
                                        data-requires-note="{{ $reason->requires_note ? '1' : '0' }}"
                                        @selected((int) old('failure_reason_id') === $reason->id)>
                                    {{ $reason->name_ar }} — {{ $reason->categoryLabel() }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-ink-500">
                            التصنيف هو ما يسمح لاحقاً بإخبار التاجر لماذا ترجع شحناته.
                        </p>
                        @error('failure_reason_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div data-when="delivered partially_delivered">
                        <label class="field-label" for="collected_amount">المبلغ المحصَّل</label>
                        <div class="relative">
                            <input id="collected_amount" name="collected_amount" type="number" min="0" step="1"
                                   value="{{ old('collected_amount', $shipment->cod_amount) }}"
                                   class="field-input pe-12 text-left" dir="ltr">
                            <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                        </div>
                        <p class="mt-1 text-xs text-warn-700">
                            هذا الرقم يدخل حساب التاجر ولا يُعدَّل بعد الحفظ.
                        </p>
                        @error('collected_amount') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="field-label" for="note">ملاحظة</label>
                        <textarea id="note" name="note" rows="2" class="field-input"
                                  placeholder="تُحفَظ في سجلّ الشحنة">{{ old('note') }}</textarea>
                        @error('note') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="btn-primary w-full">تنفيذ</button>
                </form>

                @if (auth()->user()->isStaff())
                    <x-forced-status :shipment="$shipment" />
                @endif
            @endif
        </section>
        @endif

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الحساب</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-600">المطلوب من الزبون</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-ink-600">
                        المحصَّل فعلاً
                        @if ($shipment->amount_confirmed)
                            <span class="chip chip-ok ms-1 align-middle">مؤكَّد</span>
                        @endif
                    </dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($shipment->collected_amount) }}</dd>
                </div>
                <div class="flex justify-between border-t border-ink-100 pt-2">
                    <dt class="text-ink-600">أجرة التوصيل</dt>
                    <dd dir="ltr">{{ number_format($shipment->delivery_fee) }}</dd>
                </div>
                @if ($shipment->extra_fee)
                    <div class="flex justify-between">
                        <dt class="text-ink-600">رسوم إضافية</dt>
                        <dd dir="ltr">{{ number_format($shipment->extra_fee) }}</dd>
                    </div>
                @endif
                @if ($shipment->cod_fee)
                    <div class="flex justify-between">
                        <dt class="text-ink-600">عمولة التحصيل</dt>
                        <dd dir="ltr">{{ number_format($shipment->cod_fee) }}</dd>
                    </div>
                @endif
                @if ($shipment->discount)
                    <div class="flex justify-between text-ok-700">
                        <dt>خصم</dt>
                        <dd dir="ltr">−{{ number_format($shipment->discount) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-ink-200 pt-2">
                    <dt class="text-ink-600">مجموع الأجور</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($shipment->total_fees) }}</dd>
                </div>
                <div class="flex justify-between border-t-2 border-ink-300 pt-2">
                    <dt class="font-bold">مستحقّ التاجر</dt>
                    <dd class="text-base font-bold text-[var(--brand)]" dir="ltr">
                        {{ number_format($shipment->merchant_due) }} د.ع
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-ink-500">
                الأجرة على: {{ $shipment->fees_paid_by === 'customer' ? 'الزبون' : 'التاجر' }}
            </p>

            @php
                $settled = $shipment->courier_settlement_id || $shipment->merchant_settlement_id;
                $confirmable = auth()->user()->isStaff()
                    && in_array($shipment->status, [\App\Enums\ShipmentStatus::Delivered,
                                                    \App\Enums\ShipmentStatus::PartiallyDelivered], true);
            @endphp

            @if ($confirmable && $shipment->amount_confirmed)
                <p class="mt-4 rounded-lg bg-ok-50 px-3 py-2 text-xs text-ok-700">
                    أكّد المبلغ
                    {{ \App\Models\User::withoutGlobalScopes()->find($shipment->amount_confirmed_by_user_id)?->name ?? 'النظام' }}
                    في {{ $shipment->amount_confirmed_at?->format('Y-m-d H:i') }}. لا يُعدَّل بعد التأكيد.
                </p>
            @elseif ($confirmable && $settled)
                <p class="mt-4 rounded-lg bg-ink-100 px-3 py-2 text-xs text-ink-600">
                    دخلت هذه الشحنة كشف تسوية، فالتصحيح يكون بحركة على الحساب لا بتعديل الوصل.
                </p>
            @elseif ($confirmable)
                <button type="button" class="btn-primary mt-4 w-full"
                        onclick="document.getElementById('confirm-amount').showModal()">
                    تأكيد مبلغ الوصل
                </button>
                <p class="mt-2 text-center text-xs text-ink-500">يُراجَع الرقم مرّة واحدة ثم يُقفَل.</p>

                <dialog id="confirm-amount" class="modal">
                    <form method="POST" action="{{ route('shipments.amount', $shipment) }}">
                        @csrf
                        <div class="modal-head">
                            <h3 class="font-bold">تأكيد مبلغ الوصل {{ $shipment->number }}</h3>
                            <p class="mt-1 text-xs text-ink-500">
                                راجع الرقم مع المندوب قبل التأكيد — بعده لا يُعدَّل.
                            </p>
                        </div>

                        <div class="modal-body">
                            <div class="flex justify-between rounded-lg bg-ink-50 px-3 py-2 text-sm">
                                <span class="text-ink-600">المكتوب على الوصل</span>
                                <span class="num font-semibold">{{ number_format($shipment->cod_amount) }}</span>
                            </div>

                            <div>
                                <label class="field-label" for="confirm_amount_input">المبلغ المحصَّل فعلاً</label>
                                <input id="confirm_amount_input" name="collected_amount" type="number"
                                       min="0" step="1" required class="field-input num"
                                       value="{{ old('collected_amount', $shipment->collected_amount) }}">
                                <p class="field-hint">
                                    أيّ فرق عن المبلغ الحالي يُقيَّد حركةً على حساب التاجر والمندوب معاً.
                                </p>
                                @error('collected_amount') <p class="field-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="field-label" for="confirm_amount_note">سبب الفرق (إن وُجد)</label>
                                <input id="confirm_amount_note" name="note" type="text" maxlength="255"
                                       class="field-input" placeholder="مثلاً: ردّ الزبون قطعة">
                            </div>

                            <label class="flex items-start gap-2 rounded-lg bg-warn-50 px-3 py-2 text-sm text-warn-700">
                                <input type="checkbox" name="acknowledge" value="1" required
                                       class="mt-0.5 size-4 accent-[var(--color-warn-700)]">
                                <span>أُقرّ أن هذا المبلغ نهائي، ولا يُعدَّل بعد التأكيد.</span>
                            </label>
                            @error('acknowledge') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="modal-foot">
                            <button type="button" class="btn-ghost"
                                    onclick="document.getElementById('confirm-amount').close()">تراجع</button>
                            <button type="submit" class="btn-primary">تأكيد نهائي</button>
                        </div>
                    </form>
                </dialog>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">المسؤولية والموقع</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-600">مندوب التوصيل</dt>
                    <dd class="font-medium">{{ $shipment->deliveryCourier?->name ?? 'لم يُسنَد' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-ink-600">مندوب الاستلام</dt>
                    <dd class="font-medium">{{ $shipment->pickupCourier?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-ink-600">المركز الحالي</dt>
                    <dd class="font-medium">{{ $shipment->hub?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-ink-600">الفرع</dt>
                    <dd class="font-medium">{{ $shipment->branch?->name ?? '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</div>
@endsection

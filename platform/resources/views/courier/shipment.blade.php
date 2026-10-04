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
    {{-- سطران مضمَّنان لا كتلة: الكتلة تبتلع السطر المضمَّن أعلى الصفحة (الزرّ الثاني) --}}
    @php($waiting = (bool) $ticket?->isOpen())
    @php($partial = $ticket && $ticket->kind === 'partial' && $ticket->status === 'approved' && ! $ticket->used_at)

    {{-- جواب الكول سنتر على طلب تغيير المبلغ (docs/plan/30) --}}
    @if ($waiting)
        <div class="mb-3 rounded-xl border-2 border-warn-500 bg-warn-50 p-4"
             data-ticket-poll="{{ route('courier.shipments.ticket.status', $shipment) }}" data-ticket-status="open">
            <div class="font-bold text-warn-700">طلبك {{ $ticket->number }} بانتظار الكول سنتر</div>
            <p class="mt-1 text-sm text-warn-700">
                تغيير المبلغ إلى <span class="num font-bold">{{ number_format($ticket->requested_amount) }}</span> د.ع
                ({{ $ticket->kindLabel() }}). لا تسلّم بالمبلغ الجديد حتى يُعتمد — الصفحة تتحدّث وحدها حين يأتي الجواب.
            </p>
            <a href="{{ route('courier.shipments.show', $shipment) }}"
               class="mt-3 block rounded-lg bg-white px-4 py-2 text-center text-sm font-semibold text-warn-700 ring-1 ring-warn-500">تحديث الآن</a>
        </div>
    @elseif ($ticket?->status === 'rejected')
        <div class="mb-3 rounded-xl border-2 border-bad-700 bg-bad-50 p-4">
            <div class="font-bold text-bad-700">رُفض طلبك {{ $ticket->number }}</div>
            <p class="mt-1 text-sm text-bad-700">{{ $ticket->reply }}</p>
            <p class="mt-1 text-sm text-bad-700">سلّم بالمبلغ الأصلي، أو سجّلها «لم يُسلَّم» بسببها.</p>
        </div>
    @elseif ($ticket?->status === 'approved' && $ticket->kind === 'price')
        <div class="mb-3 rounded-xl border-2 border-ok-700 bg-ok-50 p-4">
            <div class="font-bold text-ok-700">اعتمد الكول سنتر المبلغ الجديد ({{ $ticket->number }})</div>
            <p class="mt-1 text-sm text-ok-700">
                كان <span class="num">{{ number_format($ticket->current_amount) }}</span> وصار
                <span class="num font-bold">{{ number_format($shipment->cod_amount) }}</span> د.ع — سلّم به.
                @if ($ticket->reply) {{ $ticket->reply }} @endif
            </p>
        </div>
    @elseif ($partial)
        <div class="mb-3 rounded-xl border-2 border-ok-700 bg-ok-50 p-4">
            <div class="font-bold text-ok-700">اعتمد الكول سنتر الواصل الجزئي ({{ $ticket->number }})</div>
            <p class="mt-1 text-sm text-ok-700">
                استلم من الزبون <span class="num font-bold">{{ number_format($ticket->approved_amount) }}</span> د.ع،
                وارجع بباقي الطلب. @if ($ticket->reply) {{ $ticket->reply }} @endif
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('courier.shipments.act', $shipment) }}"
          class="space-y-3" data-courier-form>
        @csrf
        <input type="hidden" name="lat" data-geo-lat>
        <input type="hidden" name="lng" data-geo-lng>

        {{-- تسليم: الفعل الأكثر تكراراً، فهو الأكبر والأول. والمبلغ ثابت: لا يُكتب هنا --}}
        <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
            <p class="text-sm text-ink-600">
                تستلم من الزبون <span class="num font-bold text-ink-900">{{ number_format($shipment->cod_amount) }}</span> د.ع كاملة.
                إن قال مبلغاً آخر فلا تسلّم: اطلب تغيير المبلغ من الكول سنتر (أسفل).
            </p>

            {{-- تاجرٌ يطلب كود التسليم: الزبون يعطيه للمندوب عند الباب، ولا تسليم بدونه --}}
            @if ($shipment->delivery_code)
                <label class="field-label mt-3" for="delivery_code">كود التسليم من الزبون</label>
                <input id="delivery_code" name="delivery_code" class="field-input text-center text-lg tracking-[0.4em]" dir="ltr"
                       inputmode="numeric" autocomplete="off" maxlength="6" placeholder="••••">
                @error('delivery_code') <p class="field-error">{{ $message }}</p> @enderror
            @endif

            @if ($partial)
                <button type="submit" name="action" value="partially_delivered"
                        class="mt-3 w-full rounded-xl bg-ok-700 px-4 py-4 text-lg font-bold text-white active:brightness-110">
                    واصل جزئي — استلمت <span class="num">{{ number_format($ticket->approved_amount) }}</span>
                </button>
                <button type="submit" name="action" value="delivered"
                        class="mt-2 w-full rounded-xl bg-white px-4 py-3 text-base font-semibold text-ink-700 ring-1 ring-ink-300 active:bg-ink-50">
                    أخذ الطلب كلّه — واصل بـ<span class="num">{{ number_format($shipment->cod_amount) }}</span>
                </button>
            @else
                <button type="submit" name="action" value="delivered"
                        class="mt-3 w-full rounded-xl bg-ok-700 px-4 py-4 text-lg font-bold text-white active:brightness-110">
                    واصل — استلمت <span class="num">{{ number_format($shipment->cod_amount) }}</span>
                </button>
            @endif
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

    {{-- المبلغ لا يُغيَّر عند الباب: يُطلب من الكول سنتر، وجوابه يصل هنا --}}
    @unless ($waiting || $partial)
        <details class="mt-3 rounded-xl border border-ink-200 bg-white p-4 shadow-xs"
                 @if ($errors->hasAny(['kind', 'requested_amount', 'reason'])) open @endif>
            <summary class="cursor-pointer text-base font-bold">الزبون يريد يدفع مبلغاً آخر؟</summary>
            <form method="POST" action="{{ route('courier.shipments.ticket', $shipment) }}" class="mt-3 space-y-3">
                @csrf
                <fieldset>
                    <legend class="field-label">ماذا حدث؟</legend>
                    <div class="space-y-2">
                        @foreach (\App\Models\ShipmentTicket::KINDS as $value => $label)
                            <label class="flex items-start gap-2 rounded-lg border border-ink-200 px-3 py-2.5 text-base has-[:checked]:border-[var(--brand)] has-[:checked]:bg-ink-50">
                                <input type="radio" name="kind" value="{{ $value }}" class="mt-1" required @checked(old('kind') === $value)>
                                <span>{{ $label }}
                                    <span class="block text-xs text-ink-500">{{ $value === 'partial'
                                        ? 'يدفع ثمن ما أخذه، وترجع بالباقي للتاجر'
                                        : 'اتّفق مع التاجر على سعرٍ آخر، أو السعر المكتوب خطأ' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('kind') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <div>
                    <label class="field-label" for="requested_amount">المبلغ الذي سيدفعه الزبون</label>
                    <div class="relative">
                        <input id="requested_amount" name="requested_amount" type="number" min="0" step="1" required
                               class="field-input ps-12 text-left text-lg" dir="ltr" value="{{ old('requested_amount') }}">
                        <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                    </div>
                    @error('requested_amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="reason">ما قاله الزبون</label>
                    <textarea id="reason" name="reason" rows="2" required maxlength="255" class="field-input text-base"
                              placeholder="مثال: أخذ قطعتين من ثلاث، والثالثة مقاسها غلط">{{ old('reason') }}</textarea>
                    @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-ink-800 px-4 py-3.5 text-base font-bold text-white active:bg-ink-900">
                    أرسل للكول سنتر
                </button>
            </form>
        </details>
    @endunless
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

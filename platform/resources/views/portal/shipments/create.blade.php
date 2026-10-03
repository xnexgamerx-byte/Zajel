@extends('layouts.portal')
@section('title', 'شحنة جديدة')

@section('content')
{{--
  نموذج التاجر: حقول طلبه وحدها (docs/plan/28). أساسها الاسم والرقم والعنوان والسعر
  والعدد (يُكتب ١ تلقائياً)، وما سواها اختياري. لا أجور فيه ولا «من يدفع»: الأجرة
  عليه من تسعيرته مع الشركة، والسعر ما يدفعه الزبون كاملاً.
--}}
@php
    $required = '<span class="text-primary-600" aria-hidden="true">*</span>';
    $goods = $merchant->goodsTypeLabel();
@endphp
<div class="mx-auto mb-5 flex max-w-3xl flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">شحنة جديدة</h1>
        <p class="mt-1 text-sm text-ink-500">الأساسي: الاسم والرقم والعنوان والسعر والعدد — والباقي اختياري.</p>
    </div>
    <a href="{{ route('portal.shipments.index') }}" class="btn-ghost">شحناتي</a>
</div>

@if ($created)
    {{-- المحفوظة للتوّ: رقمها وطباعة وصلها، والنموذج تحتها فارغٌ للتالية --}}
    <div class="mx-auto mb-4 flex max-w-3xl flex-wrap items-center gap-3 rounded-3xl border border-ok-200 bg-ok-50 px-5 py-3.5 text-sm text-ok-700"
         role="status">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-white text-ok-700"><x-icon name="check" class="size-5"/></span>
        <span>
            حُفظت الشحنة
            <a href="{{ route('portal.shipments.show', $created) }}" class="num font-bold underline-offset-4 hover:underline">{{ $created->number }}</a>
            — {{ $created->recipient_name }}، <span class="num">{{ number_format($created->cod_amount) }}</span> د.ع
            @if ($created->waybill_book_id) · على الوصل المطبوع <span class="num">{{ $created->barcode }}</span> @endif
        </span>
        <span class="ms-auto flex flex-wrap items-center gap-2">
            <a href="{{ route('portal.pickups.index') }}" class="text-xs font-medium underline-offset-4 hover:underline">اطلب استلاماً</a>
            @unless ($created->waybill_book_id)
                <a href="{{ route('portal.shipments.labels', ['ids' => [$created->id]]) }}" target="_blank" class="btn-ghost">
                    <x-icon name="printer" class="size-4"/>
                    اطبع الوصل
                </a>
            @endunless
        </span>
    </div>
@endif

<form method="POST" action="{{ route('portal.shipments.store') }}" class="mx-auto max-w-3xl">
    @csrf

    <section class="card overflow-hidden">
        <div class="divide-y divide-ink-100">

            {{-- الزبون --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="user" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">الزبون</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="field-label" for="recipient_name">اسم الزبون {!! $required !!}</label>
                        <input id="recipient_name" name="recipient_name" class="field-input" placeholder="مثلاً: طه محمد"
                               autocomplete="off" required autofocus value="{{ old('recipient_name') }}">
                        @error('recipient_name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="recipient_phone">رقم الهاتف الأساسي {!! $required !!}</label>
                        <input id="recipient_phone" name="recipient_phone" class="field-input text-left" dir="ltr"
                               inputmode="numeric" autocomplete="off" placeholder="07xxxxxxxxx" required value="{{ old('recipient_phone') }}">
                        @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="recipient_phone_alt">رقم الهاتف الثانوي</label>
                        <input id="recipient_phone_alt" name="recipient_phone_alt" class="field-input text-left" dir="ltr"
                               inputmode="numeric" autocomplete="off" placeholder="اختياري" value="{{ old('recipient_phone_alt') }}">
                        @error('recipient_phone_alt') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- العنوان --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="pin" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">العنوان</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="governorate_id">المحافظة {!! $required !!}</label>
                        <select id="governorate_id" name="governorate_id" class="field-input" required>
                            <option value="">اختر المحافظة</option>
                            @foreach ($governorates as $gov)
                                <option value="{{ $gov->id }}" @selected((int) old('governorate_id') === $gov->id)>{{ $gov->name_ar }}</option>
                            @endforeach
                        </select>
                        @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="city_id">المنطقة {!! $required !!}</label>
                        <select id="city_id" name="city_id" class="field-input" data-searchable data-old="{{ old('city_id') }}">
                            <option value="">اختر المحافظة أولاً</option>
                        </select>
                        @error('city_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="field-label" for="landmark">أقرب نقطة دالّة</label>
                        <input id="landmark" name="landmark" class="field-input"
                               placeholder="اختياري — مثال: مقابل جامع الرحمن · قرب مول بابل" value="{{ old('landmark') }}">
                        @error('landmark') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- الطلب --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="box" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">الطلب</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label class="field-label" for="cod_amount">السعر مع التوصيل {!! $required !!}</label>
                        <div class="relative">
                            {{-- فارغٌ لا صفر: يُكتب السعر مباشرةً، والصفر يُكتب قصداً لما دُفع مسبقاً --}}
                            <input id="cod_amount" name="cod_amount" type="number" min="0" step="1" required placeholder="مثلاً 25 000"
                                   class="field-input ps-12 text-left text-base font-semibold placeholder:font-normal" dir="ltr" value="{{ old('cod_amount') }}">
                            <span class="absolute inset-y-0 end-4 flex items-center text-xs text-ink-400">د.ع</span>
                        </div>
                        <p class="field-hint">ما يدفعه الزبون كاملاً مع أجرة التوصيل — 0 إن دفع لك مسبقاً.</p>
                        @error('cod_amount') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="pieces_count">عدد القطع {!! $required !!}</label>
                        <input id="pieces_count" name="pieces_count" type="number" min="1" max="255" required
                               class="field-input text-left" dir="ltr" value="{{ old('pieces_count', 1) }}">
                        @error('pieces_count') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="field-label" for="description">نوع البضاعة</label>
                        <input id="description" name="description" class="field-input" maxlength="2000"
                               placeholder="مثلاً: {{ $goods && ! in_array($merchant->goods_type, ['general', 'other'], true) ? $goods : 'ملابس' }}"
                               value="{{ old('description') }}">
                        @error('description') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="size">حجم الطلب</label>
                        <select id="size" name="size" class="field-input">
                            @foreach (\App\Models\Shipment::SIZES as $value => $label)
                                <option value="{{ $value }}" @selected(old('size', 'normal') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('size') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="type">نوع الطلب</label>
                        <select id="type" name="type" class="field-input">
                            @foreach (\App\Models\Shipment::TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', 'delivery') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-3">
                        <label class="field-label" for="notes">الملاحظات</label>
                        <textarea id="notes" name="notes" rows="2" class="field-input"
                                  placeholder="اختياري — تُطبع على الوصل للمندوب، مثال: اتصل قبل الوصول">{{ old('notes') }}</textarea>
                        @error('notes') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- «البروموكود» في النظام المعتاد: رقم الوصل المطبوع الملصوق على الطرد، إن وُجد --}}
            <div class="px-5 py-5 sm:px-7">
                <label class="field-label flex items-center gap-2" for="waybill">
                    <x-icon name="receipt" class="size-4 text-ink-500"/>
                    رقم الوصل المطبوع
                </label>
                <input id="waybill" name="waybill" class="field-input" inputmode="numeric" autocomplete="off"
                       maxlength="20" placeholder="اختياري — امسح رمز الوصل أو اكتب رقمه" value="{{ old('waybill') }}">
                <p class="field-hint">
                    إن لصقت على الطرد وصلاً من <a href="{{ route('portal.waybills.index') }}" class="underline underline-offset-4">وصولات للطباعة</a>
                    تحمل الشحنة رقمه، فلا تحتاج طباعة وصلٍ لها.
                </p>
                @error('waybill') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="border-t border-ink-100 bg-ink-50/70 px-5 py-4 sm:px-7">
            <button type="submit" class="btn-primary w-full py-3 text-base">
                <x-icon name="check" class="size-5"/>
                حفظ الشحنة
            </button>
            <p class="mt-2 text-center text-xs text-ink-500">بعد الحفظ يفتح النموذج للطلب التالي.</p>
        </div>
    </section>
</form>

@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($g) => $g->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
@endsection

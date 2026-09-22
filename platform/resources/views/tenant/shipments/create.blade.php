@extends('layouts.app')
@section('title', 'شحنة جديدة')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold">شحنة جديدة</h1>
        <p class="mt-1 text-sm text-ink-500">رقم الوصل يُولَّد تلقائياً عند الحفظ.</p>
    </div>
    <a href="{{ route('shipments.index') }}" class="btn-ghost">رجوع للقائمة</a>
</div>

<form method="POST" action="{{ route('shipments.store') }}" id="shipment-form"
      data-quote-url="{{ route('pricing.quote') }}"
      class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf

    <div class="space-y-5 lg:col-span-2">

        {{-- التاجر --}}
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold text-ink-900">التاجر</h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="merchant_id">التاجر <span class="text-red-500">*</span></label>
                    <select id="merchant_id" name="merchant_id" class="field-input" required>
                        <option value="">اختر التاجر</option>
                        @foreach ($merchants as $merchant)
                            <option value="{{ $merchant->id }}" @selected(old('merchant_id') == $merchant->id)>
                                {{ $merchant->business_name }} — {{ $merchant->phone }}
                            </option>
                        @endforeach
                    </select>
                    @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="merchant_reference">رقم الطلب عند التاجر</label>
                    <input id="merchant_reference" name="merchant_reference" value="{{ old('merchant_reference') }}"
                           class="field-input" placeholder="اختياري">
                    @error('merchant_reference') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- المستلم --}}
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold text-ink-900">المستلم والعنوان</h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="recipient_name">اسم المستلم <span class="text-red-500">*</span></label>
                    <input id="recipient_name" name="recipient_name" value="{{ old('recipient_name') }}"
                           class="field-input" required>
                    @error('recipient_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="recipient_phone">هاتف المستلم <span class="text-red-500">*</span></label>
                    <input id="recipient_phone" name="recipient_phone" value="{{ old('recipient_phone') }}"
                           class="field-input text-left" dir="ltr" inputmode="numeric"
                           placeholder="07xxxxxxxxx" required>
                    @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="recipient_phone_alt">هاتف بديل</label>
                    <input id="recipient_phone_alt" name="recipient_phone_alt" value="{{ old('recipient_phone_alt') }}"
                           class="field-input text-left" dir="ltr" inputmode="numeric" placeholder="07xxxxxxxxx">
                    @error('recipient_phone_alt') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div></div>

                <div>
                    <label class="field-label" for="governorate_id">المحافظة <span class="text-red-500">*</span></label>
                    <select id="governorate_id" name="governorate_id" class="field-input" required>
                        <option value="">اختر المحافظة</option>
                        @foreach ($governorates as $gov)
                            <option value="{{ $gov->id }}" @selected(old('governorate_id') == $gov->id)>
                                {{ $gov->name_ar }}
                            </option>
                        @endforeach
                    </select>
                    @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="city_id">المنطقة</label>
                    <select id="city_id" name="city_id" class="field-input" data-old="{{ old('city_id') }}">
                        <option value="">اختر المحافظة أولاً</option>
                    </select>
                    @error('city_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="field-label" for="address">العنوان <span class="text-red-500">*</span></label>
                    <input id="address" name="address" value="{{ old('address') }}" class="field-input"
                           placeholder="المنطقة، الشارع، رقم الدار" required>
                    @error('address') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="field-label" for="landmark">
                        أقرب نقطة دالّة <span class="text-red-500">*</span>
                    </label>
                    <input id="landmark" name="landmark" value="{{ old('landmark') }}" class="field-input"
                           placeholder="مثال: مقابل جامع الرحمن · قرب مول بابل · خلف صيدلية النور" required>
                    <p class="mt-1 text-xs text-ink-500">
                        إلزامية — لا رموز بريدية في العراق، وهذه هي ما يوصل المندوب فعلاً.
                    </p>
                    @error('landmark') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- الطرد --}}
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold text-ink-900">الطرد</h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label class="field-label" for="description">وصف المحتوى</label>
                    <input id="description" name="description" value="{{ old('description') }}"
                           class="field-input" placeholder="مثال: ملابس — قطعتان">
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="pieces_count">عدد القطع <span class="text-red-500">*</span></label>
                    <input id="pieces_count" name="pieces_count" type="number" min="1" max="255"
                           value="{{ old('pieces_count', 1) }}" class="field-input" dir="ltr" required>
                    @error('pieces_count') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="weight_grams">الوزن (غرام)</label>
                    <input id="weight_grams" name="weight_grams" type="number" min="0"
                           value="{{ old('weight_grams', 0) }}" class="field-input" dir="ltr">
                    @error('weight_grams') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-end gap-4 pb-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_fragile" value="1" @checked(old('is_fragile'))
                               class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                        قابل للكسر
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="allow_open" value="1" @checked(old('allow_open'))
                               class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                        يُسمح بالفتح
                    </label>
                </div>

                <div class="sm:col-span-3">
                    <label class="field-label" for="notes">ملاحظات للمندوب</label>
                    <textarea id="notes" name="notes" rows="2" class="field-input"
                              placeholder="مثال: اتصل قبل الوصول بنصف ساعة">{{ old('notes') }}</textarea>
                    @error('notes') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
    </div>

    {{-- المال — عمود ثابت --}}
    <div class="lg:col-span-1">
        <section class="card sticky top-20 p-5">
            <h2 class="mb-4 text-sm font-bold text-ink-900">المبالغ</h2>

            <div class="space-y-4">
                <div>
                    <label class="field-label" for="cod_amount">
                        المبلغ المطلوب من الزبون <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <input id="cod_amount" name="cod_amount" type="number" min="0" step="250"
                               value="{{ old('cod_amount', 0) }}" class="field-input pe-12 text-left" dir="ltr" required>
                        <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">صفر = مدفوع مسبقاً.</p>
                    @error('cod_amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="fees_paid_by">من يدفع الأجرة؟</label>
                    <select id="fees_paid_by" name="fees_paid_by" class="field-input">
                        <option value="merchant" @selected(old('fees_paid_by', 'merchant') === 'merchant')>
                            التاجر (تُخصم من مستحقّه)
                        </option>
                        <option value="customer" @selected(old('fees_paid_by') === 'customer')>
                            الزبون (تُضاف على المبلغ)
                        </option>
                    </select>
                </div>

                <div>
                    <label class="field-label" for="delivery_fee">أجرة التوصيل</label>
                    <div class="relative">
                        <input id="delivery_fee" name="delivery_fee" type="number" min="0" step="250"
                               value="{{ old('delivery_fee') }}" class="field-input pe-12 text-left" dir="ltr"
                               placeholder="تُحسب من التسعيرة">
                        <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">اتركه فارغاً ليُحسب من تسعيرة التاجر.</p>
                    @error('delivery_fee') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label" for="extra_fee">رسوم إضافية</label>
                        <input id="extra_fee" name="extra_fee" type="number" min="0" step="250"
                               value="{{ old('extra_fee', 0) }}" class="field-input text-left" dir="ltr">
                    </div>
                    <div>
                        <label class="field-label" for="discount">خصم</label>
                        <input id="discount" name="discount" type="number" min="0" step="250"
                               value="{{ old('discount', 0) }}" class="field-input text-left" dir="ltr">
                    </div>
                </div>

                {{-- الحساب الحيّ --}}
                <div class="rounded-lg bg-ink-50 p-4 text-sm ring-1 ring-ink-200" id="quote-box">
                    <div class="flex justify-between py-1">
                        <span class="text-ink-600">أجرة التوصيل</span>
                        <span class="font-semibold" dir="ltr" data-quote="delivery_fee">—</span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-ink-600">عمولة التحصيل</span>
                        <span class="font-semibold" dir="ltr" data-quote="cod_fee">—</span>
                    </div>
                    <div class="flex justify-between border-t border-ink-200 py-1 pt-2">
                        <span class="text-ink-600">مجموع الأجور</span>
                        <span class="font-semibold" dir="ltr" data-quote="total_fees">—</span>
                    </div>
                    <div class="flex justify-between border-t-2 border-ink-300 py-1 pt-2">
                        <span class="font-bold text-ink-900">مستحقّ التاجر</span>
                        <span class="text-base font-bold text-[var(--brand)]" dir="ltr" data-quote="merchant_due">—</span>
                    </div>
                    <p class="mt-2 hidden text-xs text-warn-700" data-quote-warning>
                        لا توجد قاعدة تسعير مطابقة — أدخل الأجرة يدوياً.
                    </p>
                </div>

                <button type="submit" class="btn-primary w-full">حفظ الشحنة</button>
            </div>
        </section>
    </div>
</form>

@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($group) => $group->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
@endsection

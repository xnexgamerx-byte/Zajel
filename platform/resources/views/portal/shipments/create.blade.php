@extends('layouts.portal')
@section('title', 'شحنة جديدة')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="page-title">شحنة جديدة</h1>
        <p class="mt-1 text-sm text-ink-500">
            رقم الوصل يُولَّد عند الحفظ، والأجرة تُحسب من تسعيرتك مع {{ $company->name }}.
        </p>
    </div>
    <a href="{{ route('portal.shipments.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ route('portal.shipments.store') }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf

    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الزبون والعنوان</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="recipient_name">اسم الزبون <span class="text-red-500">*</span></label>
                    <input id="recipient_name" name="recipient_name" class="field-input" required
                           value="{{ old('recipient_name') }}">
                    @error('recipient_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="recipient_phone">هاتفه <span class="text-red-500">*</span></label>
                    <input id="recipient_phone" name="recipient_phone" class="field-input text-left" dir="ltr"
                           inputmode="numeric" placeholder="07xxxxxxxxx" required value="{{ old('recipient_phone') }}">
                    @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="recipient_phone_alt">هاتف بديل</label>
                    <input id="recipient_phone_alt" name="recipient_phone_alt" class="field-input text-left" dir="ltr"
                           placeholder="07xxxxxxxxx" value="{{ old('recipient_phone_alt') }}">
                    <p class="mt-1 text-xs text-ink-500">رقم ثانٍ يقلّل الرواجع كثيراً.</p>
                    @error('recipient_phone_alt') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="merchant_reference">رقم الطلب عندك</label>
                    <input id="merchant_reference" name="merchant_reference" class="field-input"
                           placeholder="اختياري" value="{{ old('merchant_reference') }}">
                </div>

                <div>
                    <label class="field-label" for="governorate_id">المحافظة <span class="text-red-500">*</span></label>
                    <select id="governorate_id" name="governorate_id" class="field-input" required>
                        <option value="">اختر</option>
                        @foreach ($governorates as $gov)
                            <option value="{{ $gov->id }}" @selected((int) old('governorate_id') === $gov->id)>
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
                    <input id="address" name="address" class="field-input" required
                           placeholder="المنطقة، الشارع، رقم الدار" value="{{ old('address') }}">
                    @error('address') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="field-label" for="landmark">أقرب نقطة دالّة <span class="text-red-500">*</span></label>
                    <input id="landmark" name="landmark" class="field-input" required
                           placeholder="مقابل جامع الرحمن · قرب مول بابل · خلف صيدلية النور"
                           value="{{ old('landmark') }}">
                    <p class="mt-1 text-xs text-ink-500">
                        هذه أهم خانة في النموذج: المندوب يصل بها لا بالعنوان.
                    </p>
                    @error('landmark') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الطرد</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label class="field-label" for="description">المحتوى</label>
                    <input id="description" name="description" class="field-input"
                           placeholder="مثال: ملابس — قطعتان" value="{{ old('description') }}">
                </div>
                <div>
                    <label class="field-label" for="pieces_count">عدد القطع <span class="text-red-500">*</span></label>
                    <input id="pieces_count" name="pieces_count" type="number" min="1" class="field-input text-left"
                           dir="ltr" required value="{{ old('pieces_count', 1) }}">
                    @error('pieces_count') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="weight_grams">الوزن (غرام)</label>
                    <input id="weight_grams" name="weight_grams" type="number" min="0" class="field-input text-left"
                           dir="ltr" value="{{ old('weight_grams', 0) }}">
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
                        يُفتح
                    </label>
                </div>
                <div class="sm:col-span-3">
                    <label class="field-label" for="notes">ملاحظات للمندوب</label>
                    <textarea id="notes" name="notes" rows="2" class="field-input"
                              placeholder="مثال: اتصل قبل الوصول بنصف ساعة">{{ old('notes') }}</textarea>
                </div>
            </div>
        </section>
    </div>

    <div>
        <section class="card sticky top-20 space-y-4 p-5">
            <h2 class="text-sm font-bold">المبلغ</h2>

            <div>
                <label class="field-label" for="cod_amount">
                    المطلوب من الزبون <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <input id="cod_amount" name="cod_amount" type="number" min="0" step="1" required
                           class="field-input ps-12 text-left" dir="ltr" value="{{ old('cod_amount', 0) }}">
                    <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                </div>
                <p class="mt-1 text-xs text-ink-500">صفر = الزبون دفع لك مسبقاً.</p>
                @error('cod_amount') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="fees_paid_by">من يدفع أجرة التوصيل؟</label>
                <select id="fees_paid_by" name="fees_paid_by" class="field-input" required>
                    <option value="merchant" @selected(old('fees_paid_by', 'merchant') === 'merchant')>
                        أنا (تُخصم من مستحقّي)
                    </option>
                    <option value="customer" @selected(old('fees_paid_by') === 'customer')>
                        الزبون (فوق المبلغ)
                    </option>
                </select>
            </div>

            <div class="rounded-lg bg-ink-50 px-3 py-2.5 text-xs text-ink-600 ring-1 ring-ink-200">
                الأجرة تُحسب من تسعيرتك مع {{ $company->name }} حسب المحافظة والوزن،
                وتظهر في صفحة الشحنة بعد الحفظ.
            </div>

            <button type="submit" class="btn-primary w-full">احفظ الشحنة</button>
        </section>
    </div>
</form>

@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($g) => $g->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
@endsection

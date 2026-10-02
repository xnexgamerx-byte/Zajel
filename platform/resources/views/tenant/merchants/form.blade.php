@extends('layouts.app')
@section('title', $merchant->exists ? 'تعديل تاجر' : 'تاجر جديد')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="page-title">{{ $merchant->exists ? 'تعديل ' . $merchant->business_name : 'تاجر جديد' }}</h1>
        @if ($merchant->exists)
            <p class="mt-1 text-sm text-ink-500">الرمز {{ $merchant->code }}</p>
        @else
            <p class="mt-1 text-sm text-ink-500">الرمز يُولَّد تلقائياً عند الحفظ.</p>
        @endif
    </div>
    <a href="{{ route('merchants.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST"
      action="{{ $merchant->exists ? route('merchants.update', $merchant) : route('merchants.store') }}"
      class="grid grid-cols-1 gap-5 lg:grid-cols-3" id="merchant-form">
    @csrf
    @if ($merchant->exists) @method('PUT') @endif

    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">المتجر</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="business_name">اسم المتجر <span class="text-red-500">*</span></label>
                    <input id="business_name" name="business_name" class="field-input" required
                           value="{{ old('business_name', $merchant->business_name) }}">
                    @error('business_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="owner_name">اسم صاحب المتجر</label>
                    <input id="owner_name" name="owner_name" class="field-input"
                           value="{{ old('owner_name', $merchant->owner_name) }}">
                    @error('owner_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="phone">الهاتف <span class="text-red-500">*</span></label>
                    <input id="phone" name="phone" class="field-input text-left" dir="ltr" inputmode="numeric"
                           placeholder="07xxxxxxxxx" required value="{{ old('phone', $merchant->phone) }}">
                    @error('phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="phone_alt">هاتف بديل</label>
                    <input id="phone_alt" name="phone_alt" class="field-input text-left" dir="ltr"
                           placeholder="07xxxxxxxxx" value="{{ old('phone_alt', $merchant->phone_alt) }}">
                    @error('phone_alt') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="email">البريد</label>
                    <input id="email" name="email" type="email" class="field-input text-left" dir="ltr"
                           value="{{ old('email', $merchant->email) }}">
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="branch_id">الفرع</label>
                    <select id="branch_id" name="branch_id" class="field-input">
                        <option value="">بلا فرع</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}"
                                    @selected((int) old('branch_id', $merchant->branch_id) === $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">التصنيف والخدمة</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="goods_type">نوع البضاعة</label>
                    <select id="goods_type" name="goods_type" class="field-input">
                        <option value="">—</option>
                        @foreach (\App\Models\Merchant::GOODS_TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('goods_type', $merchant->goods_type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('goods_type') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="pickup_courier_id">مندوب الاستلام</label>
                    <select id="pickup_courier_id" name="pickup_courier_id" class="field-input">
                        <option value="">—</option>
                        @foreach ($pickupCouriers as $courier)
                            <option value="{{ $courier->id }}" @selected((int) old('pickup_courier_id', $merchant->pickup_courier_id) === $courier->id)>{{ $courier->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-hint">يُقترح أوّلاً حين يطلب التاجر استلاماً.</p>
                    @error('pickup_courier_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="sales_user_id">موظّف المبيعات</label>
                    <select id="sales_user_id" name="sales_user_id" class="field-input">
                        <option value="">—</option>
                        @foreach ($salesUsers as $user)
                            <option value="{{ $user->id }}" @selected((int) old('sales_user_id', $merchant->sales_user_id) === $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-hint">من جاء به. الموظّف يُعلَّم «موظّف مبيعات» من صفحته.</p>
                    @error('sales_user_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-2.5 sm:pt-7">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="is_vip" value="0">
                        <input type="checkbox" name="is_vip" value="1" class="size-4 accent-[var(--brand)]"
                               @checked(old('is_vip', $merchant->is_vip))>
                        عميل مميّز <span class="text-xs text-ink-500">— تُعلَّم شحناته في القوائم</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="portal_access" value="0">
                        <input type="checkbox" name="portal_access" value="1" class="size-4 accent-[var(--brand)]"
                               @checked(old('portal_access', $merchant->exists ? $merchant->portal_access : true))>
                        يُسمح له بالدخول لبوابته
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="hidden" name="requires_delivery_code" value="0">
                        <input type="checkbox" name="requires_delivery_code" value="1" class="mt-1 size-4 accent-[var(--brand)]"
                               @checked(old('requires_delivery_code', $merchant->requires_delivery_code))>
                        <span>كود لتسليم الشحنة <span class="block text-xs text-ink-500">لكل شحنةٍ كودٌ يعطيه التاجر لزبونه، ولا يُسجَّل التسليم إلّا به</span></span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="hidden" name="hold_for_review" value="0">
                        <input type="checkbox" name="hold_for_review" value="1" class="mt-1 size-4 accent-[var(--brand)]"
                               @checked(old('hold_for_review', $merchant->hold_for_review))>
                        <span>شحناته تحت المراجعة <span class="block text-xs text-ink-500">لا تخرج مع مندوبٍ حتى يجيزها موظّف</span></span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="hidden" name="can_process" value="0">
                        <input type="checkbox" name="can_process" value="1" class="mt-1 size-4 accent-[var(--brand)]"
                               @checked(old('can_process', $merchant->can_process))>
                        <span>يعالج محاولاته الفاشلة بنفسه <span class="block text-xs text-ink-500">«إدخال طلبات العميل للمعالجة»: يقرّر من بوابته إعادة التوصيل أو التأجيل أو الإرجاع</span></span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="hidden" name="prepaid_billing" value="0">
                        <input type="checkbox" name="prepaid_billing" value="1" class="mt-1 size-4 accent-[var(--brand)]"
                               @checked(old('prepaid_billing', $merchant->prepaid_billing))>
                        <span>يُحاسَب مقدّماً <span class="block text-xs text-ink-500">يدفع أجور شحناته حين يُرسلها فلا تُخصم من مبالغها — تُقبض من «المال ← استلام أجور مدفوعة مقدّماً»</span></span>
                    </label>
                </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">عنوان الاستلام</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="governorate_id">المحافظة</label>
                    <select id="governorate_id" name="governorate_id" class="field-input">
                        <option value="">اختر</option>
                        @foreach ($governorates as $gov)
                            <option value="{{ $gov->id }}"
                                    @selected((int) old('governorate_id', $merchant->governorate_id) === $gov->id)>
                                {{ $gov->name_ar }}
                            </option>
                        @endforeach
                    </select>
                    @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="city_id">المنطقة</label>
                    <select id="city_id" name="city_id" class="field-input" data-searchable
                            data-old="{{ old('city_id', $merchant->city_id) }}">
                        <option value="">اختر المحافظة أولاً</option>
                    </select>
                    @error('city_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="field-label" for="address">العنوان</label>
                    <input id="address" name="address" class="field-input"
                           value="{{ old('address', $merchant->address) }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="field-label" for="landmark">أقرب نقطة دالّة</label>
                    <input id="landmark" name="landmark" class="field-input"
                           placeholder="ما يعرفه مندوب الاستلام ليصل إلى المتجر"
                           value="{{ old('landmark', $merchant->landmark) }}">
                </div>
            </div>
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">التسعير والتسوية</h2>
            <div class="space-y-4">
                <div>
                    <label class="field-label" for="price_list_id">قائمة التسعير</label>
                    @if (auth()->user()->isBranchLimited())
                        {{-- التسعيرة يختارها الفرع الرئيسي: موظّف الفرع يراها ولا يغيّرها --}}
                        <p id="price_list_id" class="field-input bg-ink-50 text-ink-600">
                            {{ $merchant->priceList?->name ?? 'تسعيرة الفرع' }}
                        </p>
                        <p class="mt-1 text-xs text-ink-500">يحدّدها الفرع الرئيسي. <a href="{{ route('pricing.branch') }}" class="text-[var(--brand)] hover:underline">تسعيرة فرعك</a></p>
                    @else
                        <select id="price_list_id" name="price_list_id" class="field-input">
                            <option value="">تسعيرة فرعه (وإلّا الافتراضية)</option>
                            @foreach ($priceLists as $list)
                                <option value="{{ $list->id }}"
                                        @selected((int) old('price_list_id', $merchant->price_list_id) === $list->id)>
                                    {{ $list->name }}{{ $list->is_default ? ' (افتراضية)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                    @error('price_list_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="settlement_cycle">دورة التسوية</label>
                    <select id="settlement_cycle" name="settlement_cycle" class="field-input" required>
                        @foreach (['daily' => 'يومي', 'weekly' => 'أسبوعي', 'biweekly' => 'كل أسبوعين',
                                   'monthly' => 'شهري', 'on_demand' => 'عند الطلب'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('settlement_cycle', $merchant->settlement_cycle ?? 'weekly') === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="field-label" for="payout_method">طريقة الدفع له</label>
                    <select id="payout_method" name="payout_method" class="field-input" required>
                        @foreach (['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                                   'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                                   'bank_transfer' => 'حوالة مصرفية'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('payout_method', $merchant->payout_method ?? 'cash') === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="field-label" for="payout_account">رقم المحفظة / الحساب</label>
                    <input id="payout_account" name="payout_account" class="field-input text-left" dir="ltr"
                           value="{{ old('payout_account', $merchant->payout_account) }}">
                </div>

                <div>
                    <label class="field-label" for="status">الحالة</label>
                    <select id="status" name="status" class="field-input" required>
                        @foreach (['active' => 'مفعّل', 'pending' => 'بانتظار التفعيل', 'suspended' => 'موقوف'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('status', $merchant->status ?? 'active') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="field-label" for="fixed_note">ملاحظة ثابتة على شحناته</label>
                    <input id="fixed_note" name="fixed_note" class="field-input" maxlength="255"
                           value="{{ old('fixed_note', $merchant->fixed_note) }}" placeholder="مثل: اتّصل قبل الوصول · لا يُفتح الطرد">
                    <p class="field-hint">تُلحق بملاحظات كل شحنةٍ جديدة له، من أيّ طريقٍ أُنشئت.</p>
                    @error('fixed_note') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="notes">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="2" class="field-input">{{ old('notes', $merchant->notes) }}</textarea>
                </div>
            </div>
        </section>

        @unless ($merchant->exists)
            <section class="card p-5">
                <h2 class="mb-4 text-sm font-bold">حساب الدخول</h2>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="create_login" value="1" @checked(old('create_login'))
                           class="mt-0.5 rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500"
                           data-toggle="login-fields">
                    <span>
                        أنشئ حساباً للتاجر على تطبيق التجّار
                        <span class="mt-0.5 block text-xs text-ink-500">
                            يدخل باسم مستخدمه — أو برقم هاتفه إن تُرك فارغاً — ويرى شحناته وحسابه فقط.
                        </span>
                    </span>
                </label>

                <div class="mt-4 space-y-4" id="login-fields" hidden>
                    <div>
                        <label class="field-label" for="username">اسم المستخدم</label>
                        <input id="username" name="username" class="field-input text-left" dir="ltr"
                               value="{{ old('username') }}" autocomplete="off" autocapitalize="none"
                               spellcheck="false" placeholder="فارغاً: رقم هاتفه">
                        @error('username') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="password">كلمة المرور</label>
                        <input id="password" name="password" type="text" class="field-input text-left" dir="ltr"
                               placeholder="ستُسلَّم للتاجر">
                        @error('password') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>
        @endunless

        <button type="submit" class="btn-primary w-full">
            {{ $merchant->exists ? 'حفظ التعديلات' : 'إضافة التاجر' }}
        </button>
    </div>
</form>

@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($g) => $g->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
@endsection

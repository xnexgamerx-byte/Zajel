@extends('layouts.platform')
@section('title', 'تسجيل شركة')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="page-title">تسجيل شركة جديدة</h1>
        <p class="mt-1 text-sm text-ink-500">
            يُنشأ نظام كامل: فرع، مركز فرز، تسعيرة افتراضية، وحساب صاحب الشركة.
        </p>
    </div>
    <a href="{{ route('admin.companies.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ route('admin.companies.store') }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf

    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الشركة</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="name">الاسم بالعربي <span class="text-red-500">*</span></label>
                    <input id="name" name="name" class="field-input" required value="{{ old('name') }}"
                           placeholder="مثال: البرق للتوصيل">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="name_en">الاسم بالإنجليزي</label>
                    <input id="name_en" name="name_en" class="field-input text-left" dir="ltr"
                           value="{{ old('name_en') }}">
                </div>

                <div class="sm:col-span-2">
                    <label class="field-label" for="slug">النطاق الفرعي <span class="text-red-500">*</span></label>
                    <div class="flex items-center gap-2" dir="ltr">
                        <input id="slug" name="slug" class="field-input text-left" required
                               value="{{ old('slug') }}" placeholder="barq" pattern="[a-z0-9]+(-[a-z0-9]+)*">
                        <span class="shrink-0 text-sm text-ink-500">.{{ config('zajel.tenant_domain') }}</span>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">
                        عنوان نظام الشركة. لا يتغيّر بعد التسجيل لأن التطبيقات تتصل به.
                    </p>
                    @error('slug') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="phone">هاتف الشركة</label>
                    <input id="phone" name="phone" class="field-input text-left" dir="ltr"
                           placeholder="07xxxxxxxxx" value="{{ old('phone') }}">
                    @error('phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="email">بريد الشركة</label>
                    <input id="email" name="email" type="email" class="field-input text-left" dir="ltr"
                           value="{{ old('email') }}">
                </div>

                <div>
                    <label class="field-label" for="governorate_id">المحافظة</label>
                    <select id="governorate_id" name="governorate_id" class="field-input">
                        <option value="">اختر</option>
                        @foreach ($governorates as $gov)
                            <option value="{{ $gov->id }}" @selected((int) old('governorate_id') === $gov->id)>
                                {{ $gov->name_ar }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="primary_color">لون العلامة</label>
                    <input id="primary_color" name="primary_color" type="color"
                           class="field-input h-10 p-1" value="{{ old('primary_color', '#0d9488') }}">
                    <p class="mt-1 text-xs text-ink-500">يظهر في لوحة الشركة وتطبيقاتها.</p>
                </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">حساب صاحب الشركة</h2>
            <p class="mb-4 text-xs text-ink-500">
                هذا هو الحساب الذي تسلّمه للعميل. يدخل به ويُنشئ بقية مستخدميه بنفسه.
            </p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="owner_name">الاسم <span class="text-red-500">*</span></label>
                    <input id="owner_name" name="owner_name" class="field-input" required
                           value="{{ old('owner_name') }}">
                    @error('owner_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="owner_phone">الهاتف <span class="text-red-500">*</span></label>
                    <input id="owner_phone" name="owner_phone" class="field-input text-left" dir="ltr" required
                           placeholder="07xxxxxxxxx" value="{{ old('owner_phone') }}">
                    @error('owner_phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="owner_email">البريد</label>
                    <input id="owner_email" name="owner_email" type="email" class="field-input text-left" dir="ltr"
                           value="{{ old('owner_email') }}">
                </div>
                <div>
                    <label class="field-label" for="owner_password">كلمة المرور <span class="text-red-500">*</span></label>
                    <input id="owner_password" name="owner_password" type="text" class="field-input text-left"
                           dir="ltr" required placeholder="ستُسلَّم للعميل">
                    @error('owner_password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">التسعيرة الابتدائية</h2>
            <p class="mb-4 text-xs text-ink-500">
                قاعدة واحدة تشمل كل العراق حتى تضبط الشركة تسعيرتها — فالنظام يسعّر من أول شحنة.
            </p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="field-label" for="branch_name">اسم الفرع الرئيسي</label>
                    <input id="branch_name" name="branch_name" class="field-input"
                           value="{{ old('branch_name', 'الفرع الرئيسي') }}">
                </div>
                <div>
                    <label class="field-label" for="default_delivery_fee">أجرة التوصيل</label>
                    <input id="default_delivery_fee" name="default_delivery_fee" type="number" min="0" step="1"
                           class="field-input text-left" dir="ltr" value="{{ old('default_delivery_fee', 5000) }}">
                </div>
                <div>
                    <label class="field-label" for="default_return_fee">أجرة الراجع</label>
                    <input id="default_return_fee" name="default_return_fee" type="number" min="0" step="1"
                           class="field-input text-left" dir="ltr" value="{{ old('default_return_fee', 2500) }}">
                </div>
            </div>
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الاشتراك</h2>
            <div class="space-y-4">
                <div>
                    <label class="field-label" for="status">حالة البداية</label>
                    <select id="status" name="status" class="field-input" required>
                        <option value="trial" @selected(old('status', 'trial') === 'trial')>تجريبية (14 يوماً)</option>
                        <option value="active" @selected(old('status') === 'active')>مفعّلة فوراً</option>
                    </select>
                </div>

                <div>
                    <label class="field-label" for="plan_id">الباقة</label>
                    <select id="plan_id" name="plan_id" class="field-input">
                        <option value="">بلا اشتراك الآن</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected((int) old('plan_id') === $plan->id)>
                                {{ $plan->name }} — {{ number_format($plan->price_monthly) }} د.ع/شهر
                            </option>
                        @endforeach
                    </select>
                    @error('plan_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="billing_cycle">دورة الفوترة</label>
                    <select id="billing_cycle" name="billing_cycle" class="field-input">
                        <option value="monthly" @selected(old('billing_cycle', 'monthly') === 'monthly')>شهرية</option>
                        <option value="yearly" @selected(old('billing_cycle') === 'yearly')>سنوية</option>
                    </select>
                </div>

                <div class="rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600 ring-1 ring-ink-200">
                    سعر الباقة يُجمَّد في الاشتراك، فتعديل الباقة لاحقاً لا يمسّ هذه الشركة.
                </div>
            </div>
        </section>

        <button type="submit" class="btn-primary w-full">سجّل الشركة</button>
    </div>
</form>
@endsection

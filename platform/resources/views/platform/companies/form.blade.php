@extends('layouts.platform')
@section('title', 'تسجيل شركة')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="page-title">تسجيل شركة جديدة</h1>
        <p class="mt-1 text-sm text-ink-500">
            يُنشأ نظام كامل: فرع، مركز فرز، تسعيرة توصيل افتراضية، وحساب صاحب الشركة.
        </p>
    </div>
    <a href="{{ route('admin.companies.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ route('admin.companies.store') }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf

    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الشركة</h2>
            @include('platform.companies._fields')
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
                    <label class="field-label" for="owner_username">اسم المستخدم</label>
                    <input id="owner_username" name="owner_username" class="field-input text-left" dir="ltr"
                           autocomplete="off" autocapitalize="none" spellcheck="false"
                           placeholder="فارغاً: رقم هاتفه" value="{{ old('owner_username') }}">
                    @error('owner_username') <p class="field-error">{{ $message }}</p> @enderror
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
                    وبعد التسجيل يُضاف الاشتراك أو يُغيَّر من «الاشتراكات».
                </div>
            </div>
        </section>

        {{-- المنصّة وسيطٌ يبيع النظام: أسعار التوصيل بين الشركة وتجّارها، لا تُضبط من هنا --}}
        <p class="rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600 ring-1 ring-ink-200">
            أسعار التوصيل شأن الشركة مع تجّارها: تبدأ بتسعيرةٍ افتراضية، التوصيل بـ
            <span class="num">5,000</span> د.ع والراجع بـ <span class="num">2,500</span>،
            وتضبطها من «التسعيرات» في نظامها.
        </p>

        <button type="submit" class="btn-primary w-full">سجّل الشركة</button>
    </div>
</form>
@endsection

@extends('layouts.app')
@section('title', $courier->exists ? 'تعديل مندوب' : 'مندوب جديد')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold">{{ $courier->exists ? 'تعديل ' . $courier->name : 'مندوب جديد' }}</h1>
        <p class="mt-1 text-sm text-ink-500">
            {{ $courier->exists ? 'الرمز ' . $courier->code : 'الرمز يُولَّد تلقائياً عند الحفظ.' }}
        </p>
    </div>
    <a href="{{ route('couriers.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST"
      action="{{ $courier->exists ? route('couriers.update', $courier) : route('couriers.store') }}"
      class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf
    @if ($courier->exists) @method('PUT') @endif

    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">البيانات الشخصية</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="name">الاسم <span class="text-red-500">*</span></label>
                    <input id="name" name="name" class="field-input" required value="{{ old('name', $courier->name) }}">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="phone">الهاتف <span class="text-red-500">*</span></label>
                    <input id="phone" name="phone" class="field-input text-left" dir="ltr" inputmode="numeric"
                           placeholder="07xxxxxxxxx" required value="{{ old('phone', $courier->phone) }}">
                    @error('phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="national_id">رقم الهوية</label>
                    <input id="national_id" name="national_id" class="field-input text-left" dir="ltr"
                           value="{{ old('national_id', $courier->national_id) }}">
                </div>
                <div>
                    <label class="field-label" for="branch_id">الفرع</label>
                    <select id="branch_id" name="branch_id" class="field-input">
                        <option value="">بلا فرع</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}"
                                    @selected((int) old('branch_id', $courier->branch_id) === $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الوظيفة</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="field-label" for="type">النوع <span class="text-red-500">*</span></label>
                    <select id="type" name="type" class="field-input" required>
                        @foreach (['delivery' => 'توصيل فقط', 'pickup' => 'استلام فقط', 'both' => 'الاثنان'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('type', $courier->type ?? 'delivery') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-ink-500">مندوب الاستلام لا يُسنَد إليه توصيل والعكس.</p>
                    @error('type') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="vehicle_type">وسيلة النقل</label>
                    <select id="vehicle_type" name="vehicle_type" class="field-input" required>
                        @foreach (['motorcycle' => 'دراجة نارية', 'car' => 'سيارة', 'van' => 'فان',
                                   'truck' => 'شاحنة', 'on_foot' => 'على الأقدام'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('vehicle_type', $courier->vehicle_type ?? 'motorcycle') === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="vehicle_number">رقم المركبة</label>
                    <input id="vehicle_number" name="vehicle_number" class="field-input"
                           value="{{ old('vehicle_number', $courier->vehicle_number) }}">
                </div>
            </div>

            <div class="mt-5">
                <span class="field-label">مناطق التغطية</span>
                <p class="mb-2 text-xs text-ink-500">تُستخدم في اقتراح المندوب عند التوزيع اليومي.</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($governorates as $gov)
                        <label class="flex items-center gap-2 rounded-lg border border-ink-200 px-3 py-2 text-sm">
                            <input type="checkbox" name="zones[]" value="{{ $gov->id }}"
                                   @checked(in_array($gov->id, old('zones', $zones) ?? []))
                                   class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                            {{ $gov->name_ar }}
                        </label>
                    @endforeach
                </div>
            </div>
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">العمولة والسقف</h2>
            <div class="space-y-4">
                @foreach ([
                    ['commission_per_delivery', 'عمولة التوصيل'],
                    ['commission_per_pickup', 'عمولة الاستلام'],
                    ['commission_per_return', 'عمولة الإرجاع'],
                ] as [$field, $label])
                    <div>
                        <label class="field-label" for="{{ $field }}">{{ $label }}</label>
                        <div class="relative">
                            <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="1"
                                   class="field-input pe-12 text-left" dir="ltr"
                                   value="{{ old($field, $courier->$field) }}">
                            <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                        </div>
                        @error($field) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div>
                    <label class="field-label" for="cash_limit">سقف النقد بيده</label>
                    <div class="relative">
                        <input id="cash_limit" name="cash_limit" type="number" min="0" step="1"
                               class="field-input pe-12 text-left" dir="ltr"
                               value="{{ old('cash_limit', $courier->cash_limit ?? 0) }}">
                        <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">صفر = بلا سقف. تجاوزه يُنبَّه في شاشة النقد.</p>
                </div>

                <div>
                    <label class="field-label" for="status">الحالة</label>
                    <select id="status" name="status" class="field-input" required>
                        @foreach (['active' => 'مفعّل', 'suspended' => 'موقوف', 'inactive' => 'غير نشط'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('status', $courier->status ?? 'active') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        @unless ($courier->exists)
            <section class="card p-5">
                <h2 class="mb-4 text-sm font-bold">حساب الدخول</h2>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="create_login" value="1" @checked(old('create_login'))
                           class="mt-0.5 rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500"
                           data-toggle="courier-login-fields">
                    <span>
                        أنشئ حساباً له على تطبيق المندوبين
                        <span class="mt-0.5 block text-xs text-ink-500">يدخل برقم هاتفه ويرى شحناته فقط.</span>
                    </span>
                </label>

                <div class="mt-4" id="courier-login-fields" hidden>
                    <label class="field-label" for="password">كلمة المرور</label>
                    <input id="password" name="password" type="text" class="field-input text-left" dir="ltr">
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </section>
        @endunless

        <button type="submit" class="btn-primary w-full">
            {{ $courier->exists ? 'حفظ التعديلات' : 'إضافة المندوب' }}
        </button>
    </div>
</form>
@endsection

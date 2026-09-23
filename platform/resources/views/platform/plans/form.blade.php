@extends('layouts.platform')
@section('title', $plan->exists ? 'تعديل باقة' : 'باقة جديدة')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <h1 class="page-title">{{ $plan->exists ? 'تعديل ' . $plan->name : 'باقة جديدة' }}</h1>
    <a href="{{ route('admin.plans.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}"
      class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf
    @if ($plan->exists) @method('PUT') @endif

    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">التعريف</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="name">الاسم <span class="text-red-500">*</span></label>
                    <input id="name" name="name" class="field-input" required value="{{ old('name', $plan->name) }}">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="code">الرمز <span class="text-red-500">*</span></label>
                    <input id="code" name="code" class="field-input text-left" dir="ltr" required
                           value="{{ old('code', $plan->code) }}" placeholder="growth">
                    @error('code') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="field-label" for="description">الوصف</label>
                    <textarea id="description" name="description" rows="2"
                              class="field-input">{{ old('description', $plan->description) }}</textarea>
                </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الحدود</h2>
            <p class="mb-4 text-xs text-ink-500">اتركه فارغاً ليكون بلا حد.</p>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach ([
                    ['max_branches', 'فروع'],
                    ['max_users', 'مستخدمون'],
                    ['max_couriers', 'مندوبون'],
                    ['max_merchants', 'تجّار'],
                    ['max_shipments_per_month', 'شحنات/شهر'],
                ] as [$field, $label])
                    <div>
                        <label class="field-label" for="{{ $field }}">{{ $label }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="number" min="1"
                               class="field-input text-left" dir="ltr" placeholder="بلا حد"
                               value="{{ old($field, $plan->$field) }}">
                        @error($field) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الوحدات المتاحة</h2>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($features as $key => $label)
                    <label class="flex items-center gap-2 rounded-lg border border-ink-200 px-3 py-2 text-sm">
                        <input type="checkbox" name="features[]" value="{{ $key }}"
                               @checked(in_array($key, old('features', []) ?: ($plan->exists ? collect($plan->features ?? [])->filter()->keys()->all() : []), true))
                               class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">السعر</h2>
            <div class="space-y-4">
                @foreach ([
                    ['price_monthly', 'شهري'],
                    ['price_yearly', 'سنوي'],
                    ['commission_per_shipment', 'عمولة لكل شحنة'],
                ] as [$field, $label])
                    <div>
                        <label class="field-label" for="{{ $field }}">{{ $label }}</label>
                        <div class="relative">
                            <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="1"
                                   class="field-input ps-12 text-left" dir="ltr" required
                                   value="{{ old($field, $plan->$field ?? 0) }}">
                            <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                        </div>
                        @error($field) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div>
                    <label class="field-label" for="commission_percent">نسبة من قيمة الشحنة (%)</label>
                    <input id="commission_percent" name="commission_percent" type="number" min="0" max="100" step="0.1"
                           class="field-input text-left" dir="ltr" required
                           value="{{ old('commission_percent', $plan->commission_percent ?? 0) }}">
                    @error('commission_percent') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="sort_order">الترتيب</label>
                    <input id="sort_order" name="sort_order" type="number" min="0"
                           class="field-input text-left" dir="ltr"
                           value="{{ old('sort_order', $plan->sort_order ?? 0) }}">
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $plan->exists ? $plan->is_active : true))
                           class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                    متاحة للاشتراك
                </label>
            </div>
        </section>

        <button type="submit" class="btn-primary w-full">{{ $plan->exists ? 'حفظ' : 'أضف الباقة' }}</button>
    </div>
</form>
@endsection

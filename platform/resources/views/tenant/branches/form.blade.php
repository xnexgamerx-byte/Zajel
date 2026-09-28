@extends('layouts.app')
@section('title', $branch->exists ? 'تعديل فرع' : 'فرع جديد')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <h1 class="page-title">{{ $branch->exists ? 'تعديل ' . $branch->name : 'فرع جديد' }}</h1>
    <a href="{{ route('branches.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ $branch->exists ? route('branches.update', $branch) : route('branches.store') }}"
      class="card max-w-2xl space-y-4 p-5">
    @csrf
    @if ($branch->exists) @method('PUT') @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="field-label" for="name">الاسم <span class="text-red-500">*</span></label>
            <input id="name" name="name" class="field-input" required value="{{ old('name', $branch->name) }}">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="code">الرمز <span class="text-red-500">*</span></label>
            <input id="code" name="code" class="field-input text-left" dir="ltr" required
                   placeholder="BSR" value="{{ old('code', $branch->code) }}">
            @error('code') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="governorate_id">المحافظة</label>
            <select id="governorate_id" name="governorate_id" class="field-input">
                <option value="">اختر</option>
                @foreach ($governorates as $gov)
                    <option value="{{ $gov->id }}"
                            @selected((int) old('governorate_id', $branch->governorate_id) === $gov->id)>
                        {{ $gov->name_ar }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label" for="city_id">المنطقة</label>
            <select id="city_id" name="city_id" class="field-input" data-searchable
                    data-old="{{ old('city_id', $branch->city_id) }}">
                <option value="">اختر المحافظة أولاً</option>
            </select>
        </div>
        <div>
            <label class="field-label" for="phone">الهاتف</label>
            <input id="phone" name="phone" class="field-input text-left" dir="ltr"
                   placeholder="07xxxxxxxxx" value="{{ old('phone', $branch->phone) }}">
            @error('phone') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="address">العنوان</label>
            <input id="address" name="address" class="field-input" value="{{ old('address', $branch->address) }}">
        </div>
    </div>

    {{-- تسعيرة الفرع: يختارها الفرع الرئيسي، وتسري على تجّار الفرع ما لم تكن للتاجر تسعيرته --}}
    <div>
        <label class="field-label" for="price_list_id">تسعيرة الفرع</label>
        <select id="price_list_id" name="price_list_id" class="field-input">
            <option value="">افتراضية الشركة</option>
            @foreach ($priceLists as $list)
                @continue($list->is_default)
                <option value="{{ $list->id }}" @selected((int) old('price_list_id', $branch->price_list_id) === $list->id)>{{ $list->name }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-ink-500">
            تسري على تجّار الفرع، والفرع يراها ولا يعدّلها. أنشئ تسعيرةً من «التسعيرات» ثم اخترها هنا.
        </p>
        @error('price_list_id') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-wrap gap-5">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_main" value="1" @checked(old('is_main', $branch->is_main))
                   class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
            الفرع الرئيسي
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $branch->exists ? $branch->is_active : true))
                   class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
            مفعّل
        </label>
    </div>
    @error('is_main') <p class="field-error">{{ $message }}</p> @enderror

    {{-- حساب دخول الفرع: «صاحب الفرع» يعمل بالنظام كلّه في فرعه وحده --}}
    <fieldset class="rounded-2xl border border-ink-100 p-4">
        <legend class="px-1 text-sm font-bold">حساب دخول الفرع</legend>

        @if ($owners->isNotEmpty())
            <ul class="mb-3 space-y-1 text-sm">
                @foreach ($owners as $owner)
                    <li class="flex flex-wrap items-center justify-between gap-2">
                        <span>{{ $owner->name }} · <span class="font-mono" dir="ltr">{{ $owner->username }}</span>
                            @unless ($owner->is_active) <span class="text-xs text-ink-500">(موقوف)</span> @endunless</span>
                        <a href="{{ route('users.edit', $owner) }}" class="text-xs font-semibold text-[var(--brand)] hover:underline">تغيير كلمة المرور</a>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="mb-3 text-xs text-ink-500">
            «صاحب الفرع» يدخل بهذا الاسم ويعمل بكل الصلاحيات في فرعه وحده: موظّفوه ومناديبه وتجّاره وشحناته
            وماله. لا يرى الفروع الأخرى، ولا يغيّر التسعيرة ولا إعدادات الشركة.
            {{ $owners->isNotEmpty() ? 'اكتب كلمة مرورٍ لتضيف حساباً آخر.' : 'اتركه فارغاً إن لم تُرد حساباً الآن.' }}
        </p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="field-label" for="account_name">اسم صاحب الفرع</label>
                <input id="account_name" name="account_name" class="field-input" value="{{ old('account_name') }}"
                       placeholder="صاحب {{ $branch->name ?: 'الفرع' }}">
                @error('account_name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="account_phone">هاتفه</label>
                <input id="account_phone" name="account_phone" class="field-input text-left" dir="ltr"
                       placeholder="07xxxxxxxxx" value="{{ old('account_phone') }}">
                @error('account_phone') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="account_username">اسم المستخدم</label>
                <input id="account_username" name="account_username" class="field-input text-left" dir="ltr"
                       autocomplete="off" value="{{ old('account_username') }}" placeholder="basra">
                <p class="mt-1 text-xs text-ink-500">فارغاً: يدخل برقم هاتفه.</p>
                @error('account_username') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="account_password">كلمة المرور</label>
                <input id="account_password" name="account_password" type="password" class="field-input text-left" dir="ltr"
                       autocomplete="new-password">
                @error('account_password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </fieldset>

    <button type="submit" class="btn-primary w-full sm:w-auto">
        {{ $branch->exists ? 'حفظ' : 'أضف الفرع' }}
    </button>
</form>

@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($g) => $g->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
@endsection

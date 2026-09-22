@extends('layouts.app')
@section('title', $branch->exists ? 'تعديل فرع' : 'فرع جديد')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <h1 class="text-xl font-bold">{{ $branch->exists ? 'تعديل ' . $branch->name : 'فرع جديد' }}</h1>
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
            <select id="city_id" name="city_id" class="field-input"
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

    <div class="flex flex-wrap gap-5">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_main" value="1" @checked(old('is_main', $branch->is_main))
                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            الفرع الرئيسي
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $branch->exists ? $branch->is_active : true))
                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            مفعّل
        </label>
    </div>
    @error('is_main') <p class="field-error">{{ $message }}</p> @enderror

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

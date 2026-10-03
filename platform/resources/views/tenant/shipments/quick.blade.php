@extends('layouts.app')
@section('title', 'إدخال سريع')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">إدخال سريع</h1>
        <p class="mt-1 text-sm text-ink-500">
            حتى ثلاثين شحنة في جدولٍ واحد. المبلغ بالألف: <span class="num">25</span> خمسةٌ وعشرون ألفاً، و<span class="num">0</span> للمدفوع مسبقاً.
            والجدول يُحفظ كلّه أو لا يُحفظ منه شيء.
        </p>
    </div>
    <a href="{{ route('shipments.create') }}" class="btn-ghost">شحنة بالتفصيل</a>
</div>

<nav class="tab-nav mb-4" aria-label="طريقة الإدخال">
    @foreach (['merchant' => 'على مستوى التاجر', 'governorate' => 'على مستوى المحافظة'] as $key => $label)
        <a href="{{ route('shipments.quick', ['mode' => $key]) }}" @class(['tab-link', 'tab-link-active' => $mode === $key])
           @if ($mode === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if (session('created_ids'))
    <div class="card mb-4 flex flex-wrap items-center gap-3 border-ok-200 bg-ok-50 p-4 text-sm text-ok-700">
        <span class="font-semibold">حُفظت. ألصِق وصولاتها قبل أن تخرج:</span>
        <a href="{{ route('shipments.labels', ['ids' => session('created_ids')]) }}" target="_blank" rel="noopener" class="btn-ghost py-1">
            <x-icon name="printer" class="size-5"/> اطبع وصولاتها
        </a>
    </div>
@endif

<form method="POST" action="{{ route('shipments.quick.store') }}" data-quick-form data-max="{{ \App\Http\Controllers\Tenant\QuickEntryController::MAX_ROWS }}">
    @csrf
    <input type="hidden" name="mode" value="{{ $mode }}">

    {{-- لا «من يدفع الأجرة»: على التاجر كما في نموذج الشحنة (docs/plan/27) --}}
    <section class="card mb-4 grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
        @if ($mode === 'merchant')
            <div>
                <label class="field-label" for="merchant_id">التاجر <span class="text-red-500">*</span></label>
                <select id="merchant_id" name="merchant_id" class="field-input" data-searchable required>
                    <option value="">اختر التاجر</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected((int) old('merchant_id') === $merchant->id)>
                            {{ $merchant->business_name }} — {{ $merchant->phone }}
                        </option>
                    @endforeach
                </select>
                @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @else
            <div>
                <label class="field-label" for="governorate_id">المحافظة <span class="text-red-500">*</span></label>
                <select id="governorate_id" name="governorate_id" class="field-input" required data-quick-header-governorate>
                    @foreach ($governorates as $gov)
                        <option value="{{ $gov->id }}" @selected((int) old('governorate_id', $baghdad) === $gov->id)>{{ $gov->name_ar }}</option>
                    @endforeach
                </select>
                @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @endif

        @can('shipments.assign')
            <div>
                <label class="field-label" for="courier_id">تخرج مع مندوب توصيل (اختياري)</label>
                <select id="courier_id" name="courier_id" class="field-input" data-searchable>
                    <option value="">تبقى عند التاجر حتى تُستلم</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}" @selected((int) old('courier_id') === $courier->id)>{{ $courier->name }}</option>
                    @endforeach
                </select>
                @error('courier_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @endcan
    </section>

    @error('rows') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm font-medium text-bad-700">{{ $message }}</div> @enderror

    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        @if ($mode === 'governorate')<th>التاجر *</th>@endif
                        <th>المبلغ (بالألف) *</th>
                        <th>هاتف المستلم *</th>
                        <th>اسم المستلم</th>
                        @if ($mode === 'merchant')<th>المحافظة *</th>@endif
                        <th>المنطقة *</th>
                        <th>أقرب نقطة دالّة</th>
                        <th>رقم الوصل</th>
                        <th>ملاحظات</th>
                        <th class="whitespace-nowrap">استبدال</th>
                        <th class="whitespace-nowrap" title="مدفوع التوصيل مقدّماً: لا تُخصم أجرته من المبلغ — ولمن يُحاسَب مقدّماً تكون كذلك وحدها">مقدّماً</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody data-quick-rows>
                    @for ($i = 0; $i < $rows; $i++)
                        @include('tenant.shipments._quick_row', ['i' => $i])
                    @endfor
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 px-5 py-3">
            <button type="button" class="btn-ghost" data-quick-add>+ خمسة صفوف</button>
            <span class="text-sm text-ink-500"><span class="num" data-quick-count>{{ $rows }}</span> صفّاً من ثلاثين · الفارغ يُترك</span>
            <button type="submit" class="btn-primary ms-auto">احفظ الشحنات</button>
        </div>
    </section>
</form>

<template data-quick-template>
    @include('tenant.shipments._quick_row', ['i' => '__I__'])
</template>

@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($group) => $group->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="quick-cities">@json($citiesByGovernorate)</script>
@endsection

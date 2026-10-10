@extends('layouts.app')
@section('title', 'توزيع بالمسح')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">توزيع بالمسح</h1>
        <p class="mt-1 text-sm text-ink-500">
            امسح وصولات المحافظة فتدخل الجدول مجمّعةً بمناطقها، ولكلّ منطقةٍ مندوبها. المسح لا يغيّر شيئاً حتى «وزّع».
        </p>
    </div>
    {{-- لكلّ محافظةٍ توزيعها: وصلٌ وجهته غيرها لا يدخل الجدول --}}
    <form method="GET" class="flex items-end gap-2">
        <div>
            <label class="field-label" for="governorate">المحافظة</label>
            <select id="governorate" name="governorate" class="field-input min-w-48" data-submit-on-change>
                @foreach ($governorates as $g)
                    <option value="{{ $g->id }}" @selected($governorate?->id === $g->id)>{{ $g->name_ar }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="btn-ghost">افتح</button></noscript>
    </form>
</div>

@if ($governorate)
<div data-distribute data-lookup="{{ route('shipments.distribute.lookup') }}" data-governorate="{{ $governorate->id }}"
     class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <section class="card p-5">
            <label class="field-label" for="scan-number">رقم الوصل — {{ $governorate->name_ar }}</label>
            <div class="flex gap-2">
                <input id="scan-number" class="field-input text-lg" autocomplete="off" autofocus inputmode="text"
                       placeholder="امسح أو اكتب ثم Enter" data-scan-input>
                <button type="button" class="btn-primary" data-scan-add>أضِف</button>
            </div>
            <p class="mt-2 min-h-5 text-sm" role="status" aria-live="polite" data-scan-message></p>
        </section>

        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-3">
                <div class="text-sm">
                    الوصولات: <span class="num font-semibold" data-scan-count>0</span>
                    · المبالغ <span class="num font-semibold" data-scan-total>0</span> د.ع
                </div>
                <button type="button" class="text-sm font-semibold text-bad-700 hover:underline" data-scan-clear>إفراغ الجدول</button>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>رقم الوصل</th>
                            <th>المنطقة</th>
                            <th>صاحب المحل</th>
                            <th>المرحلة</th>
                            <th>المبلغ</th>
                            <th>الهاتف</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody data-scan-rows>
                        <tr data-scan-empty>
                            <td colspan="8" class="px-4 py-12 text-center text-ink-500">امسح أوّل وصل.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <form method="POST" action="{{ route('shipments.distribute.store') }}" class="card h-fit space-y-3 p-5" data-distribute-form>
        @csrf
        <input type="hidden" name="governorate_id" value="{{ $governorate->id }}">
        <h2 class="card-title">المناطق ومناديبها</h2>
        <p class="card-hint">
            كلّ منطقةٍ تظهر هنا حين يُمسح أوّل وصلٍ لها، ومعها مندوبها من «مناطق المناديب» — وتبدّله من القائمة.
        </p>
        <div class="space-y-3" data-areas>
            <p class="text-sm text-ink-500" data-areas-empty>لا مناطق بعد.</p>
        </div>
        {{-- قائمة المناديب تُنسخ لكلّ منطقة: من يغطّي المحافظة أوّلاً --}}
        <template data-courier-options>
            <option value="">اختر المندوب</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}">{{ $courier->name }}{{ $courier->covers ? '' : ' (خارج المحافظة)' }}</option>
            @endforeach
        </template>
        @error('courier') <p class="field-error">{{ $message }}</p> @enderror
        <button type="submit" class="btn-primary w-full" disabled>وزّع على المناديب</button>
        <p class="field-hint">ما لم يُستلم بعد يُسجَّل استلامه ثم يخرج؛ والمسلَّمة والملغاة وما مع مندوبٍ تُتخطّى ويُقال لماذا.</p>
    </form>
</div>
@else
    <div class="card p-8 text-center text-ink-500">لا محافظات مفعّلة لشركتك.</div>
@endif
@endsection

@extends('layouts.app')
@section('title', 'استلام وصولات في كل المراحل')

@section('content')
<div class="mb-5">
    <h1 class="page-title">استلام وصولات في كل المراحل وإسنادها</h1>
    <p class="mt-1 text-sm text-ink-500">
        امسح الوصولات فتدخل الجدول، ثم فعلٌ واحد لها كلّها. المسح لا يغيّر شيئاً حتى الحفظ.
    </p>
</div>

<div data-scan-table data-lookup="{{ route('shipments.scan.lookup') }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <section class="card p-5">
            <label class="field-label" for="scan-number">رقم الوصل</label>
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
                            <th>صاحب المحل</th>
                            <th>المرحلة</th>
                            <th>المبلغ</th>
                            <th>الوجهة</th>
                            <th>أُنشئ في فرع</th>
                            <th>الهاتف</th>
                            <th>الكيس</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody data-scan-rows>
                        <tr data-scan-empty>
                            <td colspan="10" class="px-4 py-12 text-center text-ink-500">امسح أوّل وصل.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="space-y-4">
        @can('shipments.status')
            <form method="POST" action="{{ route('shipments.scan.receive') }}" class="card space-y-3 p-5" data-scan-form>
                @csrf
                <h2 class="card-title">استلام في المخزن</h2>
                <p class="card-hint">
                    تدخل {{ $hub ? '«'.$hub->name.'»' : 'المخزن' }} أيّاً كانت مرحلتها: من عند التاجر، أو مع مندوب
                    الاستلام، أو بين فرعين، أو عادت مع مندوب التوصيل؛ والراجع يُستلم من المندوب.
                    والمسلَّمة وما في المخزن سلفاً تُتخطّى بسببها.
                </p>
                <button type="submit" class="btn-primary w-full" disabled>استلم الكلّ في المخزن</button>
            </form>
        @endcan

        @can('shipments.assign')
            <form method="POST" action="{{ route('shipments.assign') }}" class="card space-y-3 p-5" data-scan-form>
                @csrf
                <h2 class="card-title">مندوب توصيل للكل</h2>
                <label class="field-label" for="scan-courier">المندوب</label>
                <select id="scan-courier" name="courier_id" class="field-input" data-searchable required>
                    <option value="">اختر المندوب</option>
                    @foreach ($couriers as $courier)
                        @php $covers = $courier->zones->pluck('governorate.name_ar')->filter()->unique(); @endphp
                        <option value="{{ $courier->id }}">
                            {{ $courier->name }}{{ $covers->isNotEmpty() ? ' — '.$covers->take(3)->implode('، ') : '' }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="btn-primary w-full" disabled>إسناد وإخراج للتوصيل</button>
                <p class="field-hint">ما لم يُستلم بعد يُسجَّل استلامه ثم يخرج؛ والمسلَّمة والملغاة وما مع مندوبٍ تُتخطّى ويُقال لماذا.</p>
            </form>
        @endcan
    </div>
</div>
@endsection

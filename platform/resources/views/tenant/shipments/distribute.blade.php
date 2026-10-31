@extends('layouts.app')
@section('title', 'توزيع بالمسح')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">توزيع بالمسح</h1>
        <p class="mt-1 text-sm text-ink-500">
            امسح الوصولات فتدخل الجدول مجمّعةً بمحافظاتها ومناطقها، ولكلّ منطقةٍ مندوبها. المسح لا يغيّر شيئاً حتى «وزّع».
        </p>
    </div>
    {{-- المحافظات (docs/plan/98): كلّها، أو ما يُختار منها. وصلٌ وجهته خارجها لا يدخل الجدول --}}
    <form method="GET" class="flex items-end gap-2">
        <input type="hidden" name="mode" value="{{ $mode }}">
        <div>
            <span class="field-label">المحافظات</span>
            <details class="relative" data-governorate-picker>
                <summary class="field-input flex min-w-56 cursor-pointer list-none items-center justify-between gap-3">
                    <span class="truncate">{{ $scope }}</span>
                    <span aria-hidden="true" class="text-ink-400">▾</span>
                </summary>
                <div class="absolute end-0 z-20 mt-1 w-72 rounded-2xl border border-ink-200 bg-white p-3 shadow-lg">
                    <p class="mb-2 text-xs text-ink-500">لا تختر شيئاً لكلّ المحافظات، أو علّم ما تريد منها.</p>
                    <div class="max-h-72 space-y-1 overflow-y-auto">
                        @foreach ($governorates as $g)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-sm hover:bg-ink-50">
                                <input type="checkbox" name="governorates[]" value="{{ $g->id }}" class="accent-[var(--brand)]"
                                       @checked($chosen->contains('id', $g->id))>
                                {{ $g->name_ar }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="btn-primary flex-1">اعرض</button>
                        <a href="{{ route('shipments.distribute', ['mode' => $mode]) }}" class="btn-ghost">كل المحافظات</a>
                    </div>
                </div>
            </details>
        </div>
    </form>
</div>

@if ($governorates->isNotEmpty())
<div data-distribute data-lookup="{{ route('shipments.distribute.lookup') }}"
     data-governorates="{{ $chosen->pluck('id')->implode(',') }}" data-single="{{ $chosen->count() === 1 ? '1' : '' }}"
     class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <section class="card p-5">
            <label class="field-label" for="scan-number">رقم الوصل — {{ $scope }}</label>
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
        @foreach ($chosen as $g)
            <input type="hidden" name="governorates[]" value="{{ $g->id }}">
        @endforeach
        <fieldset class="space-y-2">
            <legend class="field-label">نوع التوزيع</legend>
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <label class="flex cursor-pointer items-center gap-2">
                    <input type="radio" name="mode" value="own" class="accent-[var(--brand)]" data-distribute-mode @checked($mode === 'own')>
                    مندوبو الشركة
                </label>
                <label class="flex cursor-pointer items-center gap-2">
                    <input type="radio" name="mode" value="partner" class="accent-[var(--brand)]" data-distribute-mode @checked($mode === 'partner')>
                    توزيع إلى مندوب شركة أخرى
                </label>
            </div>
        </fieldset>
        <h2 class="card-title">المناطق ومناديبها</h2>
        <p class="card-hint" data-hint="own" @if ($mode === 'partner') hidden @endif>
            كلّ منطقةٍ تظهر هنا حين يُمسح أوّل وصلٍ لها، ومعها مندوبها من «مناطق المناديب» — وتبدّله من القائمة.
        </p>
        <p class="card-hint" data-hint="partner" @if ($mode !== 'partner') hidden @endif>
            اختر لكلّ منطقةٍ مندوب الشركة الأخرى. الشحنة تُسجَّل بالطريق إليه، وشركته تُخرجها من عندها.
            @if ($partners->isEmpty()) <span class="font-semibold text-bad-700">لا مندوب شركةٍ أخرى مربوط بعد: أضفه من المناديب بخيار «شركة وسيطة».</span> @endif
        </p>
        {{-- مندوبٌ واحد لكلّ المناطق (docs/plan/98): الشركة الوسيطة تأخذ وصولات محافظاتٍ كثيرة --}}
        <div>
            <label class="field-label" for="all-courier">مندوبٌ واحد لكلّ المناطق</label>
            <select id="all-courier" class="field-input" data-all-courier></select>
        </div>
        <div class="space-y-3" data-areas>
            <p class="text-sm text-ink-500" data-areas-empty>لا مناطق بعد.</p>
        </div>
        {{-- قائمة المناديب تُنسخ لكلّ منطقة: من يغطّي المحافظات المختارة أوّلاً --}}
        <template data-courier-options>
            <option value="">اختر المندوب</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}">{{ $courier->name }}{{ $courier->covers || $chosen->isEmpty() ? '' : ' (خارج المحافظات المختارة)' }}</option>
            @endforeach
        </template>
        <template data-partner-options>
            <option value="">اختر مندوب الشركة الأخرى</option>
            @foreach ($partners as $partner)
                <option value="{{ $partner->id }}">{{ $partner->name }}</option>
            @endforeach
        </template>
        @error('courier') <p class="field-error">{{ $message }}</p> @enderror
        <button type="submit" class="btn-primary w-full" disabled>وزّع على المناديب</button>
        <p class="field-hint" data-hint="own" @if ($mode === 'partner') hidden @endif>ما لم يُستلم بعد يُسجَّل استلامه ثم يخرج؛ والمسلَّمة والملغاة وما مع مندوبٍ تُتخطّى ويُقال لماذا.</p>
        <p class="field-hint" data-hint="partner" @if ($mode !== 'partner') hidden @endif>تُسجَّل بالطريق إلى الشركة الأخرى ولا تُعاد إسنادها من عندنا؛ والمسلَّمة والملغاة وما مع مندوبٍ تُتخطّى.</p>
    </form>
</div>
@else
    <div class="card p-8 text-center text-ink-500">لا محافظات مفعّلة لشركتك.</div>
@endif
@endsection

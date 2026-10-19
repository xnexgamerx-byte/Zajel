@extends('layouts.app')
@section('title', 'الإعدادات المالية')

{{-- الإعدادات المالية (docs/plan/68 §١٣): لصاحب الشركة، وكل تغييرٍ في سجلّ التدقيق --}}
@php
    $groups = [
        'الاحتياطي والسحب' => [
            'reserve_fixed'            => ['احتياطي تشغيلي ثابت (د.ع)', 'يُحجز من النقد قبل «المتاح للسحب»: الأكبر منه ومن أيام المصروف.'],
            'reserve_days'             => ['أو أيامٌ من متوسّط المصروف اليومي', null],
            'withdrawal_approval_over' => ['سحب الأرباح فوق هذا المبلغ يعتمده غيرُ طالبه (د.ع)', 'فارغاً أو صفراً: يعتمد صاحب الصلاحية سحبه بنفسه.'],
        ],
        'التجّار والعهدة والإقفال' => [
            'merchant_cycle_days' => ['دورة تسوية التجّار (أيام)', 'تفصل مستحقّات التجّار الحالية عن المتأخّرة، ويُنبَّه عمّا تجاوزها.'],
            'custody_age_days'    => ['عهدةٌ أقدم من هذا (أيام) قديمة', 'يُنبَّه عنها، ولا تُقفَل فترةٌ وفيها عهدةٌ أقدم منه.'],
        ],
        'عتبات التنبيهات المالية' => [
            'cash_handover_hours' => ['مهلة تسليم المندوب نقده (ساعات)', 'بعدها تحذير، وبعد ضعفها حرج.'],
            'batch_match_hours'   => ['مهلة مطابقة الوجبة بعد عدّ نقدها (ساعات)', null],
            'min_liquidity'       => ['الحدّ الأدنى للرصيد المتوقَّع (د.ع)', 'فارغاً: الاحتياطي التشغيلي.'],
            'cost_ratio_max'      => ['أعلى نسبةٍ للتكاليف من الإيراد (٪)', 'في آخر ٣٠ يوماً.'],
            'margin_min'          => ['أدنى هامش ربح (٪)', 'صفرٌ: يُنبَّه عند الخسارة وحدها.'],
        ],
    ];
@endphp

@section('content')
<div class="mb-5">
    <h1 class="page-title">الإعدادات المالية</h1>
    <p class="mt-1 max-w-3xl text-sm text-ink-500">
        ما يحكم المال في الشركة كلّها: من يعتمد ماذا، وما يُحجز قبل السحب، ومتى يُنبَّه. كل تغييرٍ يُكتب بقيمته قبل وبعد في سجلّ التدقيق.
    </p>
</div>

<form method="POST" action="{{ route('finance.settings.update') }}" class="space-y-5">
    @csrf
    @method('PUT')

    <section class="card p-5">
        <input type="hidden" name="dual_approval" value="0">
        <label class="flex items-start gap-3">
            <input type="checkbox" name="dual_approval" value="1" class="mt-1 size-4" @checked(old('dual_approval', $settings['dual_approval'] === '1'))>
            <span>
                <span class="card-title block">الاعتماد المزدوج</span>
                <span class="card-hint">من أنشأ وجبة التحصيل لا يعتمدها، ومن أعدّ إقفال الفترة لا يعتمده: يعتمدهما محاسبٌ آخر أو صاحب الشركة.</span>
            </span>
        </label>
    </section>

    @foreach ($groups as $title => $fields)
        <fieldset class="card p-5">
            <legend class="card-title px-1">{{ $title }}</legend>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($fields as $key => [$label, $hint])
                    <div>
                        <label class="field-label" for="{{ $key }}">{{ $label }}</label>
                        <input id="{{ $key }}" type="number" name="{{ $key }}" step="1" class="field-input text-left" dir="ltr"
                               value="{{ old($key, $settings[$key]) }}">
                        @if ($hint)<p class="card-hint mt-1">{{ $hint }}</p>@endif
                        @error($key) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </fieldset>
    @endforeach

    <div class="flex justify-end">
        <button class="btn-primary">احفظ الإعدادات المالية</button>
    </div>
</form>
@endsection

@extends('layouts.app')
@section('title', 'التقارير')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">التقارير</h1>
    <p class="mt-1 text-sm text-ink-500">
        عشرة تقارير، كلٌّ منها يُجيب سؤالاً يُتّخذ بعده قرار.
    </p>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
    @foreach ([
        ['reports.returns', 'لماذا ترجع شحناتي؟', 'أسباب الرجوع مصنّفة، ومَن تتكرّر عنده.'],
        ['reports.couriers', 'أداء المندوبين', 'مَن يوصّل ومَن يُرجع، وكم بيد كلٍّ منهم.'],
        ['reports.merchants', 'أداء التجّار', 'حجم كل تاجر ونسبة راجعه.'],
        ['reports.governorates', 'الأداء بالمحافظات', 'أين ننجح وأين نفشل جغرافياً.'],
        ['reports.daily', 'الحركة اليومية', 'ما دخل وما خرج، يوماً بيوم.'],
        ['reports.profit', 'أرباح الشحنات', 'ما دخل من أجور وما خرج عمولات.'],
        ['reports.returns-money', 'مال الرواجع', 'ما أكسبنا الراجع بعد عمولته، وكم أجرةً معلّقة.'],
        ['reports.dormant', 'عملاء منقطعون', 'تاجرٌ توقّف عن الإرسال ولم يُعلن رحيله.'],
        ['reports.debtors', 'أرصدة مدينة', 'مالٌ لنا عند التجّار ديناً وعند المندوبين نقداً.'],
        ['reports.changes', 'تتبّع التغييرات', 'مَن غيّر ماذا ومتى — سجلٌّ لا يُعدَّل.'],
    ] as [$route, $title, $blurb])
        <a href="{{ route($route, $period->query()) }}"
           class="card p-5 transition hover:border-brand">
            <h2 class="font-bold text-ink-900">{{ $title }}</h2>
            <p class="mt-1 text-sm text-ink-500">{{ $blurb }}</p>
        </a>
    @endforeach
</div>
@endsection

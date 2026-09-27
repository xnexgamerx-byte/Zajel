@extends('layouts.app')
@section('title', 'التقارير')

@section('content')
<div class="mb-5">
    <h1 class="page-title">التقارير</h1>
    <p class="mt-1 text-sm text-ink-500">
        كلٌّ منها يُجيب سؤالاً يُتّخذ بعده قرار — ومعها ما في النظام المعتاد من تقارير.
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
        ['reports.stuck', 'المعلّقة في المراحل', 'ما لم تتحرّك مرحلته منذ ساعاتٍ تختارها، بمكانه الآن.'],
        ['reports.entries', 'عدد الشحنات المُدخلة', 'من أدخل الشحنات وبأيّ قناة وفي أيّ ساعة.'],
        ['reports.portal', 'ما رفعه التجّار من بواباتهم', 'ما أُنشئ من البوابة يوماً بيوم، وكم استُلم منه.'],
        ['reports.processing', 'المتابعة والمراجعة', 'من عالج المحاولات الفاشلة وبعد كم، ومن أجاز المعلَّق.'],
        ['reports.merchant-profit', 'الأرباح حسب التاجر', 'ما دخل من أجور كل تاجرٍ وما خرج عمولات.'],
        ['reports.courier-overcharge', 'حوسب المندوب بتكلفة أعلى', 'وصولاتٌ حصّة مندوبها أكبر من أجرتها.'],
        ['reports.special-prices', 'التجّار ذوو الأسعار الخاصّة', 'من على تسعيرةٍ خاصّة، وأسعارها.'],
        ['reports.unconfirmed', 'دفعات لم يؤكَّد استلامها', 'ما دُفع للتجّار ومناديب الاستلام ولم يؤكّدوه.'],
        ['money.position', 'الموقف المالي', 'ما عندنا وما لنا وما علينا — الآن، ولقطاته.'],
    ] as [$route, $title, $blurb])
        {{-- المالية منها لمن يرى أرباح الشركة وحده --}}
        @continue(in_array($route, ['reports.profit', 'reports.returns-money', 'reports.merchant-profit', 'reports.courier-overcharge'], true) && ! auth()->user()->can('reports.financial'))
        @continue($route === 'money.position' && ! auth()->user()->can('money.view'))
        <a href="{{ route($route, $period->query()) }}"
           class="card p-5 transition hover:border-brand">
            <h2 class="font-bold text-ink-900">{{ $title }}</h2>
            <p class="mt-1 text-sm text-ink-500">{{ $blurb }}</p>
        </a>
    @endforeach
</div>
@endsection

@extends('layouts.app')
@section('title', 'لوحة الأرباح')

@section('content')
@php
    $user = auth()->user();
    $wide = ! $user->isBranchLimited();
    // «تتبّع الرقم» يفتح دفتر القيود: من الفرع الرئيسي، وفي شركةٍ فيها المحاسبة
    $trace = $wide && \App\Support\FeatureGate::enabled('accounting');
    $dims = array_filter(['branch_id' => $scope['branch'], 'courier_id' => $scope['courier'], 'merchant_id' => $scope['merchant']]);
    $from = $period->from->toDateString();
    $to = $period->to->toDateString();
    // «تتبّع الرقم»: سطور الحساب في المدّة بأبعاد النطاق — من الفرع الرئيسي
    $ledger = fn (string $account, ?string $f = null, ?string $t = null) => $trace
        ? route('finance.ledger', ['account' => $account, 'from' => $f ?? $from, 'to' => $t ?? $to] + $dims) : null;
    $always = '2000-01-01';
    $today = now()->toDateString();
    $keep = request()->except(['page', 'rank', 'what']);
    $scopeName = match (true) {
        (bool) $scope['courier']  => 'المندوب '.($courierList->firstWhere('id', $scope['courier'])->name ?? ''),
        (bool) $scope['merchant'] => 'التاجر '.($merchantList->firstWhere('id', $scope['merchant'])->business_name ?? ''),
        (bool) $scope['branch']   => 'فرع '.($branchList->firstWhere('id', $scope['branch'])->name ?? $user->branch?->name ?? ''),
        default                   => 'الشركة كلّها',
    };
    $narrow = $scope['courier'] || $scope['merchant'];
@endphp

@include('tenant.profits._tabs', [
    'title' => 'لوحة الأرباح',
    'lead'  => 'من دفتر القيود: ما كُسب في المدّة، وما وصل نقده، وما لم يُحصَّل بعد ومكانه، وما يُسحب. كل رقمٍ بمعادلته وأجزائه تحت «كيف حُسب».',
])

{{-- الفلاتر: المدّة والفرع والمندوب والتاجر --}}
<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="range">المدّة</label>
        <select id="range" name="range" class="field-input">
            @foreach (['today' => 'اليوم', '7' => 'آخر ٧ أيام', '15' => 'آخر ١٥ يوماً', 'month' => 'هذا الشهر', 'custom' => 'فترة أحدّدها'] as $key => $label)
                <option value="{{ $key }}" @selected($range === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ $from }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ $to }}" class="field-input">
    </div>
    @if ($branchList->isNotEmpty())
        <div class="min-w-40">
            <label class="field-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id" class="field-input">
                <option value="">كل الفروع</option>
                @foreach ($branchList as $option)<option value="{{ $option->id }}" @selected($scope['branch'] === $option->id)>{{ $option->name }}</option>@endforeach
            </select>
        </div>
    @endif
    <div class="min-w-40">
        <label class="field-label" for="courier_id">المندوب</label>
        <select id="courier_id" name="courier_id" class="field-input">
            <option value="">كل المناديب</option>
            @foreach ($courierList as $option)<option value="{{ $option->id }}" @selected($scope['courier'] === $option->id)>{{ $option->name }}</option>@endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input">
            <option value="">كل التجّار</option>
            @foreach ($merchantList as $option)<option value="{{ $option->id }}" @selected($scope['merchant'] === $option->id)>{{ $option->business_name }}</option>@endforeach
        </select>
    </div>
    <button type="submit" class="btn-primary">عرض</button>
    <a href="{{ route('profits.index') }}" class="btn-ghost">مسح</a>
    <details class="relative ms-auto">
        <summary class="btn-ghost cursor-pointer list-none"><x-icon name="download" class="size-4"/> تصدير Excel</summary>
        <div class="absolute end-0 z-10 mt-1 w-56 rounded-xl border border-ink-100 bg-white p-2 text-sm shadow-lg">
            <a href="{{ route('profits.export', $keep + ['what' => 'cards']) }}" class="block rounded-lg px-3 py-2 hover:bg-ink-50">البطاقات بأجزائها</a>
            @if ($branches->isNotEmpty())<a href="{{ route('profits.export', $keep + ['what' => 'branches']) }}" class="block rounded-lg px-3 py-2 hover:bg-ink-50">مقارنة الفروع</a>@endif
            <a href="{{ route('profits.export', $keep + ['what' => 'couriers', 'rank' => $rank]) }}" class="block rounded-lg px-3 py-2 hover:bg-ink-50">المناديب</a>
        </div>
    </details>
    <p class="w-full text-xs text-ink-500">
        النطاق: <span class="font-semibold text-ink-700">{{ $scopeName }}</span> ·
        المدّة <span class="num">{{ $period->label() }}</span>، وتُقارن بالسابقة بطولها <span class="num">{{ $previous->label() }}</span>.
    </p>
</form>

@unless ($initialized)
    <div class="alert alert-warn mb-4">دفتر القيود يُبنى من تاريخ الشركة الآن (كل بضع دقائق): أرقام هذه اللوحة ناقصةٌ حتى يكتمل. تابع حاله في «ميزان المراجعة».</div>
@endunless

{{-- البطاقات التسع (docs/plan/68 §٥) --}}
<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
    @include('tenant.profits._card', [
        'letter' => 'أ', 'label' => 'الشحنات المسلَّمة', 'unit' => 'count', 'value' => $a['delivered'], 'prev' => $a['prev']['delivered'],
        'hint' => 'والراجع المغلق في المدّة '.number_format($a['returned']).' — رقمٌ تشغيليّ لا ماليّ.',
        'formula' => 'عدد الشحنات التي سُلِّمت في المدّة (والواصل الجزئي منها)، بفرع إنشائها أو مندوبها أو تاجرها.',
        'parts' => [
            ['label' => 'المسلَّمة', 'value' => $a['delivered'], 'href' => route('shipments.index', ['status' => 'delivered', 'stage_from' => $from, 'stage_to' => $to] + $dims)],
            ['label' => 'الراجع المغلق', 'value' => $a['returned'], 'href' => route('shipments.index', ['status' => 'returned', 'stage_from' => $from, 'stage_to' => $to] + $dims)],
        ],
    ])
    @include('tenant.profits._card', [
        'letter' => 'ب', 'label' => 'إيراد أجور التوصيل', 'value' => $b['total'], 'prev' => $b['prev']['total'], 'tone' => 'text-info-700',
        'hint' => 'ما احتفظت به الشركة من أجور ما أُغلق في المدّة — على فرع إنشاء الشحنة.',
        'formula' => 'دائن «إيراد أجور التوصيل» (4100) و«إيراد أجور الراجع» (4110) في المدّة. يُعترف به يوم تُغلق الشحنة، لا يوم الوجبة.',
        'parts' => [
            ['label' => 'أجور المسلَّم (4100)', 'value' => $b['delivery'], 'href' => $ledger(\App\Support\Finance\Accounts::DELIVERY_REVENUE)],
            ['label' => 'أجور الراجع (4110)', 'value' => $b['returns'], 'href' => $ledger(\App\Support\Finance\Accounts::RETURN_REVENUE)],
        ],
    ])
    @include('tenant.profits._card', [
        'letter' => 'ج', 'label' => 'التكاليف التشغيلية', 'value' => $c['total'], 'prev' => $c['prev']['total'], 'tone' => 'text-bad-700', 'inverse' => true,
        'hint' => 'العمولات والحصص مباشرة، والمصروفات بتاريخها دُفعت أم لم تُدفع، والعجز المشطوب.',
        'formula' => 'مدين 5100 + 5110 (مباشرة) + 5200 (مصروفات) + 5300 (عجز مشطوب) في المدّة.',
        'parts' => [
            ['label' => 'عمولات المناديب (5100)', 'value' => $c['commissions'], 'href' => $ledger(\App\Support\Finance\Accounts::COURIER_COMMISSIONS)],
            ['label' => 'حصص مندوبي الاستلام (5110)', 'value' => $c['pickup'], 'href' => $ledger(\App\Support\Finance\Accounts::PICKUP_SHARES)],
            ['label' => 'مصروفات تشغيلية (5200)', 'value' => $c['expenses'], 'href' => $ledger(\App\Support\Finance\Accounts::OPERATING_EXPENSES),
                'note' => $narrow ? 'المصروفات العامّة لا تُحمَّل على مندوبٍ أو تاجر.' : null],
            ['label' => 'عجز نقدي مشطوب (5300)', 'value' => $c['write_offs'], 'href' => $ledger(\App\Support\Finance\Accounts::WRITE_OFFS)],
        ],
    ])
    @include('tenant.profits._card', [
        'letter' => 'د', 'label' => 'صافي الربح المحاسبي', 'value' => $d['total'], 'prev' => $d['prev']['total'],
        'tone' => $d['total'] >= 0 ? 'text-profit-700' : 'text-bad-700',
        'hint' => 'ما كُسب في المدّة، قُبض أم لم يُقبض.'.($d['fees'] ? ' هامش الربح '.round($d['total'] / $d['fees'] * 100, 1).'٪.' : ''),
        'formula' => 'ب + الإيرادات الأخرى (4200: خصومات المناديب) + فروقات الجرد (4210) − ج.',
        'parts' => [
            ['label' => 'إيراد الأجور (ب)', 'value' => $d['fees']],
            ['label' => 'إيرادات أخرى (4200)', 'value' => $d['other'], 'href' => $ledger(\App\Support\Finance\Accounts::OTHER_INCOME)],
            ['label' => 'فروقات جرد الصناديق (4210)', 'value' => $d['differences'], 'href' => $ledger(\App\Support\Finance\Accounts::COUNT_DIFFERENCES)],
            ['label' => 'التكاليف (ج)', 'value' => $d['costs'], 'sign' => '−'],
        ],
    ])
    @include('tenant.profits._card', [
        'letter' => 'هـ', 'label' => 'الربح المحصَّل نقداً', 'value' => $e['total'], 'prev' => $e['prev']['total'], 'tone' => 'text-ok-700',
        'hint' => ($d['total'] ? 'نسبة التحقّق النقدي '.round($e['total'] / max(1, abs($d['total'])) * 100).'٪ من الصافي. ' : '').'أموال التجّار مستبعدة منه.',
        'formula' => 'أجورٌ وصل نقدها وطوبق في المدّة (اعتماد الوجبة ناقصاً عجزها، ودفع كشف التاجر لما عليه، واكتساب المدفوع مقدّماً) − ما دُفع نقداً من عمولاتٍ وحصصٍ ومصروفات.',
        'parts' => [
            ['label' => 'أجور تحقّقت باعتماد الوجبات', 'value' => $e['batches'], 'href' => route('profits.batches.index', ['status' => 'approved']),
                'note' => $scope['merchant'] ? 'الوجبة لا تُقسَم على تاجر.' : 'أجرٌ دفعه الزبون، ناقصاً عجز الوجبة، ومعه تحصيل العجز السابق.'],
            ['label' => 'أجور على التجّار دُفعت كشوفهم', 'value' => $e['merchants'], 'href' => route('settlements.merchants.index')],
            ['label' => 'أجور مدفوعة مقدّماً اكتُسبت', 'value' => $e['prepaid'], 'href' => $ledger(\App\Support\Finance\Accounts::PREPAID)],
            ['label' => 'عمولات وحصص دُفعت', 'value' => $e['commissions'], 'sign' => '−', 'href' => $ledger(\App\Support\Finance\Accounts::COMMISSIONS)],
            ['label' => 'مصروفات دُفعت من الصناديق', 'value' => $e['expenses'], 'sign' => '−', 'href' => $ledger(\App\Support\Finance\Accounts::EXPENSES_DUE)],
        ],
    ])
    @include('tenant.profits._card', [
        'letter' => 'و', 'label' => 'أرباحٌ لم تُحصَّل بعد', 'value' => $f['total'], 'tone' => 'text-warn-700', 'now' => true,
        'hint' => 'أجورٌ معترفٌ بها لم يصل نقدها بعد، بمكانها.',
        'formula' => 'أجور ما سُلِّم ولم يُقفَل كشفه + أجور كشوفٍ لم تدخل وجبةً معتمدة + أجورٌ على التجّار لم يُدفع كشفها + عجز المناديب غير المسترد (1250).',
        'parts' => [
            ['label' => 'بيد المناديب', 'value' => $f['couriers']['total'], 'href' => route('couriers.cash'),
                'note' => 'بالأعمار: '.number_format($f['couriers']['0_7']).' · '.number_format($f['couriers']['8_30']).' · '.number_format($f['couriers']['31']).' (٠–٧، ٨–٣٠، +٣٠ يوماً)'],
            ['label' => 'في كشوفٍ ووجباتٍ لم تُعتمد', 'value' => $f['batches']['total'], 'href' => route('profits.batches.index', ['status' => 'open']),
                'note' => 'بالأعمار: '.number_format($f['batches']['0_7']).' · '.number_format($f['batches']['8_30']).' · '.number_format($f['batches']['31'])],
            ['label' => 'على حساب التجّار', 'value' => $f['merchants']['total'], 'href' => route('settlements.merchants.index'),
                'note' => 'بالأعمار: '.number_format($f['merchants']['0_7']).' · '.number_format($f['merchants']['8_30']).' · '.number_format($f['merchants']['31'])],
            ['label' => 'عجزٌ على المناديب (1250)', 'value' => $f['shortages']['total'], 'href' => $ledger(\App\Support\Finance\Accounts::SHORTAGES, $always, $today)],
        ],
    ])
    @if ($g)
        @include('tenant.profits._card', [
            'letter' => 'ز', 'label' => 'الأرباح المحتجزة', 'value' => $g['total'], 'tone' => 'text-profit-700', 'now' => true,
            'hint' => 'مدعومةٌ بنقدٍ حرّ '.number_format($g['backed']).'، والباقي في أجورٍ لم تُحصَّل أو عهدٍ أو ذمم. للشركة كلّها.',
            'formula' => 'كل ما رُبح منذ بداية الدفتر (الإيرادات − التكاليف) − المسحوب (3300). والمدعوم بنقد = الأصغر من المحتجزة والنقد الحرّ (ح).',
            'parts' => [
                ['label' => 'ما رُبح منذ البداية', 'value' => $g['earned'], 'href' => $trace ? route('finance.trial-balance', ['from' => $always, 'to' => $today]) : null],
                ['label' => 'المسحوب (3300)', 'value' => $g['drawn'], 'sign' => '−', 'href' => route('profits.withdrawals.index')],
                ['label' => 'مدعومة بنقد', 'value' => $g['backed']],
                ['label' => 'غير مدعومة بنقد', 'value' => $g['unbacked']],
            ],
        ])
        @include('tenant.profits._card', [
            'letter' => 'ح', 'label' => 'النقد المتاح للسحب', 'value' => $h['total'], 'tone' => 'text-profit-700', 'now' => true,
            'hint' => 'الأصغر من النقد الحرّ ('.number_format($h['free']).') والمحصَّل نقداً المتراكم بعد المسحوب ('.number_format($h['realized'] - $h['drawn']).'). للشركة كلّها.',
            'formula' => 'النقد الحرّ = الصناديق (1100) − أموال التجّار فيها − التأمينات − المقبوض مقدّماً − المصروفات المستحقّة − حصص الاستلام المستحقّة − الزيادات المعلّقة − الاحتياطي. والمتاح = الأصغر منه ومن (المحصَّل المتراكم − المسحوب)، ولا يقلّ عن صفر.',
            'parts' => [
                ['label' => 'النقد في الصناديق (1100)', 'value' => $h['cash'], 'href' => route('cash.index')],
                ['label' => 'أموال التجّار في الصناديق', 'value' => $h['merchants'], 'sign' => '−',
                    'note' => 'مستحقّاتهم '.number_format($h['payables']).' ناقص ما بيد المناديب منها '.number_format($h['with_couriers']).'.'],
                ['label' => 'تأمينات التجّار (2110)', 'value' => $h['deposits'], 'sign' => '−'],
                ['label' => 'أجور مقبوضة مقدّماً (2120)', 'value' => $h['prepaid'], 'sign' => '−'],
                ['label' => 'مصروفات مستحقّة (2300)', 'value' => $h['expenses_due'], 'sign' => '−', 'href' => route('expenses.index')],
                ['label' => 'حصص مندوبي الاستلام المستحقّة', 'value' => $h['pickup_due'], 'sign' => '−', 'href' => route('pickup-agents.index')],
                ['label' => 'زيادات نقدية معلّقة (2400)', 'value' => $h['excess'], 'sign' => '−'],
                ['label' => 'الاحتياطي التشغيلي', 'value' => $h['reserve'], 'sign' => '−', 'note' => 'إعدادٌ للشركة: مبلغٌ ثابت أو أيامٌ من متوسّط المصروف.'],
                ['label' => 'النقد الحرّ', 'value' => $h['free']],
                ['label' => 'المحصَّل نقداً المتراكم', 'value' => $h['realized']],
                ['label' => 'المسحوب', 'value' => $h['drawn'], 'sign' => '−'],
            ],
        ])
    @endif
    @include('tenant.profits._card', [
        'letter' => 'ط', 'label' => 'مستحقّات التجّار غير المسدّدة', 'value' => $i['total'], 'tone' => 'text-info-700', 'now' => true,
        'hint' => 'منها مستحقٌّ الآن '.number_format($i['now']).'، ومتأخّرٌ عن دورة التسوية ('.\App\Support\Arabic::days($i['cycle']).') '.number_format($i['late']).'.',
        'formula' => 'المستحقّ الآن: نقده عندنا (كشوفٌ تُبنى، وكشوفٌ أُقفلت ولم تُدفع). والمتأخّر منه ما تجاوز دورة التسوية. والمستقبليّ: نقده ما زال عند المناديب.',
        'parts' => [
            ['label' => 'مستحقٌّ الآن', 'value' => $i['now'], 'href' => route('settlements.merchants.index')],
            ['label' => 'منه متأخّر', 'value' => $i['late']],
            ['label' => 'مستقبليٌّ عند المناديب', 'value' => $i['future'], 'href' => route('couriers.cash')],
        ],
    ])
</div>

{{-- ما ينتظر عملاً في الوجبات --}}
<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-3">
    <a href="{{ route('profits.batches.index', ['status' => 'open']) }}" class="card block p-4 hover:shadow">
        <div class="text-xs font-medium text-ink-500">وجبات لم تُعتمد بعد</div>
        <div class="num mt-1 text-2xl font-bold text-warn-700">{{ number_format($open) }}</div>
    </a>
    <a href="{{ route('profits.batches.index', ['status' => 'discrepancy']) }}" class="card block p-4 hover:shadow">
        <div class="text-xs font-medium text-ink-500">وجباتٌ فيها عجزٌ لم يُعالَج</div>
        <div class="num mt-1 text-2xl font-bold text-bad-700">{{ number_format($differences) }}</div>
    </a>
    <a href="{{ auth()->user()->can('batches.prepare') ? route('profits.batches.create') : route('profits.batches.index', ['status' => 'open']) }}" class="card block p-4 hover:shadow">
        <div class="text-xs font-medium text-ink-500">كشوف مناديب مُقفَلة لم تدخل وجبة</div>
        <div class="num mt-1 text-2xl font-bold text-ink-900">{{ number_format($waiting['count']) }}</div>
        <div class="mt-1 text-xs text-ink-500">نقدها <span class="num">{{ number_format($waiting['cash']) }}</span> د.ع</div>
    </a>
</div>

{{-- الرسم ١ --}}
<section class="card mb-5 p-5">
    <h2 class="card-title mb-3">الإيرادات والتكاليف وصافي الربح {{ $period->days() > 31 ? 'أسبوعاً بأسبوع' : 'يوماً بيوم' }}</h2>
    @include('tenant.profits.charts.trend', ['series' => $series,
        'link' => $trace ? fn ($f, $t) => route('finance.ledger', ['from' => $f, 'to' => $t] + $dims) : null])
</section>

<div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
    {{-- الرسم ٢ --}}
    @if ($branches->isNotEmpty())
        <section class="card p-5">
            <h2 class="card-title mb-3">صافي الربح حسب الفروع</h2>
            @include('tenant.profits.charts.bars', ['rows' => $branches->sortByDesc('net')->map(fn ($b) => [
                'label' => $b->name, 'value' => $b->net,
                'href' => $b->id && $wide ? route('profits.index', ['branch_id' => $b->id] + request()->only(['range', 'from', 'to'])) : null,
            ])->values()->all()])
        </section>
    @endif
    {{-- الرسم ٣ --}}
    <section class="card p-5">
        <h2 class="card-title mb-1">المحصَّل مقابل غير المحصَّل</h2>
        <p class="mb-3 text-xs text-ink-500">الأجور التي تحقّقت نقداً في المدّة، وغير المحصَّل الآن بأماكنه.</p>
        @include('tenant.profits.charts.stack', ['parts' => [
            ['label' => 'تحقّق نقداً في المدّة', 'value' => $e['fees'], 'color' => '--color-ok-700', 'href' => route('profits.batches.index', ['status' => 'approved'])],
            ['label' => 'بيد المناديب', 'value' => $f['couriers']['total'], 'color' => '--color-warn-500', 'href' => route('couriers.cash')],
            ['label' => 'في كشوفٍ ووجباتٍ لم تُعتمد', 'value' => $f['batches']['total'], 'color' => '--color-info-700', 'href' => route('profits.batches.index', ['status' => 'open'])],
            ['label' => 'على حساب التجّار', 'value' => $f['merchants']['total'], 'color' => '--color-profit-500', 'href' => route('settlements.merchants.index')],
            ['label' => 'عجزٌ على المناديب', 'value' => $f['shortages']['total'], 'color' => '--color-bad-700', 'href' => $ledger(\App\Support\Finance\Accounts::SHORTAGES, $always, $today)],
        ]])
    </section>
</div>

{{-- الرسم ٤ --}}
<section class="card mb-5 p-5">
    <h2 class="card-title mb-3">التحصيلات اليومية — النقد المعدود في الوجبات المعتمدة</h2>
    @include('tenant.profits.charts.line', ['days' => $collections,
        'link' => fn ($day) => route('profits.batches.index', ['status' => 'approved', 'approved_on' => $day])])
</section>

{{-- الرسم ٥: أفضل المناديب --}}
<section class="card mb-5 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-5 py-4">
        <h2 class="card-title">أفضل المناديب في المدّة</h2>
        <nav class="flex flex-wrap gap-1 text-xs" aria-label="ترتيب المناديب">
            @foreach (\App\Services\Finance\ProfitDashboard::RANKS as $key => $label)
                <a href="{{ route('profits.index', $keep + ['rank' => $key]) }}" @class(['chip', 'chip-ok' => $rank === $key, 'chip-mute' => $rank !== $key])
                   @if ($rank === $key) aria-current="true" @endif>بـ{{ $label }}</a>
            @endforeach
        </nav>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>#</th><th>المندوب</th><th>المسلَّمة</th><th>في الموعد</th><th>إيراد شحناته</th><th>عمولاته</th><th>مساهمته</th><th>فرق وجباته</th></tr></thead>
            <tbody>
                @forelse ($couriers as $n => $row)
                    <tr>
                        <td class="num text-ink-500">{{ $n + 1 }}</td>
                        <td><a href="{{ route('profits.couriers.show', $row->id) }}" class="text-[var(--brand)] hover:underline">{{ $row->name }}</a> <span class="num text-xs text-ink-500">{{ $row->code }}</span></td>
                        <td class="num">{{ number_format($row->delivered) }}</td>
                        <td class="num">{{ $row->on_time === null ? '—' : $row->on_time.'٪' }}</td>
                        <td class="num">{{ number_format($row->revenue) }}</td>
                        <td class="num">{{ number_format($row->commissions) }}</td>
                        <td class="num font-semibold text-profit-700">{{ number_format($row->contribution) }}</td>
                        <td @class(['num', 'font-semibold text-bad-700' => $row->discrepancy < 0, 'text-info-700' => $row->discrepancy > 0])>{{ number_format($row->discrepancy) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-ink-500">لا تسليم بيد مندوبٍ في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- الرسم ٦: مقارنة الفروع --}}
@if ($branches->isNotEmpty())
    @php($maxNet = max(1, (int) $branches->max(fn ($b) => abs($b->net))))
    <section class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
            <h2 class="card-title">مقارنة الفروع</h2>
            <p class="mt-1 text-xs text-ink-500">الإيراد على فرع إنشاء الشحنة، والعهدة والصناديق على فرعها. والعمولة الداخلية بين الفروع منفصلة: تتقاصّ في مجموع الشركة فلا تدخل صافيها.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl text-sm">
                <thead>
                    <tr>
                        <th>الفرع</th><th>المسلَّمة</th><th>في الموعد</th><th>الإيراد</th><th>التكاليف</th><th class="min-w-40">صافي الربح</th>
                        <th>داخلي له / عليه</th><th>المحصَّل</th><th>غير المحصَّل</th><th>عهد المناديب</th><th>نقد الصناديق</th>
                        <th>عجزٌ لم يُعالَج</th><th>مستحقّات التجّار</th><th>مصروفات مستحقّة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($branches as $row)
                        <tr>
                            <td>@if ($row->id && $wide)<a href="{{ route('profits.index', ['branch_id' => $row->id] + request()->only(['range', 'from', 'to'])) }}" class="text-[var(--brand)] hover:underline">{{ $row->name }}</a>@else{{ $row->name }}@endif</td>
                            <td class="num">{{ $row->delivered === null ? '—' : number_format($row->delivered) }}</td>
                            <td class="num">{{ $row->on_time === null ? '—' : $row->on_time.'٪' }}</td>
                            <td class="num">{{ number_format($row->revenue + $row->other) }}</td>
                            <td class="num">{{ number_format($row->costs) }}</td>
                            <td>
                                <div @class(['num font-semibold', 'text-bad-700' => $row->net < 0, 'text-profit-700' => $row->net >= 0])>{{ number_format($row->net) }}</div>
                                <div class="mt-1 h-1.5 rounded-full bg-ink-100"><div class="h-1.5 rounded-full" style="width: {{ round(abs($row->net) / $maxNet * 100, 1) }}%; background: var({{ $row->net < 0 ? '--color-bad-700' : '--color-profit-500' }})"></div></div>
                            </td>
                            <td class="num text-xs">{{ number_format($row->internal_in) }} / {{ number_format($row->internal_out) }}</td>
                            @foreach (['realized', 'unrealized', 'custody', 'boxes', 'open_shortage', 'payables', 'expenses_due'] as $key)
                                <td @class(['num', 'text-bad-700' => $key === 'open_shortage' && $row->{$key} > 0])>{{ $row->{$key} === null ? '—' : number_format($row->{$key}) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-bold">
                        <td>المجموع</td>
                        <td class="num">{{ number_format($branches->sum('delivered')) }}</td>
                        <td></td>
                        <td class="num">{{ number_format($branches->sum('revenue') + $branches->sum('other')) }}</td>
                        <td class="num">{{ number_format($branches->sum('costs')) }}</td>
                        <td class="num">{{ number_format($branches->sum('net')) }}</td>
                        <td class="num text-xs">{{ number_format($branches->sum('internal_in')) }} / {{ number_format($branches->sum('internal_out')) }}</td>
                        <td colspan="7"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
@endif
@endsection

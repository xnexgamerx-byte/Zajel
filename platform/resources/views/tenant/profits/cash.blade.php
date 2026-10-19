@extends('layouts.app')
@section('title', 'الموقف النقدي')

@section('content')
@include('tenant.profits._tabs', [
    'title' => 'الموقف النقدي',
    'lead'  => 'أين النقد الآن، فئةً فئة لا رقماً واحداً، وما يُتوقَّع أن يدخل ويخرج في أربعة عشر يوماً بنطاقه وافتراضاته.',
])

@php($section = fn (string $n, string $title) => '<span class="chip chip-mute me-1">'.$n.'</span> '.e($title))

<div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
    {{-- ١ و٢ --}}
    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">{!! $section('١', 'النقد في الصناديق والحسابات') !!}
            <span class="num float-end">{{ number_format($c['cash']) }} د.ع</span></h2>
        <ul class="divide-y divide-ink-100 text-sm">
            @forelse ($c['boxes'] as $box)
                <li class="flex items-center justify-between gap-2 px-5 py-2">
                    <a href="{{ route('cash.index', ['box_id' => $box->id]) }}" class="hover:underline">{{ $box->name }} <span class="text-xs text-ink-500">{{ $box->typeLabel() }}</span></a>
                    <span @class(['num font-semibold', 'text-bad-700' => $box->balance < 0])>{{ number_format($box->balance) }}</span>
                </li>
            @empty
                <li class="px-5 py-6 text-center text-ink-500">لا صندوق مفعّل.</li>
            @endforelse
        </ul>
        <div class="border-t border-ink-100 bg-ink-50 px-5 py-3 text-xs leading-6">
            <span class="chip chip-mute me-1">٢</span> منه نقدٌ لم تُطابَق وجبته بعد — جزءٌ من الصناديق لا يُضاف إليها:
            <div>في وجباتٍ مفتوحة <a href="{{ route('profits.batches.index', ['status' => 'open']) }}" class="num font-semibold text-[var(--brand)] hover:underline">{{ number_format($c['unreconciled']['in_batches']) }}</a>
                ({{ \App\Support\Arabic::count($c['unreconciled']['batches'], ['وجبة واحدة', 'وجبتين', 'وجبات', 'وجبة']) }})</div>
            <div>وفي كشوفٍ مُقفَلة لم تدخل وجبة <a href="{{ route('profits.batches.create') }}" class="num font-semibold text-[var(--brand)] hover:underline">{{ number_format($c['unreconciled']['unbatched']) }}</a>
                ({{ \App\Support\Arabic::count($c['unreconciled']['unbatched_count'], ['كشف واحد', 'كشفان', 'كشوف', 'كشفاً']) }})</div>
        </div>
    </section>

    {{-- ٣ --}}
    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">{!! $section('٣', 'النقد عند المناديب') !!}
            <span class="num float-end">{{ number_format($c['couriers']['total']) }} د.ع</span></h2>
        <div class="grid grid-cols-4 gap-2 p-4 text-center text-xs">
            @foreach (['0_1' => 'يومٌ أو أقل', '2_3' => '٢–٣ أيام', '4_7' => '٤–٧ أيام', '8' => 'أكثر من ٧'] as $key => $label)
                <div @class(['rounded-xl p-2', 'bg-bad-50 text-bad-700' => $key === '8' && $c['couriers']['ages'][$key] > 0, 'bg-ink-50' => ! ($key === '8' && $c['couriers']['ages'][$key] > 0)])>
                    <div>{{ $label }}</div><div class="num mt-1 font-bold">{{ number_format($c['couriers']['ages'][$key]) }}</div>
                </div>
            @endforeach
        </div>
        <ul class="divide-y divide-ink-100 border-t border-ink-100 text-sm">
            @foreach ($c['couriers']['top'] as $courier)
                <li class="flex justify-between gap-2 px-5 py-2"><a href="{{ route('profits.couriers.show', $courier) }}" class="hover:underline">{{ $courier->name }}</a><span class="num">{{ number_format($courier->cash_in_hand) }}</span></li>
            @endforeach
        </ul>
    </section>
</div>

<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
    @include('tenant.profits._figure', ['label' => '٤ · بالطريق بين الفروع', 'value' => $c['in_transit']['amount'],
        'hint' => \App\Support\Arabic::count($c['in_transit']['count'], ['تسديدٌ واحد', 'تسديدان', 'تسديدات', 'تسديداً']).' أُرسلت ولم تُستلم.'])
    @include('tenant.profits._figure', ['label' => '٥ · تحصيلاتٌ متوقَّعة', 'value' => $c['expected']['expected'], 'tone' => 'text-info-700',
        'hint' => 'من '.number_format($c['expected']['amount']).' في '.\App\Support\Arabic::shipments($c['expected']['count']).' في طريقها، بنسبة تسليمٍ تاريخية '.round($c['expected']['rate'] * 100).'٪.'])
    @include('tenant.profits._figure', ['label' => '٦ · مستحقّات التجّار', 'value' => $c['merchants']['total'], 'tone' => 'text-info-700',
        'hint' => 'الآن '.number_format($c['merchants']['now']).' (منها متأخّر '.number_format($c['merchants']['late']).')، ومستقبليٌّ عند المناديب '.number_format($c['merchants']['future']).'.'])
    @include('tenant.profits._figure', ['label' => '٧ · مصروفاتٌ والتزاماتٌ مستحقّة', 'value' => array_sum($c['liabilities']), 'tone' => 'text-bad-700',
        'hint' => 'مصروفات '.number_format($c['liabilities']['expenses_due']).' · عمولات مناديب '.number_format($c['liabilities']['commissions'])
            .' · تأمينات '.number_format($c['liabilities']['deposits']).' · مقبوضٌ مقدّماً '.number_format($c['liabilities']['prepaid']).' · زيادات معلّقة '.number_format($c['liabilities']['excess']).'.'])
    @include('tenant.profits._figure', ['label' => '٨ · الاحتياطي التشغيلي', 'value' => $c['reserve'],
        'hint' => 'إعدادٌ للشركة في «إعدادات الشركة»: مبلغٌ ثابت أو أيامٌ من متوسّط المصروف.'])
    @if ($c['available'])
        @include('tenant.profits._figure', ['label' => '٩ · المتاح للسحب', 'value' => max(0, $c['available']['total'] - $c['committed']), 'tone' => 'text-profit-700',
            'hint' => 'الأصغر من النقد الحرّ ('.number_format($c['available']['free']).') والمحصَّل نقداً بعد المسحوب'.($c['committed'] ? '، بعد حجز '.number_format($c['committed']).' طُلب ولم يُدفع' : '').'.'])
    @endif
</div>

{{-- التوقّع --}}
<section class="card p-5">
    <h2 class="card-title mb-1">التدفّق المتوقَّع — أربعة عشر يوماً</h2>
    @if (! $forecast)
        <p class="py-8 text-center text-sm text-ink-500">لا يُعرض التوقّع قبل أن يكون للشركة تاريخ تسليمٍ ٣٠ يوماً على الأقل: أقلّ منه لا يُبنى عليه رقم.</p>
    @else
        @php($a = $forecast['assumptions'])
        <p class="mb-3 text-xs text-ink-500">يبدأ من النقد في الصناديق الآن <span class="num font-semibold">{{ number_format($a['start_cash']) }}</span> د.ع.</p>
        @include('tenant.profits.charts.band', ['days' => $forecast['days'], 'start' => $a['start_cash']])

        <div class="mt-4 overflow-x-auto">
            <table class="tbl text-sm">
                <thead><tr><th>اليوم</th><th>الداخل المتوقَّع</th><th>نطاقه</th><th>للتجّار</th><th>المصروف</th><th>الصافي</th><th>الرصيد المتوقَّع</th><th>نطاقه</th></tr></thead>
                <tbody>
                    @foreach ($forecast['days'] as $d)
                        <tr>
                            <td class="num">{{ $d['date'] }}</td>
                            <td class="num">{{ number_format($d['in']) }}</td>
                            <td class="num text-xs text-ink-500">{{ number_format($d['in_low']) }} – {{ number_format($d['in_high']) }}</td>
                            <td class="num">{{ number_format($d['merchants']) }}</td>
                            <td class="num">{{ number_format($d['expenses']) }}</td>
                            <td @class(['num font-semibold', 'text-bad-700' => $d['net'] < 0])>{{ number_format($d['net']) }}</td>
                            <td @class(['num font-semibold', 'text-bad-700' => $d['balance'] < 0])>{{ number_format($d['balance']) }}</td>
                            <td class="num text-xs text-ink-500">{{ number_format($d['low']) }} – {{ number_format($d['high']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h3 class="mt-5 mb-2 text-sm font-bold">الافتراضات</h3>
        <ul class="list-disc space-y-1 ps-5 text-xs leading-6 text-ink-600">
            <li>الداخل = مبالغ الشحنات التي في طريقها × نسبة تسليم محافظتها في آخر ٣٠ يوماً (للشركة <span class="num">{{ round($a['rate'] * 100) }}٪</span>@if ($a['governorates'])؛ @foreach ($a['governorates'] as $name => $rate){{ $name }} <span class="num">{{ $rate }}٪</span>@if (! $loop->last)، @endif @endforeach @endif) × توزيع زمن التسليم من الاستلام (متوسّطه <span class="num">{{ $a['delivery_mean'] }}</span> يوم) بعد عمر كل شحنة.</li>
            <li>والنقد يصل الصناديق بعد تأخّر المندوب في تسليمه (متوسّطه <span class="num">{{ $a['handover_mean'] }}</span> يوم)، وما بيد المناديب الآن يصل بالتوزيع نفسه.</li>
            <li>الخارج: مستحقّات التجّار الحالية تُدفع على دورتهم (<span class="num">{{ $a['cycle'] }}</span> أيام) بالتساوي، وحصّتهم ممّا يدخل تُدفع بعده بدورة؛ والمصروف متوسّط آخر ٦٠ يوماً (<span class="num">{{ number_format($a['daily_expense']) }}</span> د.ع يومياً).</li>
            <li>النطاق من تذبذب نسبة التسليم اليومية (المئين ١٠ و٩٠): الأدنى ×<span class="num">{{ $a['low_factor'] }}</span> والأعلى ×<span class="num">{{ $a['high_factor'] }}</span> لما يتوقّف على التسليم.</li>
            <li>لا يدخل التوقّعَ سحبٌ للأرباح ولا تسديدٌ بين الفروع ولا مصروفٌ غير معتاد.</li>
        </ul>
    @endif
</section>
@endsection

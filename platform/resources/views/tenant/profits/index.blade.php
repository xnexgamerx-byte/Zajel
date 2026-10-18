@extends('layouts.app')
@section('title', 'العوائد')

@section('content')
@include('tenant.profits._tabs', [
    'title' => 'العوائد',
    'lead'  => 'أرباح الشركة من وجبات التحصيل: ما حُسب، وما تحقّق نقداً، وما سُحب، وما ينتظر الاعتماد. أموال التجّار وأجور التوصيل وصافي الربح كلٌّ في خانته.',
])

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @include('tenant.profits._figure', ['label' => 'إجمالي الأرباح المحسوبة', 'value' => $summary['profit'], 'tone' => 'text-ok-700',
        'hint' => ($summary['approved_count'] ? 'من '.\App\Support\Arabic::count($summary['approved_count'], ['وجبة واحدة', 'وجبتين', 'وجبات', 'وجبة']).' معتمدة' : 'لا وجبة معتمدة بعد').': أجور التوصيل ناقص العمولات والتكاليف.'])
    @include('tenant.profits._figure', ['label' => 'الربح المتحقّق نقداً', 'value' => $summary['realized'], 'tone' => 'text-ok-700',
        'hint' => 'ما بقي في اليد للشركة ممّا عُدَّ فعلاً، بعد أموال التجّار والتكاليف.'])
    @unless (auth()->user()->isBranchLimited())
        @include('tenant.profits._figure', ['label' => 'ما سُحب من الأرباح', 'value' => $summary['withdrawn'], 'tone' => 'text-bad-700',
            'hint' => 'كل سحبٍ عمليةٌ منفصلة بسجلّها في «سحب الأرباح».'])
        @include('tenant.profits._figure', ['label' => 'المتاح للسحب', 'value' => $summary['available'], 'tone' => $summary['available'] >= 0 ? 'text-ok-700' : 'text-bad-700',
            'hint' => 'المتحقّق نقداً ناقص ما سُحب.'])
    @endunless
</div>

<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-3">
    <a href="{{ route('profits.batches.index', ['status' => 'draft']) }}" class="card block p-4 hover:shadow">
        <div class="text-xs font-medium text-ink-500">وجبات لم تُعتمد بعد</div>
        <div class="mt-1 text-2xl font-bold text-warn-700"><span class="num">{{ number_format($summary['draft_count']) }}</span></div>
        <div class="mt-1 text-xs text-ink-500">ربحها المحسوب <span class="num">{{ number_format($summary['draft_profit']) }}</span> د.ع — لا يُجمع حتى تُعتمد.</div>
    </a>
    <a href="{{ route('profits.batches.index', ['status' => 'discrepancy']) }}" class="card block p-4 hover:shadow">
        <div class="text-xs font-medium text-ink-500">وجبات فيها فروقات</div>
        <div class="mt-1 text-2xl font-bold text-bad-700"><span class="num">{{ number_format($summary['discrepancy_count']) }}</span></div>
        <div class="mt-1 text-xs text-ink-500">عجزٌ في عهدة المناديب لم يُسدَّد: <span class="num font-semibold">{{ number_format($summary['shortage']) }}</span> د.ع</div>
    </a>
    <a href="{{ route('profits.batches.create') }}" class="card block p-4 hover:shadow">
        <div class="text-xs font-medium text-ink-500">كشوف مناديب مُقفَلة لم تدخل وجبة</div>
        <div class="mt-1 text-2xl font-bold text-ink-900"><span class="num">{{ number_format($waiting['count']) }}</span></div>
        <div class="mt-1 text-xs text-ink-500">نقدها <span class="num">{{ number_format($waiting['cash']) }}</span> د.ع — أنشئ بها وجبة تحصيل.</div>
    </a>
</div>

{{-- الفصل الذي تقوم عليه العوائد: مال التجّار ليس لنا، والأجور إيرادنا، والصافي بعد التكاليف --}}
<section class="card mb-5 p-5">
    <h2 class="mb-3 text-sm font-bold">أين يذهب كل دينار — من الوجبات المعتمدة</h2>
    <div class="grid grid-cols-1 gap-3 text-sm md:grid-cols-4">
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">أموال التجّار التي استلمها المناديب</div>
            <div class="num mt-1 text-lg font-bold">{{ number_format($summary['merchant_money']) }}</div>
            <div class="text-xs text-ink-500">ليست ربحاً: تُدفع للتجّار بكشوفهم.</div>
        </div>
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">أجور التوصيل — إيراد الشركة</div>
            <div class="num mt-1 text-lg font-bold">{{ number_format($summary['fees']) }}</div>
            <div class="text-xs text-ink-500">قبل العمولات والتكاليف.</div>
        </div>
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">صافي الربح بعد التكاليف</div>
            <div class="num mt-1 text-lg font-bold text-ok-700">{{ number_format($summary['profit']) }}</div>
            <div class="text-xs text-ink-500">محاسبياً: ما كسبته الشركة.</div>
        </div>
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">لم يُحصَّل نقداً بعد</div>
            <div class="num mt-1 text-lg font-bold text-warn-700">{{ number_format($summary['unrealized']) }}</div>
            <div class="text-xs text-ink-500">أجورٌ على حساب التجّار أو دُفعت مقدّماً، وعجزٌ عند المناديب.</div>
        </div>
    </div>
    <p class="mt-3 text-xs text-ink-500">ومستحقّات التجّار الآن في حساباتهم كلّها (الموقف المالي): <span class="num font-semibold text-ink-800">{{ number_format($payables) }}</span> د.ع.</p>
</section>

<div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">أداء الفروع — {{ $period->label() }}</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>الفرع</th><th>الوجبات</th><th>الأجور</th><th>صافي الربح</th><th>المتحقّق</th></tr></thead>
                <tbody>
                    @forelse ($month['branches'] as $row)
                        <tr>
                            <td>{{ $row->name }}</td>
                            <td class="num">{{ number_format($row->batches) }}</td>
                            <td class="num">{{ number_format($row->fees) }}</td>
                            <td class="num font-semibold">{{ number_format($row->profit) }}</td>
                            <td class="num">{{ number_format($row->realized) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-ink-500">لا وجبة معتمدة هذا الشهر.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">أداء المناديب — {{ $period->label() }}</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>المندوب</th><th>الشحنات</th><th>الأجور</th><th>عمولته</th><th>مساهمته</th><th>الفرق</th></tr></thead>
                <tbody>
                    @forelse ($month['couriers']->take(10) as $row)
                        <tr>
                            <td>{{ $row->name }}</td>
                            <td class="num">{{ number_format($row->shipments) }}</td>
                            <td class="num">{{ number_format($row->fees) }}</td>
                            <td class="num">{{ number_format($row->commissions) }}</td>
                            <td class="num font-semibold">{{ number_format($row->contribution) }}</td>
                            <td @class(['num', 'text-bad-700 font-semibold' => $row->discrepancy < 0])>{{ number_format($row->discrepancy) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-ink-500">لا وجبة معتمدة هذا الشهر.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-ink-100 px-5 py-3 text-xs"><a href="{{ route('profits.report') }}" class="text-[var(--brand)] hover:underline">التقرير الكامل لأي مدّة ←</a></div>
    </section>
</div>

<div class="grid grid-cols-1 gap-5">
    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">آخر وجبات التحصيل</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>الرقم</th><th>المدّة</th><th>الفرع</th><th>المحصَّل</th><th>صافي الربح</th><th>الحال</th></tr></thead>
                <tbody>
                    @forelse ($recent as $batch)
                        <tr>
                            <td><a href="{{ route('profits.batches.show', $batch) }}" class="num whitespace-nowrap font-semibold text-[var(--brand)] hover:underline">{{ $batch->number }}</a></td>
                            <td class="num whitespace-nowrap text-xs">{{ $batch->from_date->format('Y-m-d') }} — {{ $batch->to_date->format('Y-m-d') }}</td>
                            <td>{{ $batch->branch?->name ?? 'كل الفروع' }}</td>
                            <td class="num">{{ number_format($batch->received_cash) }}</td>
                            <td class="num font-semibold">{{ number_format($batch->net_profit) }}</td>
                            <td class="whitespace-nowrap">@include('tenant.profits._status')</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-ink-500">لا وجبة بعد. اضغط «إنشاء وجبة تحصيل».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @unless (auth()->user()->isBranchLimited())
        <section class="card overflow-hidden">
            <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">آخر عمليات السحب</h2>
            <ul class="divide-y divide-ink-100 text-sm">
                @forelse ($withdrawals as $w)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div>
                            <div class="num font-semibold">{{ $w->number }}</div>
                            <div class="text-xs text-ink-500">{{ $w->withdrawn_on->format('Y-m-d') }}@if ($w->withdrawn_by) · {{ $w->withdrawn_by }}@endif</div>
                        </div>
                        <div @class(['num font-bold', 'line-through text-ink-400' => $w->isCancelled()])>{{ number_format($w->amount) }}</div>
                    </li>
                @empty
                    <li class="px-5 py-10 text-center text-ink-500">لا سحب بعد.</li>
                @endforelse
            </ul>
        </section>
    @endunless
</div>
@endsection

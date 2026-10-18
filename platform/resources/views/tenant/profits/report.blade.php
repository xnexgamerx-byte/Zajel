@extends('layouts.app')
@section('title', 'تقرير الأرباح')

@section('content')
@include('tenant.profits._tabs', ['title' => 'تقرير الأرباح', 'lead' => 'الأرباح والتحصيلات ومستحقّات التجّار عن أي مدّة، مجمّعةً من الوجبات التي تنتهي فيها — بالفرع أو بالمندوب.'])

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ $period->from->toDateString() }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ $period->to->toDateString() }}" class="field-input">
    </div>
    @if ($branches->isNotEmpty())
        <div class="min-w-40">
            <label class="field-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id" class="field-input">
                <option value="">كل الفروع</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="min-w-48">
        <label class="field-label" for="courier_id">المندوب</label>
        <select id="courier_id" name="courier_id" class="field-input" data-searchable>
            <option value="">كل المناديب</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected($courierId === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <label class="flex items-center gap-2 pb-2 text-sm">
        <input type="checkbox" name="drafts" value="1" class="size-4 accent-[var(--brand)]" @checked(request()->boolean('drafts'))> مع ما لم يُعتمد
    </label>
    <button type="submit" class="btn-primary">عرض</button>
</form>

@php($t = $report['totals'])
<div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @include('tenant.profits._figure', ['label' => 'المحصَّل من الزبائن', 'value' => $t['cod'], 'hint' => \App\Support\Arabic::count($t['batches'], ['وجبة واحدة', 'وجبتان', 'وجبات', 'وجبة']).' · '.\App\Support\Arabic::shipments($t['shipments'])])
    @include('tenant.profits._figure', ['label' => 'منه أموال التجّار', 'value' => $t['merchant'], 'hint' => 'مستحقّة للتجّار — ليست ربحاً.'])
    @include('tenant.profits._figure', ['label' => 'أجور التوصيل (الإيراد)', 'value' => $t['fees']])
    @include('tenant.profits._figure', ['label' => $courierId ? 'مساهمة المندوب (الأجور − عمولته)' : 'صافي الربح', 'value' => $t['profit'], 'tone' => $t['profit'] >= 0 ? 'text-ok-700' : 'text-bad-700'])
</div>
<div class="mb-5 grid grid-cols-2 gap-3 text-sm md:grid-cols-3 xl:grid-cols-6">
    @foreach ([
        ['عمولات المناديب', $t['commissions'], ''],
        ['التكاليف', $t['costs'], ''],
        ['المتحقّق نقداً', $t['realized'], 'text-ok-700'],
        ['الفروقات', $t['discrepancy'], $t['discrepancy'] < 0 ? 'text-bad-700' : ''],
        ['سُحب في المدّة', $t['withdrawn'], ''],
        ['لم يُحصَّل نقداً', $t['profit'] - $t['realized'], 'text-warn-700'],
    ] as [$label, $value, $tone])
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">{{ $label }}</div>
            <div class="num mt-1 font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>
@if ($courierId)
    <p class="mb-5 text-xs text-ink-500">بمندوبٍ بعينه: التكاليف على الوجبة لا على المندوب، فلا تُقسَم عليه.</p>
@endif

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">أداء الفروع</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الفرع</th><th>الوجبات</th><th>الشحنات</th><th>المحصَّل</th><th>للتجّار</th><th>الأجور</th><th>العمولات</th><th>التكاليف</th><th>صافي الربح</th><th>المتحقّق</th><th>الفروقات</th></tr></thead>
            <tbody>
                @forelse ($report['branches'] as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="num">{{ number_format($row->batches) }}</td>
                        <td class="num">{{ number_format($row->shipments) }}</td>
                        <td class="num">{{ number_format($row->cod) }}</td>
                        <td class="num">{{ number_format($row->merchant) }}</td>
                        <td class="num">{{ number_format($row->fees) }}</td>
                        <td class="num">{{ number_format($row->commissions) }}</td>
                        <td class="num">{{ number_format($row->costs) }}</td>
                        <td class="num font-semibold">{{ number_format($row->profit) }}</td>
                        <td class="num">{{ number_format($row->realized) }}</td>
                        <td @class(['num', 'text-bad-700' => $row->discrepancy < 0])>{{ number_format($row->discrepancy) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-10 text-center text-ink-500">لا وجبة في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">أداء المناديب</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>المندوب</th><th>الكشوف</th><th>الشحنات</th><th>المسلَّمة</th><th>المحصَّل</th><th>للتجّار</th><th>الأجور</th><th>عمولته</th><th>مساهمته</th><th>الفرق</th><th>سدّد من عجزه</th></tr></thead>
            <tbody>
                @forelse ($report['couriers'] as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="num">{{ number_format($row->settlements) }}</td>
                        <td class="num">{{ number_format($row->shipments) }}</td>
                        <td class="num">{{ number_format($row->delivered) }}</td>
                        <td class="num">{{ number_format($row->cod) }}</td>
                        <td class="num">{{ number_format($row->merchant) }}</td>
                        <td class="num">{{ number_format($row->fees) }}</td>
                        <td class="num">{{ number_format($row->commissions) }}</td>
                        <td class="num font-semibold">{{ number_format($row->contribution) }}</td>
                        <td @class(['num', 'text-bad-700 font-semibold' => $row->discrepancy < 0])>{{ number_format($row->discrepancy) }}</td>
                        <td class="num">{{ number_format($row->remainders) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-10 text-center text-ink-500">لا مندوب في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">الوجبات في المدّة</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الرقم</th><th>المدّة</th><th>الفرع</th><th>المحصَّل</th><th>صافي الربح</th><th>المتحقّق</th><th>الحال</th></tr></thead>
            <tbody>
                @forelse ($report['batches'] as $batch)
                    <tr>
                        <td><a href="{{ route('profits.batches.show', $batch) }}" class="num whitespace-nowrap font-semibold text-[var(--brand)] hover:underline">{{ $batch->number }}</a></td>
                        <td class="num whitespace-nowrap text-xs">{{ $batch->from_date->format('Y-m-d') }} — {{ $batch->to_date->format('Y-m-d') }}</td>
                        <td>{{ $batch->branch?->name ?? 'كل الفروع' }}</td>
                        <td class="num">{{ number_format($batch->received_cash) }}</td>
                        <td class="num font-semibold">{{ number_format($batch->net_profit) }}</td>
                        <td class="num">{{ number_format($batch->realized_profit) }}</td>
                        <td class="whitespace-nowrap">@include('tenant.profits._status')</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-500">لا وجبة في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

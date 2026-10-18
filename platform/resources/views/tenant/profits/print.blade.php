@extends('layouts.print', ['back' => route('profits.batches.show', $batch)])
@section('title', 'وجبة التحصيل '.$batch->number)
@section('docTitle', 'وجبة تحصيل وأرباح')
@section('subtitle', ($batch->branch?->name ?? 'كل الفروع').' · '.$batch->from_date->format('Y-m-d').' — '.$batch->to_date->format('Y-m-d'))

@section('content')
<section>
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-lg font-bold">{{ $batch->number }} <span class="text-sm font-normal text-ink-600">{{ $batch->statusLabel() }} · {{ $batch->reconciliationLabel() }}</span></h2>
        <div class="text-xs text-ink-600">@if ($batch->isApproved()) اعتُمدت <span class="num">{{ $batch->approved_at->format('Y-m-d H:i') }}</span> — {{ $batch->approvedBy?->name }}@endif</div>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
        @foreach ([
            ['النقد المستلم فعلاً', $batch->received_cash], ['المتوقَّع بالكشوف', $batch->expected_cash],
            ['أموال التجّار', $batch->merchant_money], ['أجور التوصيل المستحقّة', $batch->delivery_fees],
            ['عمولات المناديب', $batch->courier_commissions], ['التكاليف المرتبطة', $batch->costs],
            ['صافي الربح', $batch->net_profit], ['المتحقّق نقداً', $batch->realized_profit],
            ['الفرق (عجز/زيادة)', $batch->discrepancy], ['خصومات (تلف/غرامات)', $batch->deductions],
        ] as [$label, $value])
            <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">{{ $label }}</span><span class="num font-semibold">{{ number_format($value) }}</span></div>
        @endforeach
    </div>

    <table class="tbl w-full">
        <thead>
            <tr><th>المندوب</th><th>الكشف</th><th>الشحنات</th><th>المحصَّل</th><th>للتجّار</th><th>الأجور</th><th>عمولته</th><th>المتوقَّع</th><th>المستلم</th><th>الفرق</th></tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->courier?->name }}</td>
                    <td class="num">{{ $item->isRemainder() ? 'تحصيل عجز '.($item->remainderOf?->settlement?->code) : $item->settlement?->code }}</td>
                    <td class="num">{{ number_format($item->shipments_count) }}</td>
                    <td class="num">{{ number_format($item->cod_collected) }}</td>
                    <td class="num">{{ number_format($item->merchant_money) }}</td>
                    <td class="num">{{ number_format($item->delivery_fees) }}</td>
                    <td class="num">{{ number_format($item->courier_commissions) }}</td>
                    <td class="num">{{ number_format($item->expected_cash) }}</td>
                    <td class="num">{{ number_format($item->received_cash) }}</td>
                    <td class="num">{{ number_format($item->discrepancy()) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-8 flex items-end justify-between gap-8 text-xs text-ink-600">
        <div class="flex-1">أعدّها: {{ $batch->createdBy?->name ?? '..................' }}<div class="mt-6 border-t border-ink-400 pt-1">التوقيع</div></div>
        <div class="flex-1">اعتمدها: {{ $batch->approvedBy?->name ?? '..................' }}<div class="mt-6 border-t border-ink-400 pt-1">التوقيع</div></div>
    </div>
</section>
@endsection

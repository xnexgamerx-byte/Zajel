@extends('layouts.app')
@section('title', 'تسعيرة الفرع')

@section('content')
<div class="mb-5">
    <h1 class="page-title">تسعيرة {{ $branch?->name ?? 'الشركة' }}</h1>
    <p class="mt-1 text-sm text-ink-500">
        @if ($list)
            «{{ $list->name }}» — تسري على تجّار الفرع ما لم تكن للتاجر تسعيرته الخاصّة.
            يحدّدها الفرع الرئيسي، وتُرى هنا ولا تُعدَّل.
        @else
            لا تسعيرة مفعّلة بعد. يحدّدها الفرع الرئيسي من «الفروع».
        @endif
    </p>
</div>

@if ($list)
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="px-3 py-3 text-start font-semibold">الوجهة</th>
                        <th class="px-3 py-3 text-start font-semibold">التوصيل للمركز</th>
                        <th class="px-3 py-3 text-start font-semibold">للأقضية والأطراف</th>
                        <th class="px-3 py-3 text-start font-semibold">الراجع</th>
                        <th class="px-3 py-3 text-start font-semibold">كغم زائد</th>
                        <th class="px-3 py-3 text-start font-semibold">عمولة تحصيل</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @php
                        $rows = collect([['key' => 0, 'label' => 'كل العراق (قاعدة عامة)', 'general' => true]])
                            ->concat($governorates->map(fn ($g) => ['key' => $g->id, 'label' => $g->name_ar, 'general' => false]));
                        $money = fn ($v) => $v === null ? '—' : number_format((int) $v);
                    @endphp

                    @foreach ($rows as $row)
                        @php $rule = $rules[$row['key']] ?? null; @endphp
                        @continue(! $rule && ! $row['general'])
                        <tr class="{{ $row['general'] ? 'bg-ink-50/60' : '' }}">
                            <td class="px-3 py-2.5 font-medium {{ $row['general'] ? 'font-bold' : '' }}">{{ $row['label'] }}</td>
                            <td class="px-3 py-2.5 num">{{ $money($rule?->delivery_fee) }}</td>
                            <td class="px-3 py-2.5 num">{{ $rule?->peripheral_fee !== null ? $money($rule->peripheral_fee) : 'كالمركز' }}</td>
                            <td class="px-3 py-2.5 num">{{ $money($rule?->return_fee) }}</td>
                            <td class="px-3 py-2.5 num">{{ $money($rule?->extra_kg_fee) }}</td>
                            <td class="px-3 py-2.5 num">
                                {{ $money($rule?->cod_fee_flat) }}{{ $rule?->cod_fee_percent ? ' + '.rtrim(rtrim(number_format((float) $rule->cod_fee_percent, 2), '0'), '.').'%' : '' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
            المبالغ بالدينار العراقي. قاعدة المحافظة تغلب القاعدة العامة، وما لم يُذكر يُسعَّر بالعامة.
            @if ($rules->first()?->weight_to_grams)
                الأجرة تشمل حتى {{ number_format($rules->first()->weight_to_grams / 1000, 1) }} كغم، وما زاد بأجرة الكيلو الزائد.
            @endif
        </p>
    </div>
@endif
@endsection

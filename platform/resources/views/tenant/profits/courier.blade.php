@extends('layouts.app')
@section('title', 'الكشف المالي — '.$courier->name)

@section('content')
@include('tenant.profits._tabs', ['title' => 'الكشف المالي للمندوب '.$courier->name,
    'lead' => 'ما بيده الآن، وما سلّمه بكشوفه، وما عليه من عجز، وما لم يُحاسَب عليه بعد — ووجبات التحصيل التي دخلها.'])

<div class="card mb-5 flex flex-wrap items-center justify-between gap-3 p-4">
    <div class="text-sm">
        <a href="{{ route('couriers.show', $courier) }}" class="font-bold hover:underline">{{ $courier->name }}</a>
        <span class="num text-xs text-ink-500">{{ $courier->code }}</span>
        @if ($lastSettlement)
            <div class="text-xs text-ink-500">آخر كشفٍ مُقفَل
                <a href="{{ route('settlements.couriers.show', $lastSettlement) }}" class="num text-[var(--brand)] hover:underline">{{ $lastSettlement->code }}</a>
                <span class="num">{{ $lastSettlement->confirmed_at?->format('Y-m-d H:i') }}</span></div>
        @endif
    </div>
    @if ($openItem)
        <a href="{{ route('profits.batches.show', $openItem->batch) }}" class="btn-ghost">في الوجبة المفتوحة {{ $openItem->batch->number }}</a>
    @elseif ($figures['unsettled_n'] > 0)
        <a href="{{ route('profits.batches.create', ['mode' => 'couriers', 'courier' => $courier->id, 'branch_id' => $courier->branch_id]) }}" class="btn-primary">وجبة تحصيل لهذا المندوب</a>
    @endif
</div>

<div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @include('tenant.profits._figure', ['label' => 'النقد بعهدته الآن', 'value' => $figures['cash_in_hand'],
        'hint' => 'ومنه عجزٌ مكتوب عليه '.number_format($figures['shortage']).' د.ع.'])
    @include('tenant.profits._figure', ['label' => 'لم يُحاسَب عليه بعد', 'value' => $figures['unsettled'],
        'hint' => \App\Support\Arabic::shipments($figures['unsettled_n']).($oldest ? '، أقدمها من '.($oldest->delivered_at ?? $oldest->status_changed_at)?->format('Y-m-d') : '')])
    @include('tenant.profits._figure', ['label' => 'العجز عليه', 'value' => $figures['shortage'], 'tone' => $figures['shortage'] > 0 ? 'text-bad-700' : '',
        'hint' => 'ذمّةٌ من وجبات التحصيل تُسترد بتحصيلٍ في وجبةٍ لاحقة.'])
    @include('tenant.profits._figure', ['label' => 'عمولته المستحقّة', 'value' => $figures['commission'],
        'hint' => 'ما لم يُدفع له من عمولاته.'])
</div>
<div class="mb-5 grid grid-cols-2 gap-3 text-sm md:grid-cols-3 xl:grid-cols-6">
    @foreach ([
        ['شحناتٌ أُسندت إليه', (int) $counts->assigned],
        ['مسلَّمة', (int) $counts->delivered],
        ['راجعة', (int) $counts->returned],
        ['بيده للتوصيل الآن', (int) $counts->active],
        ['محصَّلٌ بكشوفه المُقفَلة', $figures['collected']],
        ['سلّمه للشركة بكشوفه', $figures['handed']],
    ] as [$label, $value])
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">{{ $label }}</div>
            <div class="num mt-1 font-bold">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">وجبات التحصيل التي دخلها</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الوجبة</th><th>حالها</th><th>الكشف</th><th>المتوقَّع</th><th>المستلم</th><th>الفرق</th><th>المعالجة</th></tr></thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>@if ($item->batch)<a href="{{ route('profits.batches.show', $item->batch) }}" class="num text-[var(--brand)] hover:underline">{{ $item->batch->number }}</a>@endif</td>
                        <td class="text-xs">{{ $item->batch?->statusLabel() }}</td>
                        <td class="num">{{ $item->isRemainder() ? 'تحصيل عجز' : $item->settlement?->code }}</td>
                        <td class="num">{{ $item->isRemainder() ? '—' : number_format($item->expected_cash) }}</td>
                        <td class="num">{{ $item->isPending() ? 'بانتظار النقد' : number_format($item->received_cash) }}</td>
                        <td @class(['num font-semibold', 'text-bad-700' => $item->discrepancy() < 0, 'text-info-700' => $item->discrepancy() > 0])>{{ number_format($item->discrepancy()) }}</td>
                        <td class="text-xs">{{ $item->resolution ? \App\Models\CollectionBatchItem::RESOLUTIONS[$item->resolution] : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-500">لم يدخل وجبة تحصيلٍ بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

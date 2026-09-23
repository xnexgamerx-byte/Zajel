@extends('layouts.app')
@section('title', 'مطابقة الدفتر')

@section('content')
@php
    $offMerchants = $balances['merchants'];
    $offCouriers = $balances['couriers'];
    $offShipments = (int) $byMerchant->sum('shipments');
    $clean = $offMerchants->isEmpty() && $offCouriers->isEmpty() && $offShipments === 0;
@endphp

<div class="mb-5">
    <h1 class="page-title">مطابقة الدفتر</h1>
    <p class="mt-1 text-sm text-ink-500">
        هل رصيد كل حسابٍ مجموعُ قيوده؟ وهل قيود كل شحنةٍ مستحقُّها؟ تُفحَص كل ليلة، وهنا الآن.
    </p>
</div>

@if ($clean)
    <section class="card p-10 text-center">
        <p class="text-lg font-bold text-ok-700">الدفتر مطابق.</p>
        <p class="mt-1 text-sm text-ink-500">كل رصيدٍ يساوي قيوده، وكل شحنةٍ قُيِّد لها مستحقّها — لا أكثر ولا أقلّ.</p>
    </section>
@else
    <div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="stat">
            <span class="stat-label">شحنات لا تطابق قيودها</span>
            <span class="stat-value num {{ $offShipments ? 'text-bad-700' : 'text-ok-700' }}">{{ number_format($offShipments) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">تجّار رصيدهم غير قيودهم</span>
            <span class="stat-value num {{ $offMerchants->count() ? 'text-bad-700' : 'text-ok-700' }}">{{ number_format($offMerchants->count()) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">مناديب رصيدهم غير قيودهم</span>
            <span class="stat-value num {{ $offCouriers->count() ? 'text-bad-700' : 'text-ok-700' }}">{{ number_format($offCouriers->count()) }}</span>
        </div>
    </div>

    @if ($offShipments)
        <section class="card mb-5 overflow-hidden">
            <div class="border-b border-ink-200 px-5 py-4">
                <h2 class="card-title">شحناتٌ لا يطابق ما قُيِّد لها مستحقَّها</h2>
                <p class="card-hint">
                    «يجب» ما يستحقّه التاجر عنها بحالها الآن، و«قُيِّد» ما في حسابه عنها فعلاً.
                    فرقٌ موجب: مستحقٌّ لم يُقيَّد. سالب: قُيِّد أكثر ممّا يستحقّ.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>التاجر</th><th>شحنات</th><th>يجب</th><th>قُيِّد</th><th>الفرق</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($byMerchant->sortByDesc(fn ($r) => abs($r->expected - $r->posted)) as $row)
                            <tr class="{{ $merchantId === (int) $row->merchant_id ? 'bg-ink-50' : '' }}">
                                <td class="font-medium">{{ $names[$row->merchant_id] ?? '—' }}</td>
                                <td class="num">{{ number_format($row->shipments) }}</td>
                                <td class="num">{{ number_format($row->expected) }}</td>
                                <td class="num">{{ number_format($row->posted) }}</td>
                                <td class="num font-semibold text-bad-700">{{ number_format($row->expected - $row->posted) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('money.reconcile', ['merchant_id' => $row->merchant_id]) }}" class="btn-ghost">الشحنات</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card mb-5 overflow-hidden">
            <div class="border-b border-ink-200 px-5 py-4">
                <h2 class="card-title">
                    {{ $merchantId ? 'شحنات '.($names[$merchantId] ?? '') : 'أوّل الشحنات' }}
                    <span class="num text-ink-500">({{ number_format($shipments->count()) }}{{ $shipments->count() >= 200 ? '+' : '' }})</span>
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>الوصل</th><th>التاجر</th><th>الحالة</th><th>يجب</th><th>قُيِّد</th><th>الفرق</th></tr></thead>
                    <tbody>
                        @foreach ($shipments as $row)
                            @php $status = \App\Enums\ShipmentStatus::tryFrom($row->status); @endphp
                            <tr>
                                <td><a href="{{ route('shipments.show', $row->id) }}" class="num font-semibold hover:underline">{{ $row->number }}</a></td>
                                <td class="text-ink-600">{{ $names[$row->merchant_id] ?? '—' }}</td>
                                <td>@if ($status)<x-status-badge :status="$status" />@endif</td>
                                <td class="num">{{ number_format($row->expected) }}</td>
                                <td class="num">{{ number_format($row->posted) }}</td>
                                <td class="num font-semibold text-bad-700">{{ number_format($row->expected - $row->posted) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($offMerchants->isNotEmpty() || $offCouriers->isNotEmpty())
        <section class="card overflow-hidden">
            <div class="border-b border-ink-200 px-5 py-4">
                <h2 class="card-title">أرصدةٌ لا تساوي قيودها</h2>
                <p class="card-hint">الرصيد على الحساب تسريعُ قراءةٍ لمجموع قيوده. اختلافهما يعني تعديلاً خارج الدفتر.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>الحساب</th><th>البند</th><th>المخزَّن</th><th>مجموع القيود</th><th>الفرق</th></tr></thead>
                    <tbody>
                        @foreach ($offMerchants as $row)
                            <tr>
                                <td>{{ $row->name }} <span class="chip chip-mute ms-1">تاجر</span></td>
                                <td>الرصيد</td>
                                <td class="num">{{ number_format($row->stored) }}</td>
                                <td class="num">{{ number_format($row->ledger) }}</td>
                                <td class="num font-semibold text-bad-700">{{ number_format($row->stored - $row->ledger) }}</td>
                            </tr>
                        @endforeach
                        @foreach ($offCouriers as $row)
                            @foreach ([['النقد بيده', $row->cash_stored, $row->cash_ledger], ['عمولته', $row->commission_stored, $row->commission_ledger]] as [$label, $stored, $ledger])
                                @continue($stored === $ledger)
                                <tr>
                                    <td>{{ $row->name }} <span class="chip chip-mute ms-1">مندوب</span></td>
                                    <td>{{ $label }}</td>
                                    <td class="num">{{ number_format($stored) }}</td>
                                    <td class="num">{{ number_format($ledger) }}</td>
                                    <td class="num font-semibold text-bad-700">{{ number_format($stored - $ledger) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endif
@endsection

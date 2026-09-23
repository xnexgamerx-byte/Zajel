@extends('layouts.app')
@section('title', 'أرصدة مدينة')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">أرصدة مدينة</h1>
        <p class="mt-1 text-sm text-ink-500">مالٌ لنا عند غيرنا — عند التجّار ديناً، وعند المندوبين نقداً لم يُسلَّم.</p>
    </div>
    <a href="{{ route('reports.index') }}" class="btn-ghost">كل التقارير</a>
</div>

<div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="stat">
        <span class="stat-label">مكشوفٌ بعد الودائع</span>
        <span class="stat-value num text-bad-700">{{ number_format($totals->owed) }}</span>
    </div>
    <div class="stat">
        <span class="stat-label">ودائع تُغطّي</span>
        <span class="stat-value num text-ok-700">{{ number_format($totals->deposits) }}</span>
    </div>
    <div class="stat">
        <span class="stat-label">نقد عند المندوبين</span>
        <span class="stat-value num text-warn-700">{{ number_format($totals->cash) }}</span>
    </div>
    <div class="stat">
        <span class="stat-label">تجاوزوا سقفهم</span>
        <span class="stat-value num {{ $totals->over > 0 ? 'text-bad-700' : 'text-ink-500' }}">{{ number_format($totals->over) }}</span>
    </div>
</div>

<section class="card mb-5 overflow-hidden">
    <div class="border-b border-ink-200 px-5 py-4">
        <h2 class="card-title">تجّار برصيد سالب</h2>
        <p class="card-hint">الرصيد السالب دَينٌ على التاجر — أجورُ رواجع تراكمت بلا تسليمات تقابلها.</p>
    </div>

    @if ($merchants->isEmpty())
        <p class="p-10 text-center text-ink-500">لا تاجر برصيد سالب.</p>
    @else
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>التاجر</th>
                        <th>الهاتف</th>
                        <th>الرصيد</th>
                        <th>وديعته</th>
                        <th>المكشوف</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($merchants as $merchant)
                        @php $exposed = max(0, -$merchant->balance - $merchant->deposit_balance); @endphp
                        <tr>
                            <td>
                                <a href="{{ route('merchants.show', $merchant) }}" class="font-medium hover:underline">{{ $merchant->business_name }}</a>
                                <span class="num block text-xs text-ink-500">{{ $merchant->code }}</span>
                            </td>
                            <td class="num text-ink-600">{{ $merchant->phone }}</td>
                            <td class="num text-bad-700">{{ number_format($merchant->balance) }}</td>
                            <td class="num text-ink-600">{{ number_format($merchant->deposit_balance) }}</td>
                            <td class="num font-semibold {{ $exposed > 0 ? 'text-bad-700' : 'text-ok-700' }}">
                                {{ $exposed > 0 ? number_format($exposed) : 'مُغطّى' }}
                            </td>
                            <td>
                                <span class="chip {{ $merchant->status === 'active' ? 'chip-ok' : 'chip-mute' }}">
                                    {{ $merchant->status === 'active' ? 'نشط' : 'موقوف' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="card overflow-hidden">
    <div class="border-b border-ink-200 px-5 py-4">
        <h2 class="card-title">نقدٌ بيد المندوبين</h2>
        <p class="card-hint">محصَّلٌ ولم يُسلَّم بعدُ إلى القاصّة. ومَن تجاوز سقفه لا يُسلَّم له المزيد حتى يُسوّي.</p>
    </div>

    @if ($couriers->isEmpty())
        <p class="p-10 text-center text-ink-500">لا نقد معلّقاً عند أحد.</p>
    @else
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>المندوب</th>
                        <th>الهاتف</th>
                        <th>بيده</th>
                        <th>سقفه</th>
                        <th>عمولةٌ له</th>
                        <th>صافي التسليم</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($couriers as $courier)
                        @php $over = $courier->cash_limit > 0 && $courier->cash_in_hand > $courier->cash_limit; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('couriers.show', $courier) }}" class="font-medium hover:underline">{{ $courier->name }}</a>
                            </td>
                            <td class="num text-ink-600">{{ $courier->phone }}</td>
                            <td class="num font-semibold {{ $over ? 'text-bad-700' : 'text-warn-700' }}">{{ number_format($courier->cash_in_hand) }}</td>
                            <td>
                                @if ($courier->cash_limit > 0)
                                    <span class="chip {{ $over ? 'chip-bad' : 'chip-mute' }}">{{ number_format($courier->cash_limit) }}</span>
                                @else
                                    <span class="text-xs text-ink-400">بلا سقف</span>
                                @endif
                            </td>
                            <td class="num text-ink-600">{{ number_format($courier->commission_balance) }}</td>
                            {{-- الواجب تسليمه = ما بيده ناقص ما له من عمولة --}}
                            <td class="num">{{ number_format($courier->cash_in_hand - $courier->commission_balance) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection

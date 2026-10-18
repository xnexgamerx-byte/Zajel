@extends('layouts.app')
@section('title', 'كشوفات رواجع المندوبين')

@php
    use App\Http\Controllers\Tenant\CourierReturnController;

    $stageChip = ['with_courier' => 'chip-bad', 'at_hub' => 'chip-warn', 'to_merchant' => 'chip-ok'];
    $only = fn (array $extra) => route('returns.couriers', array_filter($extra + request()->only(['stage', 'settled', 'from', 'to', 'q'])));
@endphp

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">كشوفات رواجع المندوبين</h1>
        <p class="mt-1 text-sm text-ink-500">
            كل راجعٍ رجع على يد مندوب: هل ما زال بيده، أم استلمناه بالمخزن، أم سُلّم لتاجره — وهل حوسب المندوب عليه.
        </p>
    </div>
    <button type="button" data-print class="btn-ghost"><x-icon name="printer" class="size-4"/> اطبع</button>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4 print:hidden">
    <div class="min-w-48 flex-1">
        <label class="field-label" for="q">رقم الوصل أو الزبون</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input" autocomplete="off">
    </div>
    <div class="min-w-48">
        <label class="field-label" for="courier_id">المندوب</label>
        <select id="courier_id" name="courier_id" class="field-input" data-searchable>
            <option value="">كل المناديب</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="stage">مرحلة الراجع</label>
        <select id="stage" name="stage" class="field-input">
            <option value="">الكل</option>
            @foreach ($stages as $key => $label)
                <option value="{{ $key }}" @selected(request('stage') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="settled">المحاسبة مع المندوب</label>
        <select id="settled" name="settled" class="field-input">
            <option value="">الكل</option>
            <option value="yes" @selected(request('settled') === 'yes')>حوسب</option>
            <option value="no" @selected(request('settled') === 'no')>لم يُحاسَب بعد</option>
        </select>
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ request('from') }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ request('to') }}" class="field-input">
    </div>
    <button type="submit" class="btn-primary">بحث</button>
    <a href="{{ route('returns.couriers') }}" class="btn-ghost">مسح البحث</a>
</form>

{{-- ملخّص كلّ مندوب: يُضغط اسمه فيُعرض راجعه وحده --}}
<section class="card mb-5 overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
        <h2 class="card-title">المناديب</h2>
        <p class="card-hint">بحسب التصفية أعلاه. «لم يُحاسَب» ما فيه عمولة راجعٍ أو نقد واصلٍ جزئي لم يدخل كشفاً مُقفَلاً.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>المندوب</th>
                    <th class="num">كل الراجع</th>
                    <th class="num">بيده</th>
                    <th class="num">بالمخزن</th>
                    <th class="num">سُلّم للتاجر</th>
                    <th class="num">لم يُحاسَب</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($summary as $row)
                    <tr @class(['bg-ink-50' => (int) request('courier_id') === $row->courier_id])>
                        <td><a href="{{ $only(['courier_id' => $row->courier_id]) }}" class="font-semibold text-[var(--brand)] hover:underline">{{ $row->name }}</a></td>
                        <td class="num font-semibold">{{ number_format($row->total) }}</td>
                        <td class="num {{ $row->with_courier ? 'font-semibold text-bad-700' : 'text-ink-400' }}">{{ number_format($row->with_courier) }}</td>
                        <td class="num">{{ number_format($row->at_hub) }}</td>
                        <td class="num">{{ number_format($row->to_merchant) }}</td>
                        <td class="num {{ $row->unsettled ? 'font-semibold text-warn-700' : 'text-ink-400' }}">{{ number_format($row->unsettled) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-500">لا رواجع مناديب بهذه التصفية.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
        <div>
            <h2 class="card-title">الرواجع شحنةً شحنة</h2>
            <p class="card-hint">العدد: <b class="num">{{ number_format($returns->total()) }}</b></p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الوصل</th>
                    <th>المندوب</th>
                    <th>التاجر</th>
                    <th>السبب</th>
                    <th class="num">المبلغ</th>
                    <th>المرحلة</th>
                    <th>استلامه من المندوب</th>
                    <th>تسليمه للتاجر</th>
                    <th>المحاسبة مع المندوب</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($returns as $shipment)
                    @php
                        $stage = CourierReturnController::stageOf($shipment);
                        [$settledText, $settledChip] = CourierReturnController::settlementOf($shipment);
                    @endphp
                    <tr>
                        <td><a href="{{ route('shipments.show', $shipment) }}" class="num font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $shipment->number }}</a></td>
                        <td class="whitespace-nowrap">{{ $shipment->deliveryCourier?->name ?? '—' }}</td>
                        <td>{{ $shipment->merchant?->business_name }}</td>
                        <td class="text-xs text-ink-600">{{ $shipment->returnReason() ?? '—' }}</td>
                        <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                        <td><span class="chip {{ $stageChip[$stage] }}">{{ $stages[$stage] }}</span></td>
                        <td class="text-xs">
                            @if ($shipment->return_received_at)
                                <span class="num">{{ $shipment->return_received_at->format('Y-m-d H:i') }}</span>
                                @if ($shipment->returnReceivedBy) <div class="text-ink-500">{{ $shipment->returnReceivedBy->name }}</div> @endif
                            @else
                                <span class="font-semibold text-bad-700">لم يُستلم منه بعد</span>
                            @endif
                        </td>
                        <td class="text-xs">
                            @if ($stage === 'to_merchant')
                                <span class="num">{{ $shipment->returned_at?->format('Y-m-d H:i') }}</span>
                                @if ($shipment->returnBatch) <div class="num text-ink-500">إيصال {{ $shipment->returnBatch->number }}</div> @endif
                            @else
                                <span class="text-ink-400">لم يُسلَّم بعد</span>
                            @endif
                        </td>
                        <td>
                            @if ($shipment->courierSettlement && Gate::allows('money.view'))
                                <a href="{{ route('settlements.couriers.show', $shipment->courierSettlement) }}" class="chip {{ $settledChip }} hover:underline">{{ $settledText }}</a>
                            @else
                                <span class="chip {{ $settledChip }}">{{ $settledText }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-12 text-center text-ink-500">لا رواجع بهذه التصفية.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-ink-100 px-5 py-3 print:hidden">{{ $returns->links() }}</div>
</section>
@endsection

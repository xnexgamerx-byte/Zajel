@extends('layouts.app')
@section('title', 'إنشاء وجبة تحصيل')

@section('content')
@include('tenant.profits._tabs', [
    'title' => 'إنشاء وجبة تحصيل',
    'lead'  => 'من كشوفٍ أُقفلت في «محاسبة المندوبين»، أو الوجبة أولاً: تختار المناديب فتُبنى كشوفهم، ويُقفَل كشف كلٍّ منهم حين يُستلم نقده. الكشف يدخل وجبةً واحدة لا غير.',
])

<nav class="mb-4 flex flex-wrap gap-2" aria-label="طريقة الإنشاء">
    <a href="{{ route('profits.batches.create', array_filter(['branch_id' => $branchId])) }}" @class(['btn-ghost', 'btn-primary' => $mode === 'settlements']) @if ($mode === 'settlements') aria-current="page" @endif>من كشوفٍ مُقفَلة</a>
    <a href="{{ route('profits.batches.create', array_filter(['mode' => 'couriers', 'branch_id' => $branchId])) }}" @class(['btn-ghost', 'btn-primary' => $mode === 'couriers']) @if ($mode === 'couriers') aria-current="page" @endif>الوجبة أولاً: اختر المناديب</a>
</nav>

@if ($mode === 'couriers')
    @if ($branches->isNotEmpty())
        <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
            <input type="hidden" name="mode" value="couriers">
            <div class="min-w-44">
                <label class="field-label" for="branch_id">الفرع</label>
                <select id="branch_id" name="branch_id" class="field-input">
                    <option value="">كل الفروع</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-ghost">عرض المناديب</button>
        </form>
    @endif

    <form method="POST" action="{{ route('profits.batches.store') }}">
        @csrf
        <input type="hidden" name="mode" value="couriers">
        <input type="hidden" name="branch_id" value="{{ $branchId }}">
        <div class="card mb-4 overflow-hidden">
            <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">
                مناديب بيدهم ما يُحاسَبون عليه
                <span class="font-normal text-ink-500">({{ \App\Support\Arabic::count($couriers->count(), ['مندوب واحد', 'مندوبَين', 'مناديب', 'مندوباً']) }})</span>
            </h2>
            @error('courier_ids') <p class="field-error px-5 pt-3">{{ $message }}</p> @enderror
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th class="w-8"><input type="checkbox" aria-label="تحديد الكل" class="size-4 accent-[var(--brand)]" data-check-all-in="table"></th>
                            <th>المندوب</th>
                            <th>النقد بعهدته</th>
                            <th>عمولته المستحقّة</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($couriers as $courier)
                            <tr>
                                <td><input type="checkbox" name="courier_ids[]" value="{{ $courier->id }}" class="size-4 accent-[var(--brand)]" aria-label="{{ $courier->name }}"
                                           @checked(in_array($courier->id, array_map('intval', (array) old('courier_ids', [request()->integer('courier')])), true))></td>
                                <td>{{ $courier->name }} <span class="num text-xs text-ink-500">{{ $courier->code }}</span></td>
                                <td class="num font-semibold">{{ number_format($courier->cash_in_hand) }}</td>
                                <td class="num">{{ number_format($courier->commission_balance) }}</td>
                                <td><a href="{{ route('profits.couriers.show', $courier) }}" class="text-xs text-[var(--brand)] hover:underline">كشفه المالي</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-14 text-center text-ink-500">لا مندوب بيده شحناتٌ واصلة لم تُحاسَب، أو كلّهم في وجباتٍ مفتوحة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($couriers->isNotEmpty())
            <div class="card flex flex-wrap items-end gap-3 p-4">
                @include('tenant.profits._box')
                <div class="min-w-64 flex-1">
                    <label class="field-label" for="notes">ملاحظة (اختياري)</label>
                    <input id="notes" name="notes" value="{{ old('notes') }}" maxlength="500" class="field-input">
                </div>
                <button type="submit" class="btn-primary">إنشاء الوجبة للمناديب المحدّدين</button>
                <p class="w-full text-xs text-ink-500">يُبنى لكل مندوبٍ كشفه مسودّةً بشحناته الواصلة، ويُقفَل من داخل الوجبة حين تستلم نقده وتعدّه.</p>
            </div>
        @endif
    </form>
@else

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="period">المدّة</label>
        <select id="period" name="period" class="field-input">
            @foreach (\App\Models\CollectionBatch::PERIODS as $key => [$label])
                <option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="field-input">
    </div>
    @if ($branches->isNotEmpty())
        <div class="min-w-44">
            <label class="field-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id" class="field-input">
                <option value="">كل الفروع</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <button type="submit" class="btn-ghost">عرض الكشوف</button>
    <p class="w-full text-xs text-ink-500">الأسبوعية والـ١٥ يوماً والشهرية تنتهي اليوم؛ و«فترة أحدّدها» بالتاريخين.</p>
</form>

@if ($older > 0)
    <div class="alert alert-warn mb-4">قبل هذه المدّة {{ number_format($older) }} كشفٌ مُقفَل لم يدخل وجبةً بعد — وسّع المدّة إن أردت حسابه.</div>
@endif

<form method="POST" action="{{ route('profits.batches.store') }}">
    @csrf
    <input type="hidden" name="period" value="{{ $period }}">
    <input type="hidden" name="from" value="{{ $from->toDateString() }}">
    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
    <input type="hidden" name="branch_id" value="{{ $branchId }}">

    <div class="card mb-4 overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">
            كشوف المناديب المُقفَلة من {{ $from->format('Y-m-d') }} إلى {{ $to->format('Y-m-d') }}
            <span class="font-normal text-ink-500">({{ \App\Support\Arabic::count($settlements->count(), ['كشف واحد', 'كشفان', 'كشوف', 'كشفاً']) }} لـ{{ \App\Support\Arabic::count($settlements->pluck('courier_id')->unique()->count(), ['مندوب واحد', 'مندوبَين', 'مناديب', 'مندوباً']) }})</span>
        </h2>
        @error('settlement_ids') <p class="field-error px-5 pt-3">{{ $message }}</p> @enderror
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="w-8"><input type="checkbox" aria-label="تحديد الكل" class="size-4 accent-[var(--brand)]" data-check-all-in="table" checked></th>
                        <th>المندوب</th>
                        <th>الكشف</th>
                        <th>أُقفل</th>
                        <th>الشحنات</th>
                        <th>المحصَّل</th>
                        <th>عمولته</th>
                        <th>المسلَّم للشركة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($settlements as $settlement)
                        <tr>
                            <td><input type="checkbox" name="settlement_ids[]" value="{{ $settlement->id }}" class="size-4 accent-[var(--brand)]" checked aria-label="{{ $settlement->code }}"></td>
                            <td>{{ $settlement->courier?->name ?? '—' }} <span class="num text-xs text-ink-500">{{ $settlement->courier?->code }}</span></td>
                            <td><a href="{{ route('settlements.couriers.show', $settlement) }}" class="num text-[var(--brand)] hover:underline" target="_blank">{{ $settlement->code }}</a></td>
                            <td class="text-xs"><span class="num">{{ $settlement->confirmed_at?->format('Y-m-d H:i') }}</span>@if ($settlement->confirmedBy)<div class="text-ink-500">{{ $settlement->confirmedBy->name }}</div>@endif</td>
                            <td class="num">{{ number_format($settlement->shipments_count) }}</td>
                            <td class="num">{{ number_format($settlement->cod_total) }}</td>
                            <td class="num">{{ number_format($settlement->commission_total) }}</td>
                            <td class="num font-semibold">{{ number_format($settlement->net_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-14 text-center text-ink-500">لا كشف مندوبٍ مُقفَلٍ في هذه المدّة لم يدخل وجبة. حاسِب المناديب أولاً من «محاسبة المندوبين».</td></tr>
                    @endforelse
                </tbody>
                @if ($settlements->isNotEmpty())
                    <tfoot>
                        <tr class="font-bold">
                            <td colspan="4">المجموع</td>
                            <td class="num">{{ number_format($settlements->sum('shipments_count')) }}</td>
                            <td class="num">{{ number_format($settlements->sum('cod_total')) }}</td>
                            <td class="num">{{ number_format($settlements->sum('commission_total')) }}</td>
                            <td class="num">{{ number_format($settlements->sum('net_amount')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    @if ($settlements->isNotEmpty())
        <div class="card flex flex-wrap items-end gap-3 p-4">
            @include('tenant.profits._box')
            <div class="min-w-64 flex-1">
                <label class="field-label" for="notes">ملاحظة (اختياري)</label>
                <input id="notes" name="notes" value="{{ old('notes') }}" maxlength="500" class="field-input">
            </div>
            <button type="submit" class="btn-primary">إنشاء الوجبة بالكشوف المحدّدة</button>
        </div>
    @endif
</form>
@endif
@endsection

@extends('layouts.portal')
@section('title', 'الطابعة')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">الطابعة</h1>
    <a href="{{ route('portal.shipments.create') }}" class="btn-primary">+ شحنة جديدة</a>
</div>

<nav class="tab-nav mb-3" aria-label="شحناتي">
    <a href="{{ route('portal.shipments.index') }}" class="tab-link">شحناتي</a>
    <a href="{{ route('portal.shipments.index', ['tab' => 'print']) }}" class="tab-link tab-link-active">
        الطابعة — الجديدة
        <span class="nav-badge">{{ number_format($printer['to_print'] + $printer['printed']) }}</span>
    </a>
    <a href="{{ route('portal.shipments.index', ['tab' => 'archive']) }}" class="tab-link">
        المؤرشفة — راجعٌ سُلّم لك
        <span class="nav-badge">{{ number_format($archived) }}</span>
    </a>
</nav>

{{-- الجديدة التي لم يستلمها المندوب: «للطباعة»، ثم «مطبوعة» بعد أوّل طباعة لوصلها (docs/plan/79) --}}
<div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-xl">
    @foreach ([
        ['to_print', 'للطباعة', 'لم يُطبع وصلها بعد', 'bg-warn-soft text-warn-deep'],
        ['printed', 'مطبوعة', 'تنتظر مندوب الاستلام', 'bg-ok-soft text-ok-deep'],
    ] as [$key, $label, $hint, $tone])
        @php $active = ($key === 'printed') === $printed; @endphp
        <a href="{{ route('portal.shipments.index', ['tab' => 'print'] + ($key === 'printed' ? ['state' => 'printed'] : [])) }}"
           @class(['kpi kpi-tonal', $tone, 'ring-2 ring-[var(--brand)]' => $active]) @if ($active) aria-current="page" @endif>
            <span class="kpi-icon"><x-icon :name="$key === 'printed' ? 'check' : 'printer'" class="size-6"/></span>
            <div class="min-w-0">
                <div class="kpi-value num">{{ number_format($printer[$key]) }}</div>
                <div class="kpi-label">{{ $label }} · {{ $hint }}</div>
            </div>
        </a>
    @endforeach
</div>

<form method="GET" action="{{ route('portal.shipments.labels') }}" target="_blank" class="card overflow-hidden" data-printer>
    <div class="flex flex-wrap items-end gap-3 border-b border-ink-100 p-4">
        <div>
            <label class="field-label" for="size">المقاس</label>
            <select id="size" name="size" class="field-input">
                @foreach (\App\Http\Controllers\ShipmentLabelController::SIZES as $key => [$name])
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-primary" @disabled($shipments->isEmpty())>
            <x-icon name="printer" class="size-5"/>
            {{ $printed ? 'أعد طباعة المحدّد' : 'اطبع المحدّد' }}
        </button>
        <p class="text-sm text-ink-500">
            {{ $printed ? 'طُبعت وصولها، وتبقى هنا حتى يستلمها المندوب.' : 'ما تطبعه ينتقل إلى «مطبوعة».' }}
        </p>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th class="w-10">
                        <input type="checkbox" aria-label="تحديد الكل" class="size-4 accent-[var(--brand)]" data-check-all-in="[data-printer]" checked>
                    </th>
                    <th>رقم الوصل</th>
                    <th>الزبون</th>
                    <th>الوجهة</th>
                    <th>المطلوب</th>
                    <th>{{ $printed ? 'طُبع' : 'أُنشئت' }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($shipments as $shipment)
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3">
                            <input type="checkbox" name="ids[]" value="{{ $shipment->id }}" checked
                                   aria-label="تحديد {{ $shipment->number }}" class="size-4 accent-[var(--brand)]">
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('portal.shipments.show', $shipment) }}"
                               class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $shipment->number }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $shipment->recipient_name }}</div>
                            <div class="text-xs text-ink-500" dir="ltr">{{ $shipment->recipient_phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-ink-600">
                            {{ $shipment->governorate?->name_ar }}
                            @if ($shipment->city)
                                <span class="text-xs text-ink-400">· {{ $shipment->city->name_ar }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</td>
                        <td class="px-4 py-3 text-xs text-ink-500" dir="ltr">
                            {{ ($printed ? $shipment->printed_at : $shipment->created_at)?->format('Y-m-d H:i') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-16 text-center text-ink-500">
                            {{ $printed ? 'لا شحنات مطبوعة تنتظر المندوب.' : 'لا شحنات جديدة للطباعة.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
    @endif
</form>
@endsection

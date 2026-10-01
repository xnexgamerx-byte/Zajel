@extends('layouts.app')
@section('title', $merchant ? 'الشحنات المؤرشفة — '.$merchant->business_name : 'الشحنات المؤرشفة')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">الشحنات المؤرشفة</h1>
        <p class="mt-1 text-sm text-ink-500">
            ما رجع إلى تاجره وسُلّم له: انتهى عمله فخرج من قائمة الشحنات الجارية، ويُحفظ هنا — لكل تاجرٍ قائمته.
        </p>
    </div>
    @if ($merchant)
        <a href="{{ route('shipments.archive') }}" class="btn-ghost">كل التجّار</a>
    @endif
</div>

@if (! $merchant)
    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-64 flex-1">
            <label class="field-label" for="q">التاجر</label>
            <input id="q" name="q" value="{{ request('q') }}" class="field-input" placeholder="اسم المتجر">
        </div>
        <button type="submit" class="btn-primary">بحث</button>
        @if (request('q'))
            <a href="{{ route('shipments.archive') }}" class="btn-ghost">مسح</a>
        @endif
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>التاجر</th>
                        <th>راجعٌ سُلّم له</th>
                        <th>مجموع مبالغها</th>
                        <th>آخر تسليم</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $owner = $merchants[$row->merchant_id] ?? null; @endphp
                        <tr>
                            <td class="px-4 py-3 font-medium">
                                {{ $owner?->business_name ?? '—' }}
                                @if ($owner?->is_vip)<span class="chip chip-info ms-1">مميّز</span>@endif
                            </td>
                            <td class="px-4 py-3">{{ \App\Support\Arabic::shipments((int) $row->total) }}</td>
                            <td class="num px-4 py-3">{{ number_format((int) $row->cod) }}</td>
                            <td class="px-4 py-3 text-xs text-ink-500" dir="ltr">
                                {{ $row->last_returned_at ? \Illuminate\Support\Carbon::parse($row->last_returned_at)->format('Y-m-d') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-end">
                                <a href="{{ route('shipments.archive', ['merchant_id' => $row->merchant_id]) }}"
                                   class="text-sm font-semibold text-[var(--brand)] hover:underline">قائمته</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-16 text-center text-ink-500">
                                {{ request('q') ? 'لا تاجر بهذا الاسم له راجعٌ مسلَّم.' : 'لم يُسلَّم راجعٌ لتاجرٍ بعد.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $rows->links() }}</div>
        @endif
    </div>
@else
    <section class="card mb-4 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="text-xs text-ink-500">قائمة التاجر</div>
                <h2 class="font-heading text-xl font-medium text-aeblack-950">{{ $merchant->business_name }}</h2>
            </div>
            <dl class="flex flex-wrap gap-6 text-sm">
                <div><dt class="text-ink-500">راجعٌ سُلّم له</dt>
                     <dd class="num text-lg font-semibold">{{ number_format((int) $summary->total) }}</dd></div>
                <div><dt class="text-ink-500">مجموع مبالغها</dt>
                     <dd class="num text-lg font-semibold">{{ number_format((int) $summary->cod) }}</dd></div>
                <div><dt class="text-ink-500">أجور الراجع</dt>
                     <dd class="num text-lg font-semibold">{{ number_format((int) $summary->fees) }}</dd></div>
            </dl>
        </div>

        <form method="GET" class="mt-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="merchant_id" value="{{ $merchant->id }}">
            <div class="min-w-56 flex-1">
                <label class="field-label" for="q">بحث</label>
                <input id="q" name="q" value="{{ request('q') }}" class="field-input" placeholder="رقم وصل · هاتف الزبون · رقم طلب التاجر">
            </div>
            <div>
                <label class="field-label" for="from">سُلّم من</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" class="field-input">
            </div>
            <div>
                <label class="field-label" for="to">إلى</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" class="field-input">
            </div>
            <button type="submit" class="btn-primary">بحث</button>
            @if (request('q') || request('from') || request('to'))
                <a href="{{ route('shipments.archive', ['merchant_id' => $merchant->id]) }}" class="btn-ghost">مسح</a>
            @endif
            @can('shipments.export')
                <a href="{{ route('shipments.export', ['status' => 'returned', 'merchant_id' => $merchant->id]) }}" class="btn-ghost ms-auto">
                    <x-icon name="download" class="size-5"/> Excel
                </a>
            @endcan
        </form>
    </section>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>رقم الوصل</th>
                        <th>الزبون</th>
                        <th>الوجهة</th>
                        <th>المبلغ</th>
                        <th>سبب الراجع</th>
                        <th>سُلّم للتاجر</th>
                        <th>الإيصال</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shipments as $shipment)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('shipments.show', $shipment) }}" class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $shipment->number }}</a>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $shipment->recipient_name }}</div>
                                <div class="text-xs text-ink-500"><x-phone :number="$shipment->recipient_phone" :name="$shipment->recipient_name" /></div>
                            </td>
                            <td class="px-4 py-3 text-ink-700">
                                {{ $shipment->governorate?->name_ar }}
                                @if ($shipment->city)<span class="text-xs text-ink-500"> · {{ $shipment->city->name_ar }}</span>@endif
                            </td>
                            <td class="num px-4 py-3 font-semibold">{{ number_format($shipment->cod_amount) }}</td>
                            <td class="px-4 py-3 text-ink-700">{{ $shipment->lastFailureReason?->name_ar ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs text-ink-500" dir="ltr">{{ $shipment->returned_at?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($shipment->returnBatch)
                                    @can('returns.manage')
                                        <a href="{{ route('return-batches.print', $shipment->returnBatch) }}" class="font-mono text-[var(--brand)] hover:underline" target="_blank" rel="noopener" dir="ltr">{{ $shipment->returnBatch->number }}</a>
                                    @else
                                        <span class="font-mono" dir="ltr">{{ $shipment->returnBatch->number }}</span>
                                    @endcan
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-16 text-center text-ink-500">لا راجع مسلَّم لهذا التاجر يطابق البحث.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($shipments->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
        @endif
    </div>

    <p class="mt-3 text-xs text-ink-500">إجمالي النتائج: {{ number_format($shipments->total()) }}</p>
@endif
@endsection

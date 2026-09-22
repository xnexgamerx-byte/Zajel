@extends('layouts.courier')
@section('title', 'مهامي')

@section('content')
<div class="mb-4 grid grid-cols-2 gap-3">
    <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
        <div class="text-xs text-ink-500">شحنات بيدك</div>
        <div class="mt-0.5 text-3xl font-bold">{{ number_format($count) }}</div>
    </div>
    <div class="rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
        <div class="text-xs text-ink-500">المطلوب تحصيله</div>
        <div class="mt-0.5 text-2xl font-bold text-warn-700"><span class="num">{{ number_format($toCollect) }}</span>
        </div>
    </div>
</div>

<form method="GET" action="{{ route('courier.search') }}" class="mb-4 flex gap-2">
    <input name="q" class="field-input flex-1 text-base" inputmode="numeric"
           placeholder="رقم الوصل أو هاتف الزبون" required>
    <button type="submit" class="btn-primary shrink-0 px-5">ابحث</button>
</form>

@forelse ($tasks as $governorate => $group)
    <h2 class="mb-2 mt-4 px-1 text-sm font-bold text-ink-600">
        {{ $governorate }}
        <span class="font-normal text-ink-400">({{ $group->count() }})</span>
    </h2>

    <div class="space-y-2">
        @foreach ($group as $shipment)
            <a href="{{ route('courier.shipments.show', $shipment) }}"
               class="block rounded-xl border border-ink-200 bg-white p-4 shadow-xs active:bg-ink-50">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-base font-bold">{{ $shipment->recipient_name }}</div>
                        <div class="mt-0.5 text-sm text-ink-500" dir="ltr">{{ $shipment->recipient_phone }}</div>
                    </div>
                    <div class="shrink-0 text-end">
                        <div class="text-lg font-bold"><span class="num">{{ number_format($shipment->cod_amount) }}</span></div>
                        <div class="text-xs text-ink-400">د.ع</div>
                    </div>
                </div>

                <div class="mt-2 border-t border-ink-100 pt-2 text-sm">
                    @if ($shipment->city)
                        <span class="font-medium text-ink-700">{{ $shipment->city->name_ar }}</span> ·
                    @endif
                    <span class="text-ink-600">{{ $shipment->landmark }}</span>
                </div>

                <div class="mt-2 flex items-center gap-2 text-xs">
                    <span class="font-mono text-ink-400" dir="ltr">{{ $shipment->number }}</span>
                    @if ($shipment->attempts_count > 0)
                        <span class="rounded bg-warn-50 px-1.5 py-0.5 font-semibold text-warn-700">
                            محاولة {{ $shipment->attempts_count + 1 }}
                        </span>
                    @endif
                    @if ($shipment->is_fragile)
                        <span class="rounded bg-bad-50 px-1.5 py-0.5 font-semibold text-bad-700">قابل للكسر</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
@empty
    <div class="rounded-xl border border-ink-200 bg-white p-10 text-center shadow-xs">
        <p class="font-semibold text-ink-700">ما عندك شحنات الآن.</p>
        <p class="mt-1 text-sm text-ink-500">راجع الشركة أو انتظر التوزيع.</p>
    </div>
@endforelse
@endsection

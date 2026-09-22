@extends('layouts.courier')
@section('title', 'مهامي')

@section('content')
<div class="mb-4 grid grid-cols-2 gap-3">
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">شحنات بيدك</div>
        <div class="mt-0.5 text-3xl font-bold">{{ number_format($count) }}</div>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">المطلوب تحصيله</div>
        <div class="mt-0.5 text-2xl font-bold text-amber-700" dir="ltr">
            {{ number_format($toCollect) }}
        </div>
    </div>
</div>

<form method="GET" action="{{ route('courier.search') }}" class="mb-4 flex gap-2">
    <input name="q" class="field-input flex-1 text-base" inputmode="numeric"
           placeholder="رقم الوصل أو هاتف الزبون" required>
    <button type="submit" class="btn-primary shrink-0 px-5">ابحث</button>
</form>

@forelse ($tasks as $governorate => $group)
    <h2 class="mb-2 mt-4 px-1 text-sm font-bold text-slate-600">
        {{ $governorate }}
        <span class="font-normal text-slate-400">({{ $group->count() }})</span>
    </h2>

    <div class="space-y-2">
        @foreach ($group as $shipment)
            <a href="{{ route('courier.shipments.show', $shipment) }}"
               class="block rounded-xl bg-white p-4 shadow-sm active:bg-slate-50">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-base font-bold">{{ $shipment->recipient_name }}</div>
                        <div class="mt-0.5 text-sm text-slate-500" dir="ltr">{{ $shipment->recipient_phone }}</div>
                    </div>
                    <div class="shrink-0 text-end">
                        <div class="text-lg font-bold" dir="ltr">{{ number_format($shipment->cod_amount) }}</div>
                        <div class="text-xs text-slate-400">د.ع</div>
                    </div>
                </div>

                <div class="mt-2 border-t border-slate-100 pt-2 text-sm">
                    @if ($shipment->city)
                        <span class="font-medium text-slate-700">{{ $shipment->city->name_ar }}</span> ·
                    @endif
                    <span class="text-slate-600">{{ $shipment->landmark }}</span>
                </div>

                <div class="mt-2 flex items-center gap-2 text-xs">
                    <span class="font-mono text-slate-400" dir="ltr">{{ $shipment->number }}</span>
                    @if ($shipment->attempts_count > 0)
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">
                            محاولة {{ $shipment->attempts_count + 1 }}
                        </span>
                    @endif
                    @if ($shipment->is_fragile)
                        <span class="rounded bg-red-100 px-1.5 py-0.5 font-semibold text-red-700">قابل للكسر</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
@empty
    <div class="rounded-xl bg-white p-10 text-center shadow-sm">
        <p class="font-semibold text-slate-700">ما عندك شحنات الآن.</p>
        <p class="mt-1 text-sm text-slate-500">راجع الشركة أو انتظر التوزيع.</p>
    </div>
@endforelse
@endsection

@extends('layouts.app')
@section('title', 'طلبات كشف راجع للتجّار')

@section('content')
<div class="mb-5">
    <h1 class="page-title">طلبات كشف راجع للتجّار</h1>
    <p class="mt-1 text-sm text-ink-500">
        تجّارٌ طلبوا رواجعهم من بواباتهم. سلّمها من المخزن أو مع مندوب استلامه — ويُغلق الطلب وحده بإيصالها.
        تنتظر الآن: {{ number_format($open) }}.
    </p>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-48 flex-1">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($merchants as $merchant)
                <option value="{{ $merchant->id }}" @selected((int) request('merchant_id') === $merchant->id)>{{ $merchant->business_name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="pickup_courier_id">مندوب الاستلام</label>
        <select id="pickup_courier_id" name="pickup_courier_id" class="field-input">
            <option value="">الكل</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('pickup_courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-36">
        <label class="field-label" for="status">تمّت معالجته؟</label>
        <select id="status" name="status" class="field-input">
            <option value="open" @selected(request('status', 'open') === 'open')>لا — بانتظار</option>
            <option value="handled" @selected(request('status') === 'handled')>نعم</option>
            <option value="all" @selected(request('status') === 'all')>الكل</option>
        </select>
    </div>
    <button type="submit" class="btn-primary">بحث</button>
    <a href="{{ route('returns.requests') }}" class="btn-ghost">مسح البحث</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>التاجر</th>
                    <th>المنطقة</th>
                    <th>مندوب الاستلام</th>
                    <th>يُعطى للمندوب؟</th>
                    <th>على الرفّ الآن</th>
                    <th>ملاحظته</th>
                    <th>تمّت المعالجة؟</th>
                    <th>تاريخ الطلب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $req)
                    @php $m = $req->merchant; $onShelf = (int) ($ready[$req->merchant_id] ?? 0); @endphp
                    <tr>
                        <td class="num font-semibold whitespace-nowrap">{{ $req->number }}</td>
                        <td>
                            <span class="font-medium">{{ $m?->business_name ?? '—' }}</span>
                            <div class="text-xs text-ink-500"><x-phone :number="$m?->phone" :name="$m?->business_name" /></div>
                        </td>
                        <td class="text-sm">{{ collect([$m?->governorate?->name_ar, $m?->city?->name_ar])->filter()->implode(' · ') ?: '—' }}</td>
                        <td>{{ $m?->pickupCourier?->name ?? '—' }}</td>
                        <td>@if ($req->via_pickup_courier)<span class="chip chip-info">نعم</span>@else<span class="text-ink-400">لا</span>@endif</td>
                        <td class="num {{ $onShelf ? 'font-semibold' : 'text-ink-400' }}">{{ number_format($onShelf) }}</td>
                        <td class="max-w-56 text-sm text-ink-600">{{ $req->note ?? '—' }}</td>
                        <td class="text-xs">
                            @if ($req->status === 'handled')
                                <span class="chip chip-ok">نعم</span>
                                @if ($req->returnBatch)
                                    <a href="{{ route('return-batches.print', $req->returnBatch) }}" target="_blank" class="num ms-1 hover:underline">{{ $req->returnBatch->number }}</a>
                                @elseif ($req->handledBy)
                                    <div class="text-ink-500">{{ $req->handledBy->name }}</div>
                                @endif
                            @elseif ($req->status === 'cancelled')
                                <span class="chip chip-mute">ألغاه التاجر</span>
                            @else
                                <span class="chip chip-warn">لا</span>
                            @endif
                        </td>
                        <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $req->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            @if ($req->isOpen())
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($onShelf)
                                        @if ($req->via_pickup_courier && $m?->pickup_courier_id)
                                            <a href="{{ route('returns.pickup', ['courier_id' => $m->pickup_courier_id]) }}" class="btn-primary py-1 text-xs whitespace-nowrap">سلّمها لمندوبه</a>
                                        @else
                                            <a href="{{ route('returns.outgoing', ['merchant_id' => $req->merchant_id]) }}" class="btn-primary py-1 text-xs whitespace-nowrap">سلّمها له</a>
                                        @endif
                                    @endif
                                    <form method="POST" action="{{ route('returns.requests.handle', $req) }}">
                                        @csrf
                                        <button class="btn-ghost py-1 text-xs whitespace-nowrap" title="عولج بطريقٍ آخر">أغلِقه</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-16 text-center text-ink-500">لا طلب كشف راجعٍ بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $requests->links() }}</div>
    @endif
</div>
@endsection

@extends('layouts.app')
@section('title', 'طلبات محاسبة من التجّار')

@section('content')
<div class="mb-5">
    <h1 class="page-title">طلبات محاسبة من التجّار</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما طلبه التجّار من بواباتهم: «حاسبوني». يُغلق الطلب وحده حين يُبنى كشفه، ويُربط به.
    </p>
</div>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">طلبات تنتظر</div>
        <div class="mt-1 text-2xl font-bold">{{ number_format($open) }}</div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">مبالغ شحناتهم — بانتظار الدفع</div>
        <div class="mt-1 text-2xl font-bold text-info-700"><span class="num">{{ number_format($gross) }}</span> <span class="text-sm font-medium text-ink-500">د.ع</span></div>
        <div class="mt-1 text-xs text-ink-500">ما حُصّل من شحناتهم الواصلة ولم يدخل كشفاً</div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">المستحقّ لهم — بعد استقطاع الديون</div>
        <div class="mt-1 text-2xl font-bold text-ok-700"><span class="num">{{ number_format($net) }}</span> <span class="text-sm font-medium text-ink-500">د.ع</span></div>
        <div class="mt-1 text-xs text-ink-500">أرصدتهم في الدفتر بعد الأجور والرواجع</div>
    </div>
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
    <div class="min-w-36">
        <label class="field-label" for="payout_method">طريقة الدفع المطلوبة</label>
        <select id="payout_method" name="payout_method" class="field-input">
            <option value="">الكل</option>
            @foreach (\App\Models\Merchant::PAYOUT_METHODS as $key => $label)
                <option value="{{ $key }}" @selected(request('payout_method') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <label class="flex items-center gap-2 pb-2 text-sm">
        <input type="checkbox" name="cancelled" value="1" class="size-4 accent-[var(--brand)]" @checked(request()->boolean('cancelled'))>
        والملغاة (مع «الكل»)
    </label>
    <button type="submit" class="btn-primary">بحث</button>
    <a href="{{ route('merchant-requests.payments') }}" class="btn-ghost">مسح البحث</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>التاجر</th>
                    <th>ملاحظته</th>
                    <th>المنطقة والعنوان</th>
                    <th>مندوب الاستلام</th>
                    <th>يُعطى للمندوب؟</th>
                    <th>طريقة الدفع</th>
                    <th>المستحقّ الآن</th>
                    <th>تمّت المعالجة؟</th>
                    <th>تاريخ الطلب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $req)
                    @php $m = $req->merchant; @endphp
                    <tr>
                        <td class="num font-semibold whitespace-nowrap">{{ $req->number }}</td>
                        <td>
                            @if ($m && auth()->user()->can('settings.merchants'))
                                <a href="{{ route('merchants.show', $m) }}" class="font-medium hover:underline">{{ $m->business_name }}</a>
                            @else
                                <span class="font-medium">{{ $m?->business_name ?? '—' }}</span>
                            @endif
                            <div class="text-xs text-ink-500"><x-phone :number="$m?->phone" :name="$m?->business_name" /></div>
                        </td>
                        <td class="max-w-56 text-sm text-ink-600">{{ $req->note ?? '—' }}</td>
                        <td class="text-sm">
                            {{ collect([$m?->governorate?->name_ar, $m?->city?->name_ar])->filter()->implode(' · ') ?: '—' }}
                            @if ($m?->address)<div class="text-xs text-ink-500">{{ $m->address }}</div>@endif
                        </td>
                        <td>{{ $m?->pickupCourier?->name ?? '—' }}</td>
                        <td>@if ($req->via_pickup_courier)<span class="chip chip-info">نعم</span>@else<span class="text-ink-400">لا</span>@endif</td>
                        <td>{{ \App\Models\Merchant::PAYOUT_METHODS[$req->payout_method] ?? '—' }}</td>
                        <td class="num {{ ($m?->balance ?? 0) > 0 ? 'text-ok-700' : 'text-bad-700' }}">{{ number_format($m?->balance ?? 0) }}</td>
                        <td class="text-xs">
                            @if ($req->status === 'handled')
                                <span class="chip chip-ok">نعم</span>
                                @if ($req->settlement)
                                    <a href="{{ route('settlements.merchants.show', $req->settlement) }}" class="num ms-1 hover:underline">{{ $req->settlement->code }}</a>
                                    <x-settlement-status :status="$req->settlement->status" />
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
                                @can('money.settle')
                                    <div class="flex gap-1.5">
                                        <form method="POST" action="{{ route('settlements.merchants.store') }}">
                                            @csrf
                                            <input type="hidden" name="merchant_id" value="{{ $req->merchant_id }}">
                                            <button class="btn-primary py-1 text-xs whitespace-nowrap">ابنِ كشفه</button>
                                        </form>
                                        <form method="POST" action="{{ route('merchant-requests.payments.handle', $req) }}">
                                            @csrf
                                            <button class="btn-ghost py-1 text-xs whitespace-nowrap" title="عولج بطريقٍ آخر">أغلِقه</button>
                                        </form>
                                    </div>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-16 text-center text-ink-500">لا طلب حسابٍ بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $requests->links() }}</div>
    @endif
</div>
@endsection

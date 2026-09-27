@extends('layouts.app')
@section('title', 'شحنات ممسوحة')

@section('content')
<div class="mb-5">
    <h1 class="page-title">شحنات ممسوحة</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما أُنشئ خطأً ومُسح قبل أن يصلنا — بمن مسحه ومتى ولماذا. «استرجاع» يعيدها كما كانت.
    </p>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-48 flex-1">
        <label class="field-label" for="q">رقم الوصل أو هاتف المستلم</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input">
    </div>
    <div class="min-w-48">
        <label class="field-label" for="merchant_id">التاجر</label>
        <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($merchants as $merchant)
                <option value="{{ $merchant->id }}" @selected((int) request('merchant_id') === $merchant->id)>{{ $merchant->business_name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-40">
        <label class="field-label" for="deleted_by">مُسحت من خلال</label>
        <select id="deleted_by" name="deleted_by" class="field-input">
            <option value="">الكل</option>
            @foreach ($deleters as $user)
                <option value="{{ $user->id }}" @selected((int) request('deleted_by') === $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="from">مُسحت من</label>
        <input id="from" type="date" name="from" value="{{ request('from') }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ request('to') }}" class="field-input">
    </div>
    <button type="submit" class="btn-primary">بحث</button>
    <a href="{{ route('shipments.trash') }}" class="btn-ghost">مسح البحث</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>رقم الوصل</th>
                    <th>التاجر</th>
                    <th>المستلم</th>
                    <th>الوجهة</th>
                    <th>المبلغ</th>
                    <th>المرحلة</th>
                    <th>مُسحت</th>
                    <th>السبب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    <tr class="align-top">
                        <td class="num font-semibold whitespace-nowrap" dir="ltr">{{ $shipment->number }}</td>
                        <td>{{ $shipment->merchant?->business_name }}</td>
                        <td>
                            {{ $shipment->recipient_name }}
                            <div class="text-xs text-ink-500"><x-phone :number="$shipment->recipient_phone" :name="$shipment->recipient_name" /></div>
                        </td>
                        <td class="whitespace-nowrap">{{ $shipment->governorate?->name_ar }}@if ($shipment->city) · <span class="text-xs text-ink-500">{{ $shipment->city->name_ar }}</span>@endif</td>
                        <td class="num whitespace-nowrap">{{ number_format($shipment->cod_amount) }}</td>
                        <td><x-status-badge :status="$shipment->status" /></td>
                        <td class="whitespace-nowrap text-sm">
                            {{ $shipment->deleted_by_name ?? '—' }}
                            <div class="num text-xs text-ink-500">{{ $shipment->deleted_at->format('Y-m-d H:i') }}</div>
                        </td>
                        <td class="min-w-48 text-sm text-ink-700">{{ $shipment->delete_reason }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('shipments.trash.restore', $shipment->id) }}">
                                @csrf
                                <button class="btn-ghost py-1">استرجاع</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-16 text-center text-ink-500">
                        {{ request()->hasAny(['q', 'merchant_id', 'deleted_by', 'from', 'to']) ? 'لا شحنة ممسوحة بهذا البحث.' : 'لم تُمسح شحنة.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $shipments->links() }}</div>
    @endif
</div>
@endsection

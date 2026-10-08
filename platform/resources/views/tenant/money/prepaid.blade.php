@extends('layouts.app')
@section('title', 'استلام أجور مدفوعة مقدّماً')

@section('content')
<div class="mb-5">
    <h1 class="page-title">استلام أجور مدفوعة مقدّماً</h1>
    <p class="mt-1 text-sm text-ink-500">
        تاجرٌ يدفع أجور شحناته حين يُرسلها (يُحاسَب مقدّماً) فلا تُخصم من مبالغها: تُقبض هنا في صندوقٍ بإيصال،
        وتعود إلى حسابه حين تُسلَّم الشحنة أو ترجع. وما سُلّم قبل قبض أجرته تُخصم أجرته من مبلغه كالعادة.
    </p>
    <p class="mt-1 text-sm">
        تعطي التاجر مالاً مقدّماً يُستردّ من طلباته الواصلة؟
        <a href="{{ route('merchant-advances.index') }}" class="font-medium text-[var(--brand)] underline">سلف التجّار</a>
    </p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    {{-- من ينتظر القبض: الأقدم أوّلاً --}}
    <section class="card overflow-hidden lg:col-span-1">
        <h2 class="card-title border-b border-ink-100 px-5 py-4">تنتظر القبض</h2>
        <ul class="divide-y divide-ink-100">
            @forelse ($waiting as $row)
                <li>
                    <a href="{{ route('prepaid-fees.index', ['merchant_id' => $row->merchant_id]) }}"
                       @class(['flex items-center justify-between gap-3 px-5 py-3 text-sm hover:bg-primary-50/50', 'bg-primary-50/60' => $merchant?->id === (int) $row->merchant_id])>
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ $names[$row->merchant_id] ?? '—' }}</span>
                            <span class="text-xs text-ink-500">{{ \App\Support\Arabic::shipments((int) $row->shipments) }}</span>
                        </span>
                        <span class="num shrink-0 font-semibold">{{ number_format((int) $row->amount) }}</span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-ink-500">لا أجور تنتظر القبض.</li>
            @endforelse
        </ul>
    </section>

    <section class="card p-5 lg:col-span-2">
        @if (! $merchant)
            <p class="py-10 text-center text-sm text-ink-500">اختر تاجراً من القائمة لترى شحناته وتقبض أجورها.</p>
        @elseif ($shipments->isEmpty())
            <p class="py-10 text-center text-sm text-ink-500">لا شحنة لـ{{ $merchant->business_name }} تنتظر قبض أجرتها.</p>
        @else
            <form method="POST" action="{{ route('prepaid-fees.store') }}"
                  data-confirm="قبض أجور المختارة من {{ $merchant->business_name }} في الصندوق المختار؟">
                @csrf
                <input type="hidden" name="merchant_id" value="{{ $merchant->id }}">

                <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="card-title">{{ $merchant->business_name }}</h2>
                    <span class="text-sm text-ink-500">
                        أجور الكل <span class="num font-semibold text-ink-800">{{ number_format((int) $shipments->sum('total_fees')) }}</span> د.ع
                    </span>
                </div>

                <div class="max-h-[28rem] overflow-auto rounded-xl border border-ink-100" data-prepaid-table>
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="size-4 accent-[var(--brand)]" checked aria-label="الكل"
                                           data-check-all-in="[data-prepaid-table]">
                                </th>
                                <th>رقم الوصل</th><th>الحالة</th><th>الوجهة</th><th>المبلغ</th><th>الأجور</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($shipments as $shipment)
                                <tr>
                                    <td><input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" checked
                                               class="size-4 accent-[var(--brand)]" aria-label="{{ $shipment->number }}"></td>
                                    <td class="num font-semibold"><a href="{{ route('shipments.show', $shipment) }}" class="text-[var(--brand)] hover:underline">{{ $shipment->number }}</a></td>
                                    <td><x-status-badge :status="$shipment->status" /></td>
                                    <td class="text-sm">{{ $shipment->governorate?->name_ar }}{{ $shipment->city ? ' — '.$shipment->city->name_ar : '' }}</td>
                                    <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                                    <td class="num font-semibold">{{ number_format($shipment->total_fees) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('shipment_ids') <p class="field-error">{{ $message }}</p> @enderror

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label class="field-label" for="cash_box_id">في صندوق</label>
                        <select id="cash_box_id" name="cash_box_id" class="field-input" required>
                            @foreach ($boxes as $box)
                                <option value="{{ $box->id }}" @selected((int) old('cash_box_id', $defaultBox) === $box->id)>{{ $box->name }}</option>
                            @endforeach
                        </select>
                        @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="field-label" for="note">ملاحظة</label>
                        <input id="note" name="note" maxlength="255" class="field-input" value="{{ old('note') }}"
                               placeholder="مثلاً: سلّمها مع الطرود لمندوب الاستلام">
                    </div>
                </div>

                <button type="submit" class="btn-primary mt-4">اقبض أجور المختارة</button>
            </form>
        @endif
    </section>
</div>

<section class="card mt-5 overflow-hidden">
    <h2 class="card-title border-b border-ink-100 px-5 py-4">الإيصالات</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>الإيصال</th><th>التاجر</th><th>الشحنات</th><th>المبلغ</th><th>الصندوق</th><th>قبضه</th><th>الوقت</th><th>ملاحظة</th></tr>
            </thead>
            <tbody>
                @forelse ($receipts as $receipt)
                    <tr>
                        <td class="num font-semibold">{{ $receipt->number }}</td>
                        <td>{{ $receipt->merchant?->business_name }}</td>
                        <td class="num">{{ number_format($receipt->shipments_count) }}</td>
                        <td class="num font-semibold">{{ number_format($receipt->amount) }}</td>
                        <td class="text-sm">{{ $receipt->cashBox?->name ?? '—' }}</td>
                        <td class="text-sm">{{ $receipt->user?->name ?? '—' }}</td>
                        <td class="num text-xs whitespace-nowrap text-ink-500">{{ $receipt->created_at->format('Y-m-d H:i') }}</td>
                        <td class="text-sm text-ink-600">{{ $receipt->note }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-ink-500">لم يُقبض شيءٌ بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($receipts->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $receipts->links() }}</div>
    @endif
</section>
@endsection

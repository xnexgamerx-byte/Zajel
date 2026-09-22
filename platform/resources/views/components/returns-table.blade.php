@props([
    'shipments',
    'action',
    'submit',
    'empty',
    'party',          // 'courier' أو 'merchant' — العمود الذي يُعرَض
    'merchantId' => null,
])

@if ($shipments->isEmpty())
    <section class="card p-10 text-center">
        <p class="text-ink-500">{{ $empty }}</p>
    </section>
@else
    <form method="POST" action="{{ $action }}" class="card overflow-hidden">
        @csrf
        @if ($merchantId)
            <input type="hidden" name="merchant_id" value="{{ $merchantId }}">
        @endif

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="w-10">
                            {{-- تحديد الكلّ: الراجع يُستلم بالجملة لا وصلاً وصلاً --}}
                            <input type="checkbox" aria-label="تحديد الكل" class="size-4 accent-[var(--brand)]"
                                   onchange="this.closest('table').querySelectorAll('tbody input[type=checkbox]')
                                             .forEach(c => c.checked = this.checked)">
                        </th>
                        <th>الوصل</th>
                        <th>{{ $party === 'courier' ? 'المندوب' : 'التاجر' }}</th>
                        <th>المستلم</th>
                        <th>سبب الرجوع</th>
                        <th>المبلغ</th>
                        <th>أجرة الراجع</th>
                        <th>{{ $party === 'courier' ? 'منذ' : 'في المخزن منذ' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($shipments as $shipment)
                        <tr>
                            <td>
                                <input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}"
                                       aria-label="الوصل {{ $shipment->number }}"
                                       class="size-4 accent-[var(--brand)]">
                            </td>
                            <td>
                                <a href="{{ route('shipments.show', $shipment) }}"
                                   class="num font-semibold text-ink-900 hover:underline">{{ $shipment->number }}</a>
                            </td>
                            <td class="text-ink-600">
                                {{ $party === 'courier'
                                    ? ($shipment->deliveryCourier?->name ?? 'بلا مندوب')
                                    : $shipment->merchant?->business_name }}
                            </td>
                            <td class="text-ink-600">{{ $shipment->recipient_name }}</td>
                            <td>
                                @if ($shipment->lastFailureReason)
                                    <span class="chip chip-warn">{{ $shipment->lastFailureReason->name_ar }}</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                            <td class="num text-bad-700">{{ number_format($shipment->return_fee) }}</td>
                            <td class="text-sm text-ink-500">
                                {{ ($party === 'courier' ? $shipment->status_changed_at : $shipment->return_received_at)?->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 p-5">
            <input type="text" name="note" class="field-input w-auto min-w-64 flex-1" maxlength="255"
                   placeholder="ملاحظة تُسجَّل مع كل شحنة (اختياري)">
            <button type="submit" class="btn-primary">{{ $submit }}</button>
            <p class="ms-auto text-xs text-ink-500">
                المحدَّد فقط يُنفَّذ عليه الإجراء.
            </p>
        </div>
    </form>
@endif

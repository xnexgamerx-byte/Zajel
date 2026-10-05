@props([
    'shipments',      // ما ينتظر التسوية خارج المسودّة (EditDraftSettlement::addable)
    'count',          // عددها كلّها — يُعرض منها ADDABLE_SHOWN
    'action',         // مسار الإضافة
    'party',          // 'courier' أو 'merchant' — العمود الأخير: عمولته، أو ما له
])

{{-- تعديل المسودّة: ما سُلِّم أو رجع بعد فتح الكشف يُحدَّد ويُضاف من شريطٍ أسفل الشاشة --}}
<section class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
        <div>
            <h2 class="text-sm font-bold">تنتظر التسوية خارج الكشف{{ $count ? ' — '.\App\Support\Arabic::shipments((int) $count) : '' }}</h2>
            <p class="mt-0.5 text-xs text-ink-500">سُلِّمت أو رجعت بعد فتح الكشف. حدّد ما تريد إضافته.</p>
        </div>
    </div>

    @if ($shipments->isEmpty())
        <p class="px-5 py-6 text-center text-sm text-ink-500">لا شحنات أخرى تنتظر التسوية.</p>
    @else
        <form method="POST" action="{{ $action }}" id="add-lines">@csrf</form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="w-10">
                            <input type="checkbox" aria-label="تحديد الكل" class="size-4 accent-[var(--brand)]" data-check-all-in="table">
                        </th>
                        <th>رقم الوصل</th>
                        <th>المستلم</th>
                        <th>الحالة</th>
                        <th>المحصَّل</th>
                        <th>{{ $party === 'courier' ? 'عمولته' : 'له' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($shipments as $shipment)
                        <tr>
                            <td class="px-4 py-2.5">
                                <input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" form="add-lines"
                                       aria-label="الوصل {{ $shipment->number }}" class="size-4 accent-[var(--brand)]">
                            </td>
                            <td class="px-4 py-2.5">
                                <a href="{{ route('shipments.show', $shipment) }}"
                                   class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $shipment->number }}</a>
                            </td>
                            <td class="px-4 py-2.5">{{ $shipment->recipient_name }}</td>
                            <td class="px-4 py-2.5"><x-status-badge :status="$shipment->status" /></td>
                            <td class="px-4 py-2.5 font-semibold" dir="ltr">
                                {{ $shipment->collected_amount !== null ? number_format($shipment->collected_amount) : '—' }}
                            </td>
                            <td class="px-4 py-2.5 {{ $party === 'courier' ? 'text-ok-700' : 'font-bold' }}" dir="ltr">
                                {{ number_format($party === 'courier' ? (int) $shipment->courier_commission : (int) $shipment->merchant_due) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($count > $shipments->count())
            <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                يُعرض أقدم {{ number_format($shipments->count()) }} منها؛ وما بعدها يدخل الكشف التالي.
            </p>
        @endif
    @endif
</section>

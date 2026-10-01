{{--
  جدول الشحنات بخانة الاختيار لشريط الإسناد: قائمة الشحنات وقسم كل مرحلةٍ في
  «كل مراحل النقل» — جدولٌ واحد لا نسختان تختلفان. $empty رسالة القائمة الفارغة،
  و$sinceStage يجعل عمود التاريخ «في المرحلة منذ» بدل تاريخ الإنشاء.
--}}
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th class="w-10 px-4 py-3 text-start">
                        <input type="checkbox" data-select-all
                               class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                    </th>
                    <th >رقم الوصل</th>
                    <th >التاجر</th>
                    <th >المستلم</th>
                    <th >الوجهة</th>
                    <th >المبلغ</th>
                    <th >الأجرة</th>
                    <th >المندوب</th>
                    <th >الحالة</th>
                    <th >{{ ($sinceStage ?? false) ? 'في المرحلة منذ' : 'التاريخ' }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    <tr>
                        <td class="px-4 py-3">
                            @if (auth()->user()->isStaff())
                                <input type="checkbox" form="assign-form" name="shipment_ids[]"
                                       value="{{ $shipment->id }}" data-row-select
                                       class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('shipments.show', $shipment) }}"
                               class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">
                                {{ $shipment->number }}
                            </a>
                            @if ($shipment->attempts_count > 0)
                                <span class="ms-1 rounded bg-warn-50 px-1.5 text-xs font-semibold text-warn-700">
                                    {{ $shipment->attempts_count }} محاولة
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-700">
                            {{ $shipment->merchant->business_name }}
                            @if ($shipment->merchant->is_vip)<span class="chip chip-info ms-1">مميّز</span>@endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $shipment->recipient_name }}</div>
                            <div class="text-xs text-ink-500">
                                <x-phone :number="$shipment->recipient_phone" :name="$shipment->recipient_name" />
                            </div>
                        </td>
                        <td class="px-4 py-3 text-ink-700">
                            {{ $shipment->governorate->name_ar }}
                            @if ($shipment->city)
                                <span class="text-ink-400">·</span>
                                <span class="text-xs text-ink-500">{{ $shipment->city->name_ar }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</td>
                        <td class="px-4 py-3 text-ink-600" dir="ltr">{{ number_format($shipment->total_fees) }}</td>
                        <td class="px-4 py-3 text-ink-700">
                            {{ $shipment->deliveryCourier?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <x-status-badge :status="$shipment->status" />
                        </td>
                        @if ($sinceStage ?? false)
                            @php
                                $inStage = $shipment->status_changed_at ? (int) $shipment->status_changed_at->copy()->startOfDay()->diffInDays(today()) : null;
                            @endphp
                            <td class="px-4 py-3 text-xs">
                                <span @class(['font-semibold text-bad-700' => $inStage >= 3, 'text-ink-700' => $inStage < 3])>
                                    {{ $inStage === null ? '—' : ($inStage === 0 ? 'اليوم' : \App\Support\Arabic::days($inStage)) }}
                                </span>
                                <div class="text-ink-500" dir="ltr">{{ $shipment->status_changed_at?->format('Y-m-d H:i') }}</div>
                            </td>
                        @else
                            <td class="px-4 py-3 text-xs text-ink-500" dir="ltr">
                                {{ $shipment->created_at->format('Y-m-d H:i') }}
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-16 text-center">
                            <div class="text-ink-500">{{ $empty ?? 'لا توجد شحنات مطابقة.' }}</div>
                            @if (! isset($empty) && auth()->user()->isStaff())
                                <a href="{{ route('shipments.create') }}" class="btn-primary mt-4">أنشئ أول شحنة</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">
            {{ $shipments->links() }}
        </div>
    @endif
</div>

<p class="mt-3 mb-20 text-xs text-ink-500">
    إجمالي النتائج: {{ number_format($shipments->total()) }}
</p>

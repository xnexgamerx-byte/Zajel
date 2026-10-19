@extends('layouts.app')
@section('title', 'وجبة التحصيل '.$batch->number)

@section('content')
@php($approved = $batch->isApproved())
@php($open = $batch->isOpen())
@php($pending = $items->where('status', 'pending_cash')->count())
@php($unresolved = $items->filter(fn ($i) => ! $i->isRemainder() && $i->discrepancy() < 0 && $i->resolution === null)->count())
@include('tenant.profits._tabs', ['title' => 'وجبة التحصيل '.$batch->number])

@if ($batch->isCancelled())
    <div class="alert alert-warn mb-4">
        أُلغيت هذه الوجبة <span class="num">{{ $batch->cancelled_at?->format('Y-m-d H:i') }}</span> بيد {{ $batch->cancelledBy?->name ?? '—' }}:
        {{ $batch->cancel_reason }}. كشوفها ومصروفاتها عادت لوجبةٍ أخرى، وسجلّها باقٍ أدناه.
    </div>
@endif

<div class="card mb-5 flex flex-wrap items-center justify-between gap-3 p-4">
    <div class="space-y-1 text-sm">
        <div class="flex flex-wrap items-center gap-2">@include('tenant.profits._status')</div>
        <div class="text-ink-600">
            {{ $batch->branch?->name ?? 'كل الفروع' }} ·
            {{ $batch->periodLabel() }} <span class="num">{{ $batch->from_date->format('Y-m-d') }} — {{ $batch->to_date->format('Y-m-d') }}</span> ·
            أنشأها {{ $batch->createdBy?->name ?? '—' }} <span class="num">{{ $batch->created_at->format('Y-m-d H:i') }}</span>
            @if ($approved) · اعتمدها {{ $batch->approvedBy?->name ?? '—' }} <span class="num">{{ $batch->approved_at->format('Y-m-d H:i') }}</span>@endif
        </div>
        @if ($batch->cashBox)<div class="text-xs text-ink-500">صندوق القبض: {{ $batch->cashBox->name }}</div>@endif
        @if ($batch->notes)<div class="text-xs text-ink-500">ملاحظة: {{ $batch->notes }}</div>@endif
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('profits.batches.print', $batch) }}" target="_blank" class="btn-ghost"><x-icon name="printer" class="size-4"/> طباعة الوجبة</a>
    </div>
</div>

{{-- مراحل الوجبة: أين هي الآن --}}
@unless ($batch->isCancelled())
    @php($steps = ['awaiting_cash' => 'استلام النقد', 'awaiting_reconciliation' => 'المطابقة ومعالجة الفروق', 'approved' => 'الاعتماد'])
    @php($at = match ($batch->status) { 'draft', 'awaiting_cash' => 0, 'discrepancy', 'awaiting_reconciliation' => 1, default => 2 })
    <ol class="mb-5 grid grid-cols-3 gap-2 text-xs" aria-label="مراحل الوجبة">
        @foreach (array_values($steps) as $i => $label)
            <li @class(['rounded-xl border px-3 py-2', 'border-ok-200 bg-ok-50 text-ok-700' => $i < $at || $approved,
                'border-[var(--brand)] font-bold' => $i === $at && ! $approved, 'border-ink-100 text-ink-500' => $i > $at])
                @if ($i === $at && ! $approved) aria-current="step" @endif>
                <span class="num">{{ $i + 1 }}</span>. {{ $label }}
                @if ($i === 0 && $pending) <span class="block text-ink-500">بانتظار {{ \App\Support\Arabic::count($pending, ['مندوب واحد', 'مندوبَين', 'مناديب', 'مندوباً']) }}</span>@endif
                @if ($i === 1 && $unresolved) <span class="block text-bad-700">عجزٌ لم يُعالَج عند {{ \App\Support\Arabic::count($unresolved, ['مندوب واحد', 'مندوبَين', 'مناديب', 'مندوباً']) }}</span>@endif
            </li>
        @endforeach
    </ol>
@endunless

@error('batch') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('reason') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('received_cash') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('resolution') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('resolution_reason') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror

{{-- الأرقام مفصولة: النقد، ومنه للتجّار، والأجور إيرادنا، والصافي --}}
<div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @include('tenant.profits._figure', ['label' => 'النقد المستلم فعلاً', 'value' => $batch->received_cash,
        'hint' => 'المتوقَّع بالكشوف '.number_format($batch->expected_cash).($batch->remainders ? ' + تحصيل عجزٍ سابق '.number_format($batch->remainders) : '')])
    @include('tenant.profits._figure', ['label' => 'أموال التجّار في هذا النقد', 'value' => $batch->merchant_money,
        'hint' => 'ليست للشركة: تُدفع للتجّار بكشوفهم.'])
    @include('tenant.profits._figure', ['label' => 'أجور التوصيل المستحقّة', 'value' => $batch->delivery_fees,
        'hint' => 'إيراد الشركة من '.\App\Support\Arabic::shipments((int) $batch->shipments_count).'، المسلَّم منها '.number_format($batch->delivered_count).'.'])
    @include('tenant.profits._figure', ['label' => 'صافي الربح', 'value' => $batch->net_profit, 'tone' => $batch->net_profit >= 0 ? 'text-ok-700' : 'text-bad-700',
        'hint' => 'الأجور − عمولات المناديب − التكاليف.'])
</div>
<div class="mb-5 grid grid-cols-2 gap-3 text-sm md:grid-cols-3 xl:grid-cols-6">
    @foreach ([
        ['عمولات المناديب', $batch->courier_commissions, ''],
        ['التكاليف المرتبطة', $batch->costs, ''],
        ['أجورٌ جاءت نقداً', $batch->fees_in_cash, ''],
        ['خصومات (تلف/غرامات)', $batch->deductions, ''],
        ['المتحقّق نقداً', $batch->realized_profit, 'text-ok-700'],
        ['الفرق (عجز/زيادة)', $batch->discrepancy, $batch->discrepancy < 0 ? 'text-bad-700' : ($batch->discrepancy > 0 ? 'text-info-700' : '')],
    ] as [$label, $value, $tone])
        <div class="rounded-xl bg-ink-50 p-3">
            <div class="text-xs text-ink-500">{{ $label }}</div>
            <div class="num mt-1 font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>
<p class="mb-5 text-xs leading-6 text-ink-500">
    الصندوق قبض ما في الكشف لحظة إقفاله؛ والفرق يُكتب عند الاعتماد: العجز يخرج من الصندوق ذمّةً على المندوب (أو يُشطب بصلاحية)،
    والزيادة تدخله معلَّقةً حتى يُعرف أصلها. والعجز يُحصَّل لاحقاً بسطرٍ في وجبةٍ تالية يُشير إليه. والخصومات لا تدخل الربح: تُعوَّض بها الأضرار.
</p>

{{-- ورقة كل مندوب: كشوفه وشحناته ومبالغه وفروق عهدته --}}
<h2 class="mb-3 text-base font-bold">الورقة المفصّلة لكل مندوب</h2>
@forelse ($byCourier as $courierItems)
    @php($courier = $courierItems->first()->courier)
    <section class="card mb-4 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-5 py-3">
            <h3 class="text-sm font-bold">
                @if ($courier)<a href="{{ route('profits.couriers.show', $courier) }}" class="hover:underline">{{ $courier->name }}</a>@else مندوب @endif
                <span class="num text-xs font-normal text-ink-500">{{ $courier?->code }}</span>
            </h3>
            <div class="text-xs text-ink-600">
                شحنات <span class="num font-semibold">{{ number_format($courierItems->sum('shipments_count')) }}</span> ·
                المستلم <span class="num font-semibold">{{ number_format($courierItems->sum('received_cash')) }}</span> ·
                الفرق <span @class(['num font-semibold', 'text-bad-700' => $courierItems->sum(fn ($i) => $i->discrepancy()) < 0])>{{ number_format($courierItems->sum(fn ($i) => $i->discrepancy())) }}</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الكشف</th>
                        <th>الشحنات</th>
                        <th>المحصَّل</th>
                        <th>للتجّار</th>
                        <th>الأجور</th>
                        <th>عمولته</th>
                        <th>المتوقَّع</th>
                        <th class="min-w-56">المستلم فعلاً</th>
                        <th>الفرق</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($courierItems as $item)
                        @if ($item->isRemainder())
                            <tr class="bg-ok-50/40">
                                <td colspan="6" class="text-sm">
                                    تحصيل عجزٍ سابق — {{ $item->remainderOf?->settlement?->code }} من الوجبة
                                    @if ($item->remainderOf?->batch)<a href="{{ route('profits.batches.show', $item->remainderOf->batch) }}" class="num text-[var(--brand)] hover:underline">{{ $item->remainderOf->batch->number }}</a>@endif
                                    @if ($item->note)<div class="text-xs text-ink-500">{{ $item->note }}</div>@endif
                                </td>
                                <td>—</td>
                                <td class="num font-semibold">{{ number_format($item->received_cash) }}</td>
                                <td>—</td>
                                <td>
                                    @if ($open)
                                        <form method="POST" action="{{ route('profits.batches.items.remove', [$batch, $item]) }}" data-confirm="يُخرج هذا التحصيل من الوجبة؟">
                                            @csrf @method('DELETE')
                                            <button class="btn-ghost py-1 text-xs">إخراج</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @else
                            <tr>
                                <td>
                                    <a href="{{ route('settlements.couriers.show', $item->courier_settlement_id) }}" class="num font-semibold text-[var(--brand)] hover:underline" target="_blank">{{ $item->settlement?->code }}</a>
                                    <div class="num text-xs text-ink-500">{{ $item->settlement?->confirmed_at?->format('Y-m-d') }}</div>
                                </td>
                                <td class="num">{{ number_format($item->shipments_count) }} <span class="text-xs text-ink-500">({{ number_format($item->delivered_count) }} مسلَّمة)</span></td>
                                <td class="num">{{ number_format($item->cod_collected) }}</td>
                                <td class="num">{{ number_format($item->merchant_money) }}</td>
                                <td class="num">{{ number_format($item->delivery_fees) }}</td>
                                <td class="num">{{ number_format($item->courier_commissions) }}</td>
                                <td class="num">{{ number_format($item->expected_cash) }}</td>
                                <td>
                                    @if ($batch->isCancelled())
                                        <span class="num">{{ number_format($item->received_cash) }}</span>
                                    @else
                                        <form method="POST" action="{{ route('profits.batches.items.received', [$batch, $item]) }}" class="flex flex-wrap items-center gap-1">
                                            @csrf
                                            <input type="number" name="received_cash" min="0" step="1" required
                                                   value="{{ $item->isPending() ? $item->expected_cash : $item->received_cash }}"
                                                   class="field-input w-32 py-1 text-left" dir="ltr" aria-label="المستلم فعلاً من {{ $courier?->name }}">
                                            @if ($approved)
                                                <input name="reason" required maxlength="255" placeholder="سبب التصحيح" class="field-input w-40 py-1" aria-label="سبب التصحيح">
                                            @endif
                                            <button @class(['py-1 text-xs', 'btn-primary' => $item->isPending(), 'btn-ghost' => ! $item->isPending()])>
                                                {{ $item->isPending() ? 'استلام النقد' : ($approved ? 'تصحيح' : 'حفظ') }}
                                            </button>
                                        </form>
                                    @endif
                                    @if ($item->isPending())
                                        <div class="mt-1 text-xs text-warn-700">كشفه مسودّة: يُقفَل حين يُستلم نقده ويدخل صندوق الوجبة.</div>
                                    @elseif ($item->counted_at)
                                        <div class="num mt-1 text-xs text-ink-500">عُدَّ {{ $item->counted_at->format('Y-m-d H:i') }}</div>
                                    @endif
                                    @if ($item->discrepancy() < 0)
                                        @if ($open)
                                            <form method="POST" action="{{ route('profits.batches.items.resolve', [$batch, $item]) }}" class="mt-2 flex flex-wrap items-center gap-1">
                                                @csrf
                                                <select name="resolution" class="field-input w-36 py-1 text-xs" aria-label="معالجة العجز">
                                                    <option value="courier_debt" @selected($item->resolution === 'courier_debt')>ذمّة على المندوب</option>
                                                    @can('money.write_off')<option value="written_off" @selected($item->resolution === 'written_off')>شطب العجز</option>@endcan
                                                </select>
                                                <input name="resolution_reason" maxlength="255" value="{{ $item->resolution_reason }}" placeholder="السبب" class="field-input w-32 py-1 text-xs" aria-label="سبب المعالجة">
                                                <button class="btn-ghost py-1 text-xs">{{ $item->resolution ? 'تغيير' : 'عالِج العجز' }}</button>
                                            </form>
                                        @endif
                                        @if ($item->resolution)
                                            <div class="mt-1 text-xs"><span @class(['chip', 'chip-warn' => $item->resolution === 'courier_debt', 'chip-mute' => $item->resolution === 'written_off'])>{{ \App\Models\CollectionBatchItem::RESOLUTIONS[$item->resolution] }}</span>
                                                @if ($item->resolution_reason)<span class="text-ink-500">{{ $item->resolution_reason }}</span>@endif</div>
                                        @endif
                                    @elseif ($item->discrepancy() > 0)
                                        <div class="mt-1 text-xs text-info-700">زيادةٌ تُعلَّق في الصندوق حتى يُعرف أصلها.</div>
                                    @endif
                                    @if ((int) $item->repaid > 0)<div class="mt-1 text-xs text-ok-700">سُدِّد من عجزه لاحقاً <span class="num">{{ number_format($item->repaid) }}</span></div>@endif
                                </td>
                                <td @class(['num font-semibold', 'text-bad-700' => $item->discrepancy() < 0, 'text-info-700' => $item->discrepancy() > 0])>{{ number_format($item->discrepancy()) }}</td>
                                <td>
                                    @if ($open)
                                        <form method="POST" action="{{ route('profits.batches.items.remove', [$batch, $item]) }}" data-confirm="يُخرج الكشف {{ $item->settlement?->code }} من الوجبة؟ يعود لوجبةٍ أخرى.">
                                            @csrf @method('DELETE')
                                            <button class="btn-ghost py-1 text-xs">إخراج</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                            @if ($lines->has($item->courier_settlement_id))
                                <tr>
                                    <td colspan="10" class="bg-ink-50/50 p-0">
                                        <details class="px-5 py-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-ink-600">شحنات الكشف {{ $item->settlement?->code }} ({{ $lines[$item->courier_settlement_id]->count() }})</summary>
                                            <table class="tbl mt-2 text-xs">
                                                <thead><tr><th>الوصل</th><th>التاجر</th><th>الحال</th><th>المحصَّل</th><th>الأجرة</th><th>للتاجر</th><th>عمولته</th></tr></thead>
                                                <tbody>
                                                    @foreach ($lines[$item->courier_settlement_id] as $line)
                                                        @php($s = $line->shipment)
                                                        <tr>
                                                            <td class="num">@if ($s)<a href="{{ route('shipments.show', $s->id) }}" class="text-[var(--brand)] hover:underline">{{ $s->number }}</a>@endif</td>
                                                            <td>{{ $s?->merchant?->business_name }}</td>
                                                            <td>@if ($s)<x-status-badge :status="$s->status"/>@endif</td>
                                                            <td class="num">{{ number_format($line->collected_amount) }}</td>
                                                            <td class="num">{{ number_format($s ? ($s->delivered_at ? $s->total_fees : $s->return_fee) : 0) }}</td>
                                                            <td class="num">{{ number_format($s && $s->delivered_at ? max(0, (int) $s->merchant_due) : 0) }}</td>
                                                            <td class="num">{{ number_format($line->commission) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@empty
    <div class="card mb-4 p-8 text-center text-ink-500">{{ $batch->isCancelled() ? 'الوجبة ملغاة: لا سطر فيها.' : 'لا كشف في الوجبة. أضف كشوفاً من الأسفل.' }}</div>
@endforelse

{{-- التكاليف المرتبطة: مصروفاتٌ تُحسب على الوجبة، كلٌّ في وجبةٍ واحدة --}}
<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">التكاليف المرتبطة بالوجبة</h2>
    @error('expense_ids') <p class="field-error px-5 pt-3">{{ $message }}</p> @enderror
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>المصروف</th><th>التاريخ</th><th>البند</th><th>البيان</th><th>المبلغ</th><th></th></tr></thead>
            <tbody>
                @forelse ($batch->costLines as $cost)
                    <tr>
                        <td class="num">{{ $cost->expense?->number }}</td>
                        <td class="num text-xs">{{ $cost->expense?->spent_on?->format('Y-m-d') }}</td>
                        <td>{{ $cost->expense?->category?->name_ar }}</td>
                        <td>{{ $cost->expense?->description }}</td>
                        <td class="num font-semibold">{{ number_format($cost->amount) }}</td>
                        <td>
                            @unless ($batch->isCancelled())
                                <form method="POST" action="{{ route('profits.batches.costs.remove', [$batch, $cost]) }}" class="flex items-center gap-1"
                                      data-confirm="يُخرج المصروف {{ $cost->expense?->number }} من الوجبة؟">
                                    @csrf @method('DELETE')
                                    @if ($approved)<input name="reason" required maxlength="255" placeholder="سبب التصحيح" class="field-input w-36 py-1" aria-label="سبب التصحيح">@endif
                                    <button class="btn-ghost py-1 text-xs">إخراج</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-ink-500">لا مصروف محسوب على الوجبة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($expenses->isNotEmpty() && ! $batch->isCancelled())
        <form method="POST" action="{{ route('profits.batches.costs.add', $batch) }}" class="border-t border-ink-100 p-4">
            @csrf
            <div class="mb-2 text-xs font-semibold text-ink-600">مصروفات المدّة {{ $batch->branch ? 'للفرع' : '' }} لم تُحسب على وجبة:</div>
            <div class="grid grid-cols-1 gap-1 text-sm md:grid-cols-2">
                @foreach ($expenses as $expense)
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="expense_ids[]" value="{{ $expense->id }}" class="size-4 accent-[var(--brand)]">
                        <span class="num text-xs text-ink-500">{{ $expense->number }} · {{ $expense->spent_on->format('Y-m-d') }}</span>
                        <span>{{ $expense->category?->name_ar }} — {{ $expense->description }}</span>
                        <span class="num ms-auto font-semibold">{{ number_format($expense->amount) }}</span>
                    </label>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if ($approved)<input name="reason" required maxlength="255" placeholder="سبب التصحيح" class="field-input w-56" aria-label="سبب التصحيح">@endif
                <button class="btn-ghost">احسب المحدَّد على الوجبة</button>
            </div>
        </form>
    @endif
</section>

@if ($open)
    @if ($addable->isNotEmpty())
        <section class="card mb-5 overflow-hidden">
            <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">إضافة كشوف مُقفَلة لم تدخل وجبة</h2>
            <form method="POST" action="{{ route('profits.batches.items.add', $batch) }}">
                @csrf
                @error('settlement_ids') <p class="field-error px-5 pt-3">{{ $message }}</p> @enderror
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th class="w-8"><input type="checkbox" aria-label="تحديد الكل" class="size-4 accent-[var(--brand)]" data-check-all-in="table"></th><th>المندوب</th><th>الكشف</th><th>أُقفل</th><th>الشحنات</th><th>المسلَّم للشركة</th></tr></thead>
                        <tbody>
                            @foreach ($addable as $settlement)
                                <tr>
                                    <td><input type="checkbox" name="settlement_ids[]" value="{{ $settlement->id }}" class="size-4 accent-[var(--brand)]" aria-label="{{ $settlement->code }}"></td>
                                    <td>{{ $settlement->courier?->name }}</td>
                                    <td class="num">{{ $settlement->code }}</td>
                                    <td class="num text-xs">{{ $settlement->confirmed_at?->format('Y-m-d H:i') }}</td>
                                    <td class="num">{{ number_format($settlement->shipments_count) }}</td>
                                    <td class="num font-semibold">{{ number_format($settlement->net_amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-ink-100 p-4"><button class="btn-ghost">أضف المحدَّد إلى الوجبة</button></div>
            </form>
        </section>
    @endif

    @if ($shortages->isNotEmpty())
        <section class="card mb-5 p-5">
            <h2 class="mb-1 text-sm font-bold">تحصيل عجزٍ سابق</h2>
            <p class="mb-3 text-xs text-ink-500">مندوبٌ سلّم ما نقص في وجبةٍ معتمدة: يُسجَّل هنا بسطرٍ يُشير إلى العجز نفسه، فلا يُحصَّل الدينار مرّتين.</p>
            <form method="POST" action="{{ route('profits.batches.remainders', $batch) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="min-w-64 flex-1">
                    <label class="field-label" for="remainder_of_item_id">العجز</label>
                    <select id="remainder_of_item_id" name="remainder_of_item_id" class="field-input" required>
                        @foreach ($shortages as $short)
                            <option value="{{ $short->id }}">{{ $short->courier?->name }} — {{ $short->settlement?->code }} من {{ $short->batch?->number }} (باقٍ {{ number_format($short->outstanding()) }})</option>
                        @endforeach
                    </select>
                    @error('remainder_of_item_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="amount">المبلغ المستلم</label>
                    <input id="amount" type="number" name="amount" min="1" step="1" required class="field-input w-36 text-left" dir="ltr">
                    @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="min-w-56 flex-1">
                    <label class="field-label" for="note">البيان</label>
                    <input id="note" name="note" required maxlength="255" class="field-input" placeholder="مثلاً: سلّم باقي عهدته نقداً">
                </div>
                <button class="btn-ghost">سجّل التحصيل</button>
            </form>
        </section>
    @endif
@endif

{{-- المرفقات: ورقة العدّ، إيصال تسليم العجز --}}
<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">المرفقات</h2>
    @error('file') <p class="field-error px-5 pt-3">{{ $message }}</p> @enderror
    <ul class="divide-y divide-ink-100 text-sm">
        @forelse ($batch->files as $file)
            <li class="flex flex-wrap items-center gap-2 px-5 py-2">
                <a href="{{ route('profits.batches.files.show', [$batch, $file]) }}" class="text-[var(--brand)] hover:underline">{{ $file->name }}</a>
                <span class="text-xs text-ink-500">{{ $file->uploadedBy?->name }} · <span class="num">{{ $file->created_at?->format('Y-m-d H:i') }}</span></span>
            </li>
        @empty
            <li class="px-5 py-4 text-ink-500">لا مرفق. أرفق ورقة العدّ الموقَّعة أو إيصال تسليم العجز.</li>
        @endforelse
    </ul>
    @unless ($batch->isCancelled())
        <form method="POST" action="{{ route('profits.batches.files.store', $batch) }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2 border-t border-ink-100 p-4">
            @csrf
            <input type="file" name="file" required accept=".jpg,.jpeg,.png,.webp,.pdf" class="field-input max-w-xs" aria-label="الملف">
            <button class="btn-ghost">أرفق</button>
            <span class="text-xs text-ink-500">صورة أو PDF حتى ٥ م.ب.</span>
        </form>
    @endunless
</section>

@if ($open)
    {{-- الاعتماد أو الإلغاء: آخر الصفحة، بعد أن يُرى كل شيء --}}
    <section class="card mb-5 grid gap-4 p-5 md:grid-cols-2">
        <div>
            <h2 class="mb-2 text-sm font-bold">اعتماد الوجبة</h2>
            <ul class="mb-3 space-y-1 text-xs">
                <li @class(['text-ok-700' => $items->isNotEmpty(), 'text-bad-700' => $items->isEmpty()])>{{ $items->isNotEmpty() ? '✓' : '✗' }} في الوجبة كشوف</li>
                <li @class(['text-ok-700' => ! $pending, 'text-bad-700' => $pending])>{{ $pending ? '✗' : '✓' }} استُلم نقد كل المناديب وعُدَّ</li>
                <li @class(['text-ok-700' => ! $unresolved, 'text-bad-700' => $unresolved])>{{ $unresolved ? '✗' : '✓' }} كل عجزٍ معالَج: ذمّةً أو شطباً</li>
                @if (\App\Support\Tenancy\Tenancy::company()?->setting('finance.dual_approval'))
                    <li @class(['text-ok-700' => (int) $batch->created_by_user_id !== (int) auth()->id(), 'text-bad-700' => (int) $batch->created_by_user_id === (int) auth()->id()])>
                        {{ (int) $batch->created_by_user_id === (int) auth()->id() ? '✗' : '✓' }} يعتمدها غيرُ من أنشأها</li>
                @endif
            </ul>
            <form method="POST" action="{{ route('profits.batches.approve', $batch) }}" class="flex flex-wrap items-center gap-2"
                  data-confirm="اعتماد الوجبة {{ $batch->number }} بصافي ربح {{ number_format($batch->net_profit) }} د.ع و{{ $batch->reconciliationLabel() }}؟ تُكتب حركات الفروق، وبعدها كل تصحيحٍ يُكتب بسببه.">
                @csrf
                @if ($unresolved && auth()->user()->can('money.write_off'))
                    <input name="exception" maxlength="255" placeholder="سبب الاعتماد باستثناء (يصير العجز ذمّة)" class="field-input min-w-56 flex-1" aria-label="سبب الاستثناء">
                @endif
                <button class="btn-primary" @disabled($pending || $items->isEmpty())><x-icon name="check" class="size-4"/> اعتماد بعد المطابقة</button>
            </form>
        </div>
        <div>
            <h2 class="mb-2 text-sm font-bold">إلغاء الوجبة</h2>
            <p class="mb-3 text-xs text-ink-500">لا يتغيّر شيءٌ في المال: الكشوف المُقفَلة ونقدها في الصندوق كما هي، وتدخل كشوفها ومصروفاتها وجبةً أخرى. والوجبة تبقى بسجلّها.</p>
            <form method="POST" action="{{ route('profits.batches.destroy', $batch) }}" class="flex flex-wrap items-center gap-2"
                  data-confirm="إلغاء الوجبة {{ $batch->number }}؟">
                @csrf @method('DELETE')
                <input name="reason" required maxlength="255" placeholder="سبب الإلغاء" class="field-input min-w-48 flex-1" aria-label="سبب الإلغاء">
                <button class="btn-ghost text-bad-700">إلغاء الوجبة</button>
            </form>
        </div>
    </section>
@endif

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">سجلّ الوجبة</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الوقت</th><th>من</th><th>العملية</th><th>على</th><th>قبل</th><th>بعد</th><th>السبب</th></tr></thead>
            <tbody>
                @foreach ($audits as $audit)
                    <tr>
                        <td class="num text-xs">{{ $audit->created_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $audit->user_name }}</td>
                        <td><span @class(['chip', 'chip-warn' => in_array($audit->action, ['corrected', 'approved_exception'], true), 'chip-ok' => $audit->action === 'approved',
                            'chip-bad' => $audit->action === 'cancelled', 'chip-mute' => ! in_array($audit->action, ['corrected', 'approved', 'approved_exception', 'cancelled'], true)])>{{ $audit->actionLabel() }}</span></td>
                        <td class="text-xs">{{ $audit->subject }}</td>
                        <td class="num text-xs">{{ $audit->before ?? '—' }}</td>
                        <td class="num text-xs">{{ $audit->after ?? '—' }}</td>
                        <td class="text-xs">{{ $audit->reason ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection

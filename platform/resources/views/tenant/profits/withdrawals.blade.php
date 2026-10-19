@extends('layouts.app')
@section('title', 'سحب الأرباح')

@section('content')
@include('tenant.profits._tabs', ['title' => 'سحب الأرباح', 'lead' => 'السحب حقوق ملكيةٍ لا مصروف: يُطلب ويُعتمد ثم يُدفع من صندوقٍ أو حسابٍ مصرفي، ولا يزيد على المتاح — الأصغر من النقد الحرّ والمحصَّل نقداً بعد ما سُحب.'])

@php($user = auth()->user())

<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
    @include('tenant.profits._figure', ['label' => 'الأرباح المحاسبية المتراكمة', 'value' => $g['earned'], 'tone' => 'text-profit-700',
        'hint' => 'كل ما رُبح منذ بداية الدفتر، قُبض أم لم يُقبض.'])
    @include('tenant.profits._figure', ['label' => 'المحصَّلة نقداً', 'value' => $h['realized'], 'tone' => 'text-ok-700',
        'hint' => 'أجورٌ وصل نقدها وطوبق، ناقص ما دُفع من عمولاتٍ ومصروفات.'])
    @include('tenant.profits._figure', ['label' => 'الأرباح المحتجزة', 'value' => $g['total'],
        'hint' => 'مدعومةٌ بنقد '.number_format($g['backed']).'، وغير مدعومة '.number_format($g['unbacked']).'.'])
    @include('tenant.profits._figure', ['label' => 'المتاح للسحب الآن', 'value' => $free, 'tone' => 'text-profit-700',
        'hint' => $committed ? 'بعد حجز '.number_format($committed).' طُلب أو اعتُمد ولم يُدفع.' : 'لا سحب ينتظر الدفع.'])
</div>

<details class="card mb-5 p-4 text-sm">
    <summary class="cursor-pointer font-semibold text-[var(--brand)]">تفصيل المتاح للسحب</summary>
    <ul class="mt-3 divide-y divide-ink-100">
        @foreach ([
            ['النقد في الصناديق والحسابات (1100)', $h['cash'], ''],
            ['أموال التجّار فيها', $h['merchants'], '−'],
            ['تأمينات التجّار', $h['deposits'], '−'],
            ['أجور مقبوضة مقدّماً لم تُكتسب', $h['prepaid'], '−'],
            ['مصروفات مستحقّة غير مدفوعة', $h['expenses_due'], '−'],
            ['حصص مندوبي الاستلام المستحقّة', $h['pickup_due'], '−'],
            ['زيادات نقدية معلّقة', $h['excess'], '−'],
            ['الاحتياطي التشغيلي', $h['reserve'], '−'],
            ['= النقد الحرّ', $h['free'], ''],
            ['المحصَّل نقداً المتراكم', $h['realized'], ''],
            ['المسحوب', $h['drawn'], '−'],
            ['= المتاح (الأصغر من النقد الحرّ والمحصَّل بعد المسحوب)', $h['total'], ''],
            ['طُلب أو اعتُمد ولم يُدفع', $committed, '−'],
        ] as [$label, $value, $sign])
            <li class="flex justify-between gap-2 py-1.5"><span>{{ $label }}</span><span class="num font-semibold">{{ $value ? $sign : '' }}{{ number_format($value) }}</span></li>
        @endforeach
    </ul>
    @if ($approvalOver)<p class="mt-2 text-xs text-ink-500">السحب فوق <span class="num">{{ number_format($approvalOver) }}</span> د.ع يعتمده غيرُ من طلبه (إعداد الشركة).</p>@endif
</details>

@error('withdrawal') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('reason') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror

@can('withdrawals.request')
    <form method="POST" action="{{ route('profits.withdrawals.store') }}" class="card mb-5 p-4"
          data-confirm="تسجيل سحبٍ من أرباح الشركة؟ السحب حقوق ملكيةٍ لا مصروف، ويخرج من الصندوق المختار عند الدفع.">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $key) }}">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="field-label" for="amount">المبلغ</label>
                <input id="amount" type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" required class="field-input w-40 text-left" dir="ltr">
                @error('amount') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="min-w-48">
                <label class="field-label" for="cash_box_id">من الصندوق أو الحساب</label>
                <select id="cash_box_id" name="cash_box_id" class="field-input" required>
                    @foreach ($boxes as $box)
                        <option value="{{ $box->id }}" @selected((int) old('cash_box_id') === $box->id)>{{ $box->name }}{{ $box->type === 'bank' ? ' ('.$box->typeLabel().')' : '' }} — {{ number_format($box->balance) }}</option>
                    @endforeach
                </select>
                @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
                @if ($boxes->isEmpty())<p class="field-error">لا صندوق ولا حساب مفعّل: أنشئه من <a href="{{ route('cash.index') }}" class="underline">الصندوق</a>.</p>@endif
            </div>
            <div class="min-w-40">
                <label class="field-label" for="withdrawn_by">المستفيد</label>
                <input id="withdrawn_by" name="withdrawn_by" value="{{ old('withdrawn_by', $user->name) }}" maxlength="120" required class="field-input">
            </div>
            <div>
                <label class="field-label" for="withdrawn_on">التاريخ</label>
                <input id="withdrawn_on" type="date" name="withdrawn_on" value="{{ old('withdrawn_on', now()->toDateString()) }}" required class="field-input">
            </div>
            <div class="min-w-56 flex-1">
                <label class="field-label" for="note">سبب السحب</label>
                <input id="note" name="note" value="{{ old('note') }}" maxlength="255" required class="field-input" placeholder="مثلاً: أرباح الأسبوع للشركاء">
                @error('note') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
            <span>المتاح قبل: <span class="num font-semibold">{{ number_format($free) }}</span> · وبعد:
                <span class="num font-semibold text-profit-700" data-after-of="amount" data-before="{{ $free }}">{{ number_format($free) }}</span> د.ع</span>
            @can('withdrawals.approve')
                <label class="flex items-center gap-2"><input type="checkbox" name="pay_now" value="1" class="size-4" @checked(old('pay_now'))> اعتمده وادفعه الآن</label>
            @endcan
            <button class="btn-primary ms-auto">تسجيل السحب</button>
        </div>
    </form>
@endcan

<div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-5 py-4">
        <h2 class="text-sm font-bold">سجل عمليات السحب</h2>
        <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
            <select name="status" class="field-input py-1" aria-label="الحال" data-submit-on-change>
                <option value="">كل الحالات</option>
                @foreach (\App\Models\ProfitWithdrawal::STATUSES as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach
            </select>
            <a href="{{ route('profits.withdrawals.export', request()->query()) }}" class="btn-ghost py-1"><x-icon name="download" class="size-4"/> تصدير Excel</a>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الرقم</th><th>الحال</th><th>التاريخ</th><th>المبلغ</th><th>المستفيد</th><th>الصندوق</th><th>السبب</th><th>المسار</th><th class="min-w-56"></th></tr></thead>
            <tbody>
                @forelse ($withdrawals as $w)
                    <tr @class(['text-ink-400' => $w->isCancelled()])>
                        <td class="num font-semibold">{{ $w->number }}</td>
                        <td><span @class(['chip', 'chip-ok' => $w->isPaid(), 'chip-warn' => in_array($w->status, ['requested', 'approved'], true), 'chip-mute' => $w->isCancelled()])>{{ $w->statusLabel() }}</span></td>
                        <td class="num">{{ $w->withdrawn_on->format('Y-m-d') }}</td>
                        <td @class(['num font-bold', 'line-through' => $w->isCancelled()])>{{ number_format($w->amount) }}</td>
                        <td>{{ $w->withdrawn_by ?? '—' }}</td>
                        <td class="text-xs">{{ $w->cashBox?->name ?? '—' }}</td>
                        <td class="text-xs">{{ $w->note ?? '—' }}@if ($w->cancel_reason)<div class="text-bad-700">{{ $w->isReversed() ? 'عُكس' : 'أُلغي' }}: {{ $w->cancel_reason }}</div>@endif</td>
                        <td class="text-xs leading-5">
                            طلبه {{ $w->createdBy?->name ?? '—' }}
                            @if ($w->approvedBy)<br>اعتمده {{ $w->approvedBy->name }}@endif
                            @if ($w->paidBy)<br>دفعه {{ $w->paidBy->name }} <span class="num">{{ $w->paid_at?->format('Y-m-d H:i') }}</span>@endif
                            @if ($w->available_before !== null)<br>المتاح <span class="num">{{ number_format($w->available_before) }}</span> ← <span class="num">{{ number_format($w->available_after) }}</span>@endif
                        </td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1">
                                @if ($w->status === 'requested' && $user->can('withdrawals.approve'))
                                    <form method="POST" action="{{ route('profits.withdrawals.approve', $w) }}">@csrf<button class="btn-ghost py-1 text-xs">اعتماد</button></form>
                                @endif
                                @if ($w->status === 'approved' && $user->can('withdrawals.approve'))
                                    <form method="POST" action="{{ route('profits.withdrawals.pay', $w) }}" data-confirm="دفع {{ number_format($w->amount) }} د.ع من «{{ $w->cashBox?->name }}»؟">@csrf<button class="btn-primary py-1 text-xs">دفع</button></form>
                                @endif
                                @if ($w->isPaid())
                                    <a href="{{ route('profits.withdrawals.print', $w) }}" target="_blank" class="btn-ghost py-1 text-xs"><x-icon name="printer" class="size-4"/> سند الصرف</a>
                                @endif
                                @if ((in_array($w->status, ['requested', 'approved'], true) && $user->can('withdrawals.request'))
                                    || ($w->isPaid() && $user->can('withdrawals.approve') && \App\Actions\Money\UndoWithinDay::open($w->paid_at ?? $w->created_at)))
                                    <form method="POST" action="{{ route('profits.withdrawals.cancel', $w) }}" class="flex items-center gap-1"
                                          data-confirm="{{ $w->isPaid() ? 'عكس السحب '.$w->number.'؟ يعود المبلغ إلى الصندوق ويُكتب القيد العكسي.' : 'إلغاء طلب السحب '.$w->number.'؟' }}">
                                        @csrf
                                        <input name="reason" required maxlength="255" placeholder="السبب" class="field-input w-28 py-1 text-xs" aria-label="السبب">
                                        <button class="btn-ghost py-1 text-xs text-bad-700">{{ $w->isPaid() ? 'عكس' : 'إلغاء' }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-12 text-center text-ink-500">لا سحب بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($withdrawals->hasPages())<div class="border-t border-ink-100 px-4 py-3">{{ $withdrawals->links() }}</div>@endif
</div>
@endsection

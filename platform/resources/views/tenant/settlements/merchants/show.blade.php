@extends('layouts.app')
@section('title', 'كشف ' . $settlement->code)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="page-title font-mono" dir="ltr">{{ $settlement->code }}</h1>
            <x-settlement-status :status="$settlement->status" />
        </div>
        <p class="mt-1 text-sm text-ink-500">
            {{ $settlement->merchant->business_name }}
            · من {{ $settlement->from_date?->format('Y-m-d') }}
            إلى {{ $settlement->to_date?->format('Y-m-d') }}
        </p>
    </div>
    <a href="{{ route('settlements.merchants.index') }}" class="btn-ghost">رجوع</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">
                سطور الكشف — {{ \App\Support\Arabic::shipments((int) $settlement->shipments_count) }}
                @if ($settlement->returned_count)
                    <span class="font-normal text-warn-700">
                        (منها {{ $settlement->returned_count }} راجعة)
                    </span>
                @endif
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th >رقم الوصل</th>
                            <th >المستلم</th>
                            <th >الحالة</th>
                            <th >المحصَّل</th>
                            <th >التوصيل</th>
                            <th >الراجع</th>
                            <th >له</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($settlement->lines as $line)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('shipments.show', $line->shipment) }}"
                                       class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">
                                        {{ $line->shipment->number }}
                                    </a>
                                </td>
                                <td class="px-4 py-2.5">{{ $line->shipment->recipient_name }}</td>
                                <td class="px-4 py-2.5"><x-status-badge :status="$line->shipment->status" /></td>
                                <td class="px-4 py-2.5 font-semibold" dir="ltr">
                                    {{ number_format($line->collected_amount) }}
                                </td>
                                <td class="px-4 py-2.5 text-ink-600" dir="ltr">
                                    {{ $line->delivery_fee ? '−'.number_format($line->delivery_fee) : '' }}
                                </td>
                                <td class="px-4 py-2.5 text-warn-700" dir="ltr">
                                    {{ $line->return_fee ? '−'.number_format($line->return_fee) : '' }}
                                </td>
                                <td class="px-4 py-2.5 font-bold {{ $line->net_amount >= 0 ? '' : 'text-bad-700' }}"
                                    dir="ltr">{{ number_format($line->net_amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-ink-50 font-bold">
                        <tr>
                            <td class="px-4 py-3" colspan="3">المجموع</td>
                            <td class="px-4 py-3" dir="ltr">{{ number_format($settlement->cod_total) }}</td>
                            <td class="px-4 py-3" dir="ltr">
                                {{ $settlement->delivery_fees_total ? '−'.number_format($settlement->delivery_fees_total) : '' }}
                            </td>
                            <td class="px-4 py-3 text-warn-700" dir="ltr">
                                {{ $settlement->return_fees_total ? '−'.number_format($settlement->return_fees_total) : '' }}
                            </td>
                            <td class="px-4 py-3 text-[var(--brand)]" dir="ltr">{{ number_format($settlement->net_amount) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الحساب</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-600">المحصَّل من الزبائن</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($settlement->cod_total) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-ink-600">أجور التوصيل</dt>
                    <dd dir="ltr">−{{ number_format($settlement->delivery_fees_total) }}</dd>
                </div>
                @if ($settlement->cod_fees_total)
                    <div class="flex justify-between">
                        <dt class="text-ink-600">عمولة التحصيل</dt>
                        <dd dir="ltr">−{{ number_format($settlement->cod_fees_total) }}</dd>
                    </div>
                @endif
                @if ($settlement->return_fees_total)
                    <div class="flex justify-between text-warn-700">
                        <dt>أجور الرواجع</dt>
                        <dd dir="ltr">−{{ number_format($settlement->return_fees_total) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t-2 border-ink-300 pt-2">
                    <dt class="font-bold">{{ $settlement->net_amount >= 0 ? 'الواجب دفعه له' : 'الواجب تحصيله منه' }}</dt>
                    <dd class="text-lg font-bold {{ $settlement->net_amount >= 0 ? 'text-[var(--brand)]' : 'text-bad-700' }}"
                        dir="ltr">{{ number_format(abs($settlement->net_amount)) }} د.ع</dd>
                </div>
            </dl>
        </section>

        @if ($settlement->status === 'draft')
            <form method="POST" action="{{ route('settlements.merchants.confirm', $settlement) }}"
                  class="card space-y-4 p-5">
                @csrf
                <h2 class="text-sm font-bold">إقفال الكشف</h2>
                <p class="text-xs text-ink-500">
                    يُثبَّت الرقم وتُوسَم الشحنات فلا تدخل كشفاً آخر. الدفع خطوة تالية.
                </p>
                <div>
                    <label class="field-label" for="notes">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="2" class="field-input">{{ old('notes') }}</textarea>
                </div>
                <button type="submit" class="btn-primary w-full">إقفال الكشف</button>
            </form>
        @elseif ($settlement->status === 'confirmed')
            <form method="POST" action="{{ route('settlements.merchants.pay', $settlement) }}"
                  class="card space-y-4 p-5">
                @csrf
                <h2 class="text-sm font-bold">تسجيل الدفع</h2>

                <div>
                    <label class="field-label" for="payout_method">طريقة الدفع</label>
                    <select id="payout_method" name="payout_method" class="field-input" required>
                        @foreach (['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                                   'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                                   'bank_transfer' => 'حوالة مصرفية'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('payout_method', $settlement->payout_method) === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('payout_method') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="payout_reference">رقم الحوالة / الإيصال</label>
                    <input id="payout_reference" name="payout_reference" class="field-input text-left" dir="ltr"
                           value="{{ old('payout_reference') }}">
                    @error('payout_reference') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="btn-primary w-full">سجّل الدفع</button>
            </form>
        @else
            <section class="card p-5">
                <h2 class="mb-3 text-sm font-bold">الدفع</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-500">الطريقة</dt>
                        <dd class="font-medium">
                            {{ ['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                                'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                                'bank_transfer' => 'حوالة مصرفية'][$settlement->payout_method] ?? '—' }}
                        </dd>
                    </div>
                    @if ($settlement->payout_reference)
                        <div class="flex justify-between">
                            <dt class="text-ink-500">المرجع</dt>
                            <dd class="font-medium" dir="ltr">{{ $settlement->payout_reference }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-ink-500">دُفع في</dt>
                        <dd class="font-medium" dir="ltr">{{ $settlement->paid_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                </dl>
            </section>
        @endif
    </div>
</div>
@endsection

@extends('layouts.platform')
@section('title', 'فاتورة ' . $invoice->number)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="font-mono text-xl font-bold" dir="ltr">{{ $invoice->number }}</h1>
            <x-invoice-status :status="$invoice->status" />
        </div>
        <p class="mt-1 text-sm text-slate-500">
            <a href="{{ route('admin.companies.show', $invoice->company) }}" class="text-brand-700 hover:underline">
                {{ $invoice->company->name }}
            </a>
            · فترة {{ $invoice->period_start->format('Y-m-d') }} إلى {{ $invoice->period_end->format('Y-m-d') }}
        </p>
    </div>
    <a href="{{ route('admin.invoices.index') }}" class="btn-ghost">رجوع</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card overflow-hidden">
            <h2 class="border-b border-slate-100 px-5 py-4 text-sm font-bold">بنود الفاتورة</h2>

            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-semibold">البند</th>
                        <th class="px-4 py-3 text-start font-semibold">الكمية</th>
                        <th class="px-4 py-3 text-start font-semibold">السعر</th>
                        <th class="px-4 py-3 text-start font-semibold">المبلغ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($invoice->items as $item)
                        <tr>
                            <td class="px-4 py-3">{{ $item->description }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ number_format($item->quantity) }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ number_format($item->unit_price) }}</td>
                            <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($item->amount) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-slate-500">
                                لا بنود — لا اشتراك ولا شحنات مفوترة في هذه الفترة.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-slate-50 font-bold">
                    <tr>
                        <td class="px-4 py-3" colspan="3">الإجمالي</td>
                        <td class="px-4 py-3 text-brand-700" dir="ltr">{{ number_format($invoice->total) }} د.ع</td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الدفعات</h2>

            @if ($invoice->payments->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">لا دفعات بعد.</p>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($invoice->payments as $payment)
                        <div class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                            <span class="font-semibold" dir="ltr">{{ number_format($payment->amount) }} د.ع</span>
                            <span class="text-slate-600">
                                {{ ['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                                    'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                                    'bank_transfer' => 'حوالة مصرفية', 'other' => 'أخرى'][$payment->method] ?? $payment->method }}
                            </span>
                            @if ($payment->reference)
                                <span class="font-mono text-xs text-slate-500" dir="ltr">{{ $payment->reference }}</span>
                            @endif
                            <span class="ms-auto text-xs text-slate-400" dir="ltr">
                                {{ $payment->paid_at->format('Y-m-d H:i') }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الحساب</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-600">الاشتراك</dt>
                    <dd dir="ltr">{{ number_format($invoice->subscription_amount) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-600">
                        العمولة
                        <span class="text-xs text-slate-400">
                            ({{ number_format($invoice->billable_shipments) }} شحنة)
                        </span>
                    </dt>
                    <dd dir="ltr">{{ number_format($invoice->commission_amount) }}</dd>
                </div>
                <div class="flex justify-between border-t border-slate-200 pt-2">
                    <dt class="font-semibold">الإجمالي</dt>
                    <dd class="font-bold" dir="ltr">{{ number_format($invoice->total) }}</dd>
                </div>
                <div class="flex justify-between text-emerald-700">
                    <dt>المدفوع</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($invoice->amount_paid) }}</dd>
                </div>
                <div class="flex justify-between border-t-2 border-slate-300 pt-2">
                    <dt class="font-bold">المتبقّي</dt>
                    <dd class="text-lg font-bold {{ $invoice->balanceDue() > 0 ? 'text-amber-700' : 'text-emerald-700' }}"
                        dir="ltr">{{ number_format($invoice->balanceDue()) }} د.ع</dd>
                </div>
                @if ($invoice->due_at)
                    <div class="flex justify-between border-t border-slate-100 pt-2 text-xs text-slate-500">
                        <dt>تستحقّ في</dt>
                        <dd dir="ltr">{{ $invoice->due_at->format('Y-m-d') }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        @if ($invoice->balanceDue() > 0 && $invoice->status !== 'void')
            <form method="POST" action="{{ route('admin.invoices.pay', $invoice) }}" class="card space-y-4 p-5">
                @csrf
                <h2 class="text-sm font-bold">تسجيل دفعة</h2>

                <div>
                    <label class="field-label" for="amount">المبلغ</label>
                    <div class="relative">
                        <input id="amount" name="amount" type="number" min="1" step="1000" required
                               class="field-input pe-12 text-left" dir="ltr"
                               value="{{ old('amount', $invoice->balanceDue()) }}">
                        <span class="absolute inset-y-0 end-3 flex items-center text-xs text-slate-400">د.ع</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">الدفع الجزئي مسموح.</p>
                    @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="method">الطريقة</label>
                    <select id="method" name="method" class="field-input" required>
                        @foreach (['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                                   'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                                   'bank_transfer' => 'حوالة مصرفية', 'other' => 'أخرى'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('method') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="field-label" for="reference">رقم الحوالة</label>
                    <input id="reference" name="reference" class="field-input text-left" dir="ltr"
                           value="{{ old('reference') }}">
                </div>

                <button type="submit" class="btn-primary w-full">سجّل الدفعة</button>
            </form>
        @endif

        @if ($invoice->subscription)
            <section class="card p-5">
                <h2 class="mb-3 text-sm font-bold">الاشتراك وقت الفوترة</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">الباقة</dt>
                        <dd class="font-medium">{{ $invoice->subscription->plan->name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">السعر المجمَّد</dt>
                        <dd class="font-medium" dir="ltr">{{ number_format($invoice->subscription->price) }}</dd>
                    </div>
                    @if ($invoice->subscription->commission_per_shipment)
                        <div class="flex justify-between">
                            <dt class="text-slate-500">عمولة الشحنة</dt>
                            <dd class="font-medium" dir="ltr">
                                {{ number_format($invoice->subscription->commission_per_shipment) }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>
        @endif
    </div>
</div>
@endsection

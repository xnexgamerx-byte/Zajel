@extends('layouts.app')
@section('title', 'اشتراك الشركة وفواتيرها')

@section('content')
@php
    $subscription = $dues->subscription;
    $overdue = $dues->oldestOverdue();
    $next = $dues->nextDue();
    $suspendsOn = $dues->suspendsOn();
    $open = $dues->open;
@endphp

<div class="mb-5">
    <h1 class="page-title">اشتراك الشركة وفواتيرها</h1>
    <p class="mt-1 text-sm text-ink-500">ما على شركتك لإدارة المنصّة — الاشتراك والعمولة ورسوم الميزات — وكيف تدفع.</p>
</div>

@if ($company->isHeldForBilling())
    <div class="alert alert-bad mb-5" role="alert">
        <x-icon name="lock" class="size-5 shrink-0"/>
        <div>
            <div class="font-semibold">نظام شركتك متوقّف منذ <span class="num">{{ $company->suspended_at?->format('Y-m-d') }}</span> لتأخّر السداد.</div>
            <p class="mt-1">يعود كلّه وحده حين تُسجَّل الدفعة. ادفع بإحدى الطرق أدناه ثم أبلغ عن دفعتك هنا.</p>
        </div>
    </div>
@endif

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="stat">
        <div class="stat-label">عليك الآن</div>
        <div class="mt-1 text-2xl font-bold {{ $dues->balance() > 0 ? ($overdue ? 'text-bad-700' : 'text-ink-900') : 'text-ok-700' }}">
            <span class="num">{{ number_format($dues->balance()) }}</span>
            <span class="text-sm font-medium text-ink-500">د.ع</span>
        </div>
    </div>
    <div class="stat">
        @if ($overdue)
            <div class="stat-label">متأخّرة</div>
            <div class="mt-1 text-lg font-bold text-bad-700">منذ {{ \App\Support\Arabic::days(max(1, $dues->daysLate())) }}</div>
            <div class="text-xs text-ink-500">موعدها كان <span class="num">{{ $overdue->due_at->format('Y-m-d') }}</span></div>
        @elseif ($next)
            <div class="stat-label">تُستحقّ في</div>
            <div class="mt-1 text-lg font-bold text-ink-900"><span class="num">{{ $next->due_at?->format('Y-m-d') }}</span></div>
            <div class="text-xs text-ink-500">فاتورة <span class="num">{{ $next->number }}</span></div>
        @else
            <div class="stat-label">الفواتير</div>
            <div class="mt-1 text-lg font-bold text-ok-700">لا شيء عليك</div>
        @endif
    </div>
    <div class="stat">
        @if ($company->isHeldForBilling())
            <div class="stat-label">النظام</div>
            <div class="mt-1 text-lg font-bold text-bad-700">متوقّف</div>
            <div class="text-xs text-ink-500">يعود حين تُسجَّل الدفعة</div>
        @elseif ($suspendsOn)
            <div class="stat-label">يتوقّف النظام في</div>
            <div class="mt-1 text-lg font-bold text-bad-700"><span class="num">{{ $suspendsOn->format('Y-m-d') }}</span></div>
            <div class="text-xs text-ink-500">إن لم تُسدَّد المتأخّرة قبله</div>
        @elseif ($subscription)
            <div class="stat-label">الاشتراك</div>
            <div class="mt-1 text-lg font-bold text-ink-900">{{ $subscription->plan?->name }}</div>
            <div class="text-xs text-ink-500">
                <span class="num">{{ number_format($subscription->price) }}</span> د.ع {{ $subscription->cycleLabel() }}
            </div>
        @else
            <div class="stat-label">الاشتراك</div>
            <div class="mt-1 text-lg font-bold text-ink-500">لا اشتراك قائم</div>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card overflow-hidden">
            <div class="px-5 pt-5">
                <h2 class="card-title">الفواتير</h2>
                <p class="card-hint">تصدر أول كل شهر عن الشهر الذي قبله: الاشتراك، وعمولة الشحنات المسلَّمة، ورسوم الميزات.</p>
            </div>
            @if ($invoices->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-ink-500">لا فواتير بعد.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>الفاتورة</th>
                                <th>الشهر</th>
                                <th>المبلغ</th>
                                <th>الباقي</th>
                                <th>الموعد</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($invoices as $invoice)
                                <tr class="hover:bg-ink-50">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('billing.invoice', $invoice) }}" class="num text-xs font-semibold text-[var(--brand)] hover:underline">{{ $invoice->number }}</a>
                                    </td>
                                    <td class="num px-4 py-3 text-xs text-ink-500">{{ $invoice->period_start->format('Y-m') }}</td>
                                    <td class="num px-4 py-3 font-semibold">{{ number_format($invoice->total) }}</td>
                                    <td class="num px-4 py-3 {{ $invoice->balanceDue() > 0 ? 'font-semibold text-warn-700' : 'text-ink-400' }}">{{ number_format($invoice->balanceDue()) }}</td>
                                    <td class="num px-4 py-3 text-xs text-ink-500">{{ $invoice->due_at?->format('Y-m-d') }}</td>
                                    <td class="px-4 py-3"><x-invoice-status :status="$invoice->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($invoices->hasPages())
                    <div class="border-t border-ink-100 px-4 py-3">{{ $invoices->links() }}</div>
                @endif
            @endif
        </section>

        <section class="card p-5" id="notify">
            <h2 class="card-title">دفعتُ — أبلغ عن دفعة</h2>
            <p class="card-hint">بعد أن تحوّل المبلغ اكتب رقم الحوالة وأرفق صورة الإيصال إن شئت. تُسجَّل على الفاتورة حين تؤكّدها إدارة المنصّة، وتقرأ هنا ما يُقرَّر فيها.</p>

            @if ($open->isEmpty())
                <p class="mt-3 text-sm text-ink-500">لا فاتورة عليها باقٍ.</p>
            @else
                <form method="POST" action="{{ route('billing.notify') }}" enctype="multipart/form-data" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label class="field-label" for="invoice_id">الفاتورة</label>
                        <select id="invoice_id" name="invoice_id" class="field-input" required>
                            @foreach ($open as $invoice)
                                <option value="{{ $invoice->id }}" @selected((int) old('invoice_id', $open->first()->id) === $invoice->id)>
                                    {{ $invoice->number }} — باقيها {{ number_format($invoice->balanceDue()) }} د.ع
                                </option>
                            @endforeach
                        </select>
                        @error('invoice_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="amount">المبلغ المدفوع</label>
                        <div class="relative">
                            <input id="amount" name="amount" inputmode="numeric" class="field-input num ps-12 text-left" dir="ltr" required data-money
                                   value="{{ old('amount', $open->first()->balanceDue()) }}">
                            <span class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-xs text-ink-400">د.ع</span>
                        </div>
                        @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="method">طريقة الدفع</label>
                        <select id="method" name="method" class="field-input" required>
                            @foreach (\App\Models\Payment::METHODS as $value => $label)
                                <option value="{{ $value }}" @selected(old('method', 'zaincash') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="paid_on">تاريخ الدفع</label>
                        <input id="paid_on" name="paid_on" type="date" class="field-input num" required
                               value="{{ old('paid_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}">
                        @error('paid_on') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="reference">رقم الحوالة (اختياري)</label>
                        <input id="reference" name="reference" class="field-input num" maxlength="120" value="{{ old('reference') }}" autocomplete="off">
                    </div>
                    <div>
                        <label class="field-label" for="proof">صورة الإيصال (اختيارية)</label>
                        <input id="proof" name="proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="field-input">
                        @error('proof') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="field-label" for="note">ملاحظة (اختيارية)</label>
                        <input id="note" name="note" class="field-input" maxlength="500" value="{{ old('note') }}">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">أبلغ عن الدفعة</button>
                    </div>
                </form>
            @endif

            @if ($notices->isNotEmpty())
                <h3 class="mt-6 mb-2 text-sm font-bold">ما أبلغتم عنه</h3>
                <ul class="divide-y divide-ink-100">
                    @foreach ($notices as $notice)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                            <span class="num font-semibold">{{ number_format($notice->amount) }} د.ع</span>
                            <span class="text-ink-600">{{ $notice->methodLabel() }}</span>
                            @if ($notice->invoice)
                                <span class="num text-xs text-ink-500">{{ $notice->invoice->number }}</span>
                            @endif
                            <span class="num text-xs text-ink-400">{{ $notice->paid_on->format('Y-m-d') }}</span>
                            @if ($notice->hasProof())
                                <a href="{{ route('billing.proof', $notice) }}" target="_blank" class="text-xs text-[var(--brand)] hover:underline">الإيصال</a>
                            @endif
                            <span class="chip {{ $notice->statusChip() }} ms-auto">{{ $notice->statusLabel() }}</span>
                            @if ($notice->status === 'rejected' && $notice->reject_reason)
                                <span class="w-full text-xs text-bad-700">السبب: {{ $notice->reject_reason }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="card-title">كيف تدفع</h2>
            @if (filled($methods))
                <p class="mt-2 text-sm leading-7 whitespace-pre-line text-ink-800">{{ $methods }}</p>
            @else
                <p class="mt-2 text-sm text-ink-500">تواصل مع إدارة المنصّة لتعرف طريقة الدفع.</p>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="card-title">الاشتراك</h2>
            @if ($subscription)
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">الباقة</dt>
                        <dd class="font-semibold">{{ $subscription->plan?->name }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">السعر</dt>
                        <dd><span class="num font-semibold">{{ number_format($subscription->price) }}</span> د.ع {{ $subscription->cycleLabel() }}</dd>
                    </div>
                    @if ($subscription->commission_per_shipment)
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">عمولة الشحنة المسلَّمة</dt>
                            <dd><span class="num">{{ number_format($subscription->commission_per_shipment) }}</span> د.ع</dd>
                        </div>
                    @endif
                    @if ($subscription->commission_percent > 0)
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">نسبة من قيمة المسلَّم</dt>
                            <dd><span class="num">{{ rtrim(rtrim(number_format($subscription->commission_percent, 2), '0'), '.') }}</span>٪</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-3 border-t border-ink-100 pt-2.5">
                        <dt class="text-ink-500">{{ $subscription->auto_renew && ! $subscription->cancelled_at && $subscription->status === 'active' ? 'يتجدّد في' : 'ينتهي في' }}</dt>
                        <dd class="num font-semibold">{{ $subscription->ends_at?->format('Y-m-d') }}</dd>
                    </div>
                </dl>
            @elseif ($company->status === 'trial')
                <p class="mt-2 text-sm text-ink-600">
                    فترةٌ تجريبية @if ($company->trial_ends_at) تنتهي في <span class="num font-semibold">{{ $company->trial_ends_at->format('Y-m-d') }}</span>@endif.
                </p>
            @else
                <p class="mt-2 text-sm text-ink-500">لا اشتراك قائم — راجع إدارة المنصّة.</p>
            @endif
        </section>

        {{-- ما يعمل في نظامها من ميزات وبكم: تفتحها إدارة المنصّة وتُفوتَر عليها (docs/plan/35) --}}
        <section class="card p-5" id="features">
            <h2 class="card-title">ميزات نظامك</h2>
            <p class="card-hint">ورسم كلٍّ منها الشهري على فاتورتك. وما ليس مفتوحاً يُفتح بطلبٍ إلى إدارة المنصّة.</p>
            <ul class="mt-2 divide-y divide-ink-100">
                @foreach ($features as $row)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0">{{ $row['feature']->label() }}</span>
                        @if ($row['enabled'])
                            <span class="chip chip-ok shrink-0">{{ $row['price'] ? number_format($row['price']).' د.ع شهرياً' : 'مفتوحة' }}</span>
                        @else
                            <span class="chip chip-mute shrink-0">غير مفعّلة</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
@endsection

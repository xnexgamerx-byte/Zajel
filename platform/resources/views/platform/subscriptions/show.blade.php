@extends('layouts.platform')
@section('title', 'اشتراك '.$company->name)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <span class="h-5 w-5 rounded-lg" style="background: {{ $company->primary_color }}"></span>
            <h1 class="page-title">اشتراك {{ $company->name }}</h1>
            <x-company-status :status="$company->status" />
        </div>
        <p class="mt-1 text-sm text-ink-500">
            ما تدفعه لقاء النظام. وأسعار توصيلها مع تجّارها شأنها، من «التسعيرات» في نظامها.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.companies.show', $company) }}" class="btn-ghost">صفحة الشركة</a>
        <a href="{{ route('admin.subscriptions.index') }}" class="btn-ghost">رجوع</a>
    </div>
</div>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">تدفع الآن</div>
        @if ($current)
            <div class="mt-1 text-2xl font-bold text-[var(--brand)]">
                <span class="num">{{ number_format($current->price) }}</span>
                <span class="text-sm font-medium text-ink-500">د.ع {{ $current->cycleLabel() }}</span>
            </div>
        @else
            <div class="mt-1 text-2xl font-bold text-ink-400">لا شيء</div>
        @endif
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">عليها</div>
        <div class="mt-1 text-2xl font-bold {{ $owed > 0 ? 'text-warn-700' : 'text-ink-900' }}">
            <span class="num">{{ number_format($owed) }}</span>
            <span class="text-sm font-medium text-ink-500">د.ع</span>
        </div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">دفعت منذ اشتراكها</div>
        <div class="mt-1 text-2xl font-bold text-ok-700">
            <span class="num">{{ number_format($paid) }}</span>
            <span class="text-sm font-medium text-ink-500">د.ع</span>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الاشتراك الحالي</h2>

            @if ($current)
                <dl class="grid grid-cols-1 gap-x-8 gap-y-2.5 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">الباقة</dt>
                        <dd class="font-semibold">{{ $current->plan->name }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">الحالة</dt>
                        <dd><x-subscription-status :status="$current->status" /></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">المبلغ</dt>
                        <dd><span class="num font-semibold">{{ number_format($current->price) }}</span> د.ع {{ $current->cycleLabel() }}</dd>
                    </div>
                    @if ($current->commission_per_shipment > 0 || $current->commission_percent > 0)
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">عمولة الشحنة المسلَّمة</dt>
                            <dd>
                                @if ($current->commission_per_shipment > 0)
                                    <span class="num">{{ number_format($current->commission_per_shipment) }}</span> د.ع
                                @endif
                                @if ($current->commission_percent > 0)
                                    <span class="num">{{ rtrim(rtrim(number_format($current->commission_percent, 2), '0'), '.') }}%</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">بدأ في</dt>
                        <dd class="num">{{ $current->starts_at->format('Y-m-d') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">{{ $current->auto_renew ? 'يتجدّد في' : 'ينتهي في' }}</dt>
                        <dd class="num font-semibold">{{ $current->ends_at->format('Y-m-d') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">المتبقّي</dt>
                        <dd class="font-bold {{ $current->daysUntilEnd() <= 7 ? 'text-bad-700' : 'text-[var(--brand)]' }}">
                            {{ $current->remainingLabel() }}
                        </dd>
                    </div>
                    @if ($current->notes)
                        <div class="sm:col-span-2">
                            <dt class="text-ink-500">ملاحظة</dt>
                            <dd class="mt-1 text-ink-700">{{ $current->notes }}</dd>
                        </div>
                    @endif
                </dl>

                <form method="POST" action="{{ route('admin.subscriptions.cancel', $company) }}"
                      class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 pt-4">
                    @csrf
                    <p class="max-w-md text-xs text-ink-500">
                        الإلغاء يوقف التجديد، وفاتورة هذا الشهر آخر ما يُحسب به.
                        والشركة تبقى تعمل حتى توقفها من صفحتها.
                    </p>
                    <button type="submit" class="btn-ghost text-bad-700 ring-bad-200 hover:bg-bad-50">ألغِ الاشتراك</button>
                </form>
            @else
                <p class="text-sm text-ink-500">
                    لا اشتراك الآن: لا تدفع شيئاً ولا تصدر لها فواتير. أضفه من النموذج.
                </p>
            @endif
        </section>

        <section class="card p-5">
            <div class="mb-4 flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold">فواتيرها</h2>
                <a href="{{ route('admin.invoices.index', ['company_id' => $company->id]) }}"
                   class="text-xs font-semibold text-[var(--brand)] hover:underline">كل فواتيرها</a>
            </div>

            @if ($invoices->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">
                    لا فواتير بعد. تصدر من «الفواتير» كل شهر، ومنها تُسجَّل الدفعات.
                </p>
            @else
                <div class="divide-y divide-ink-100">
                    @foreach ($invoices as $invoice)
                        <a href="{{ route('admin.invoices.show', $invoice) }}"
                           class="flex flex-wrap items-center gap-3 py-2.5 text-sm hover:bg-ink-50">
                            <span class="font-mono text-xs font-semibold text-[var(--brand)]" dir="ltr">{{ $invoice->number }}</span>
                            <span class="text-xs text-ink-500" dir="ltr">{{ $invoice->period_start->format('Y-m') }}</span>
                            <span class="ms-auto">
                                <span class="num font-semibold">{{ number_format($invoice->total) }}</span>
                                @if ($invoice->balanceDue() > 0 && $invoice->status !== 'void')
                                    <span class="text-xs text-warn-700">
                                        (بقي <span class="num">{{ number_format($invoice->balanceDue()) }}</span>)
                                    </span>
                                @endif
                            </span>
                            <x-invoice-status :status="$invoice->status" />
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($history->isNotEmpty())
            <section class="card overflow-hidden">
                <h2 class="px-5 pt-5 pb-3 text-sm font-bold">سجلّ اشتراكاتها</h2>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th >الباقة</th>
                                <th >المبلغ</th>
                                <th >من</th>
                                <th >إلى</th>
                                <th >الحالة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($history as $row)
                                <tr>
                                    <td class="px-4 py-2.5">{{ $row->plan->name }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        <span class="num">{{ number_format($row->price) }}</span>
                                        <span class="text-xs text-ink-500">{{ $row->cycleLabel() }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap"><span class="num text-xs">{{ $row->starts_at->format('Y-m-d') }}</span></td>
                                    <td class="px-4 py-2.5 whitespace-nowrap text-xs">
                                        @if ($row->cancelled_at)
                                            أُلغي <span class="num">{{ $row->cancelled_at->format('Y-m-d') }}</span>
                                        @else
                                            <span class="num">{{ $row->ends_at->format('Y-m-d') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5"><x-subscription-status :status="$row->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>

    <div class="space-y-5">
        <form method="POST" action="{{ route('admin.subscriptions.store', $company) }}" class="card space-y-4 p-5">
            @csrf
            <div>
                <h2 class="text-sm font-bold">{{ $current ? 'تغيير الاشتراك' : 'اشتراك جديد' }}</h2>
                <p class="mt-1 text-xs text-ink-500">
                    @if ($current)
                        يحلّ محلّ الحالي من تاريخ بدايته، والحالي يبقى في السجلّ.
                    @else
                        تبدأ الشركة الدفع من تاريخ البداية، ويتجدّد كل دورةٍ حتى يُلغى.
                    @endif
                    @if ($company->status === 'trial')
                        وتنتهي تجربتها فتصير مفعّلة.
                    @endif
                </p>
            </div>

            <div>
                <label class="field-label" for="plan_id">الباقة <span class="text-red-500">*</span></label>
                <select id="plan_id" name="plan_id" class="field-input" required>
                    <option value="">اختر</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected((int) old('plan_id', $current?->plan_id) === $plan->id)>
                            {{ $plan->name }} — {{ number_format($plan->price_monthly) }} شهرياً، {{ number_format($plan->price_yearly) }} سنوياً
                        </option>
                    @endforeach
                </select>
                @error('plan_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="billing_cycle">الدورة <span class="text-red-500">*</span></label>
                <select id="billing_cycle" name="billing_cycle" class="field-input" required>
                    <option value="monthly" @selected(old('billing_cycle', $current?->billing_cycle ?? 'monthly') === 'monthly')>شهرية</option>
                    <option value="yearly" @selected(old('billing_cycle', $current?->billing_cycle) === 'yearly')>سنوية</option>
                </select>
                @error('billing_cycle') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="price">المبلغ المتّفق عليه</label>
                <input id="price" name="price" class="field-input num" inputmode="numeric" autocomplete="off"
                       placeholder="فارغاً: سعر الباقة لدورتها" value="{{ old('price') }}">
                <p class="mt-1 text-xs text-ink-500">بالدينار، إن اتّفقتما على غير سعر الباقة.</p>
                @error('price') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="starts_at">يبدأ في <span class="text-red-500">*</span></label>
                <input id="starts_at" name="starts_at" type="date" class="field-input num" required
                       value="{{ old('starts_at', now()->toDateString()) }}">
                @error('starts_at') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="notes">ملاحظة</label>
                <textarea id="notes" name="notes" rows="2" maxlength="500" class="field-input"
                          placeholder="مثال: خصم أوّل ثلاثة أشهر">{{ old('notes') }}</textarea>
                @error('notes') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn-primary w-full">احفظ الاشتراك</button>
        </form>
    </div>
</div>
@endsection

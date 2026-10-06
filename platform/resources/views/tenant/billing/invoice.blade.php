@extends('layouts.app')
@section('title', 'فاتورة '.$invoice->number)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="page-title num">{{ $invoice->number }}</h1>
            <x-invoice-status :status="$invoice->status" />
        </div>
        <p class="mt-1 text-sm text-ink-500">
            فاتورة إدارة المنصّة على {{ $company->name }} عن
            <span class="num">{{ $invoice->period_start->format('Y-m-d') }}</span> إلى <span class="num">{{ $invoice->period_end->format('Y-m-d') }}</span>
            @if ($invoice->due_at) · تُستحقّ في <span class="num">{{ $invoice->due_at->format('Y-m-d') }}</span>@endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2 print:hidden">
        <button type="button" data-print class="btn-ghost"><x-icon name="printer" class="size-4"/> اطبع</button>
        <a href="{{ route('billing') }}" class="btn-ghost">كل الفواتير</a>
    </div>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card overflow-hidden lg:col-span-2">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">البنود</h2>
        <table class="tbl">
            <thead>
                <tr>
                    <th>البند</th>
                    <th>المبلغ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($invoice->items as $item)
                    <tr>
                        <td class="px-4 py-3">{{ $item->description }}</td>
                        <td class="num px-4 py-3 font-semibold">{{ number_format($item->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-ink-50 font-bold">
                <tr>
                    <td class="px-4 py-3">الإجمالي</td>
                    <td class="num px-4 py-3 text-[var(--brand)]">{{ number_format($invoice->total) }} د.ع</td>
                </tr>
            </tfoot>
        </table>
    </section>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-3 text-sm font-bold">الحساب</h2>
            <dl class="space-y-2 text-sm">
                @foreach ([
                    ['الاشتراك', $invoice->subscription_amount],
                    ['العمولة', $invoice->commission_amount],
                    ['الميزات الإضافية', $invoice->features_amount],
                ] as [$label, $value])
                    @if ($value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-600">{{ $label }}</dt>
                            <dd class="num">{{ number_format($value) }}</dd>
                        </div>
                    @endif
                @endforeach
                <div class="flex justify-between gap-3 border-t border-ink-200 pt-2">
                    <dt class="font-semibold">الإجمالي</dt>
                    <dd class="num font-bold">{{ number_format($invoice->total) }}</dd>
                </div>
                <div class="flex justify-between gap-3 text-ok-700">
                    <dt>المدفوع</dt>
                    <dd class="num font-semibold">{{ number_format($invoice->amount_paid) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-t-2 border-ink-300 pt-2">
                    <dt class="font-bold">الباقي</dt>
                    <dd class="num text-lg font-bold {{ $invoice->balanceDue() > 0 ? 'text-warn-700' : 'text-ok-700' }}">{{ number_format($invoice->balanceDue()) }} د.ع</dd>
                </div>
            </dl>
            @if ($invoice->balanceDue() > 0)
                <a href="{{ route('billing') }}#notify" class="btn-primary mt-4 w-full print:hidden">أبلغ عن دفعة</a>
            @endif
        </section>

        @if ($invoice->payments->isNotEmpty())
            <section class="card p-5">
                <h2 class="mb-3 text-sm font-bold">الدفعات</h2>
                <ul class="divide-y divide-ink-100 text-sm">
                    @foreach ($invoice->payments as $payment)
                        <li class="flex flex-wrap items-center gap-x-3 py-2">
                            <span class="num font-semibold">{{ number_format($payment->amount) }} د.ع</span>
                            <span class="text-ink-600">{{ \App\Models\Payment::METHODS[$payment->method] ?? $payment->method }}</span>
                            <span class="num ms-auto text-xs text-ink-400">{{ $payment->paid_at->format('Y-m-d') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
@endsection

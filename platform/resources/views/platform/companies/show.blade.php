@extends('layouts.platform')
@section('title', $company->name)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <span class="h-5 w-5 rounded-lg" style="background: {{ $company->primary_color }}"></span>
            <h1 class="page-title">{{ $company->name }}</h1>
            <x-company-status :status="$company->status" />
        </div>
        <p class="mt-1 font-mono text-sm text-ink-500" dir="ltr">
            {{ $company->slug }}.{{ config('zajel.tenant_domain') }}
        </p>
        {{-- بلا نطاق: نطاقها الفرعي لا يعمل بعد، ونظامها على هذا العنوان --}}
        @if ($company->slug === \App\Support\Tenancy\DefaultCompany::slug()
            && \App\Support\Tenancy\DefaultCompany::covers(request()->getHost()))
            <p class="mt-1 text-sm text-ink-700">
                نظامها الآن على
                <a href="{{ request()->getSchemeAndHttpHost() }}" target="_blank" rel="noopener"
                   class="num font-semibold text-[var(--brand)] hover:underline">{{ request()->getHost() }}</a>
                حتى يُربط النطاق.
            </p>
        @endif
    </div>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.companies.impersonate', $company) }}">
            @csrf
            <button class="btn-ghost" @disabled(! $company->isOperational())>ادخل نظامها</button>
        </form>

        @if ($company->status === 'suspended')
            <form method="POST" action="{{ route('admin.companies.activate', $company) }}">
                @csrf
                <button type="submit" class="btn-primary">تفعيل</button>
            </form>
        @endif
    </div>
</div>

@if ($company->status === 'suspended')
    <div class="mb-5 rounded-lg bg-bad-50 px-4 py-3 text-sm text-bad-700 ring-1 ring-bad-200">
        <span class="font-semibold">موقوفة منذ {{ $company->suspended_at?->format('Y-m-d H:i') }}</span>
        @if ($company->suspended_reason) — {{ $company->suspended_reason }} @endif
    </div>
@endif

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ([
        ['شحنات هذا الشهر', $stats['this_month'], 'text-[var(--brand)]'],
        ['إجمالي الشحنات', $stats['shipments'], 'text-ink-900'],
        ['التجّار', $stats['merchants'], 'text-ink-900'],
        ['المندوبون', $stats['couriers'], 'text-ink-900'],
    ] as [$label, $value, $tone])
        <div class="card p-4">
            <div class="text-xs font-medium text-ink-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}"><span class="num">{{ number_format($value) }}</span></div>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">وضعها المالي الداخلي</h2>
            <p class="mb-4 text-xs text-ink-500">
                هذه أموال الشركة مع تجّارها ومندوبيها — لا علاقة لها باشتراكها معك.
            </p>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-ink-500">مستحقّ لتجّارها</dt>
                    <dd class="mt-1 text-xl font-bold text-[var(--brand)]" dir="ltr">
                        {{ number_format($stats['owed']) }} د.ع
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-500">نقد بيد مندوبيها</dt>
                    <dd class="mt-1 text-xl font-bold text-warn-700" dir="ltr">
                        {{ number_format($stats['in_hand']) }} د.ع
                    </dd>
                </div>
            </dl>
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">آخر فواتيرها</h2>

            @if ($invoices->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">لا فواتير بعد.</p>
            @else
                <div class="divide-y divide-ink-100">
                    @foreach ($invoices as $invoice)
                        <a href="{{ route('admin.invoices.show', $invoice) }}"
                           class="flex flex-wrap items-center gap-3 py-2.5 text-sm hover:bg-ink-50">
                            <span class="font-mono text-xs font-semibold text-[var(--brand)]" dir="ltr">
                                {{ $invoice->number }}
                            </span>
                            <span class="text-xs text-ink-500" dir="ltr">
                                {{ $invoice->period_start->format('Y-m') }}
                            </span>
                            <span class="ms-auto font-semibold" dir="ltr">{{ number_format($invoice->total) }} د.ع</span>
                            <x-invoice-status :status="$invoice->status" />
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">سجلّ التدقيق</h2>
            <p class="mb-4 text-xs text-ink-500">
                كل دخول من المنصّة إلى هذا النظام مسجَّل هنا — وهذا ما يجعل الشركة تثق بوضع حساباتها فيه.
            </p>

            @if ($audit->isEmpty())
                <p class="py-6 text-center text-sm text-ink-500">لا سجلّات.</p>
            @else
                <div class="divide-y divide-ink-100">
                    @foreach ($audit as $entry)
                        <div class="flex items-center justify-between gap-3 py-2 text-sm">
                            <span>
                                {{ [
                                    'company_registered'    => 'تسجيل الشركة',
                                    'company_suspended'     => 'إيقاف',
                                    'company_activated'     => 'تفعيل',
                                    'impersonation_started' => 'دخول من المنصّة',
                                    'impersonation_ended'   => 'خروج من المنصّة',
                                ][$entry->action] ?? $entry->actionLabel() }}
                                @if ($entry->user_name)
                                    <span class="text-ink-500">— {{ $entry->user_name }}</span>
                                @endif
                            </span>
                            <span class="shrink-0 text-xs text-ink-400" dir="ltr">
                                {{ $entry->created_at->format('Y-m-d H:i') }}@if ($entry->ip) · {{ $entry->ip }}@endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الاشتراك</h2>

            @if ($subscription)
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-500">الباقة</dt>
                        <dd class="font-semibold">{{ $subscription->plan->name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">السعر المجمَّد</dt>
                        <dd class="font-semibold" dir="ltr">{{ number_format($subscription->price) }} د.ع</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">الدورة</dt>
                        <dd>{{ $subscription->billing_cycle === 'yearly' ? 'سنوية' : 'شهرية' }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-ink-100 pt-2.5">
                        <dt class="text-ink-500">ينتهي في</dt>
                        <dd class="font-semibold" dir="ltr">{{ $subscription->ends_at?->format('Y-m-d') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">المتبقّي</dt>
                        @php $days = $subscription->daysUntilEnd(); @endphp
                        <dd class="font-bold {{ $days <= 7 ? 'text-bad-700' : 'text-[var(--brand)]' }}">
                            {{ $days < 0 ? 'انتهى منذ '.\App\Support\Arabic::days(-$days) : ($days === 0 ? 'ينتهي اليوم' : \App\Support\Arabic::days($days)) }}
                        </dd>
                    </div>
                </dl>
            @else
                <p class="text-sm text-ink-500">لا اشتراك فعّال.</p>
            @endif
        </section>

        @if ($company->status !== 'suspended')
            <form method="POST" action="{{ route('admin.companies.suspend', $company) }}" class="card space-y-4 p-5">
                @csrf
                <h2 class="text-sm font-bold">إيقاف الشركة</h2>
                <p class="text-xs text-ink-500">
                    يسري فوراً: أول طلب بعده يُرفض. بياناتها تبقى كما هي وتعود بالتفعيل.
                </p>
                <div>
                    <label class="field-label" for="reason">السبب <span class="text-red-500">*</span></label>
                    <input id="reason" name="reason" class="field-input" required
                           placeholder="مثال: تأخّر السداد عن 30 يوماً">
                    @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-ghost w-full text-bad-700 ring-bad-200 hover:bg-bad-50">أوقف الاشتراك</button>
            </form>
        @endif
    </div>
</div>
@endsection

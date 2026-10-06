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
        <a href="{{ route('admin.companies.edit', $company) }}" class="btn-ghost">تعديل البيانات</a>
        <a href="{{ route('admin.companies.system', $company) }}" class="btn-ghost">
            <x-icon name="sliders" class="size-4"/>
            الميزات والمظهر
        </a>

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
        <span class="font-semibold">{{ $company->isHeldForBilling() ? 'أوقفها تأخّر السداد' : 'موقوفة' }} منذ {{ $company->suspended_at?->format('Y-m-d H:i') }}</span>
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
                                    'company_registered'     => 'تسجيل الشركة',
                                    'company_suspended'      => 'إيقاف',
                                    'company_activated'      => 'تفعيل',
                                    'company_updated'        => 'تعديل البيانات',
                                    'subscription_started'   => 'اشتراك جديد',
                                    'subscription_cancelled' => 'إلغاء الاشتراك',
                                    'impersonation_started'  => 'دخول من المنصّة',
                                    'impersonation_ended'    => 'خروج من المنصّة',
                                ][$entry->action] ?? $entry->actionLabel() }}
                                {{-- أيّ ميزةٍ وبكم، وأيّ مظهر --}}
                                @if ($named = \App\Enums\Feature::tryFrom((string) ($entry->new_values['feature'] ?? '')))
                                    «{{ $named->label() }}»@if ($entry->new_values['monthly_price'] ?? 0) بـ <span class="num">{{ number_format($entry->new_values['monthly_price']) }}</span> د.ع شهرياً@endif
                                @elseif ($entry->action === 'theme_changed')
                                    «{{ \App\Support\Theme::NAMES[$entry->new_values['theme'] ?? ''] ?? '' }}»
                                @endif
                                @if ($entry->user_name)
                                    <span class="text-ink-500">— {{ $entry->user_name }}</span>
                                @elseif ($entry->new_values['automatic'] ?? false)
                                    <span class="text-ink-500">— تلقائياً {{ $entry->action === 'company_suspended' ? 'لتأخّر السداد' : 'بعد السداد' }}</span>
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
            <div class="mb-4 flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold">بيانات الشركة</h2>
                <a href="{{ route('admin.companies.edit', $company) }}"
                   class="text-xs font-semibold text-[var(--brand)] hover:underline">تعديل</a>
            </div>
            <dl class="space-y-2.5 text-sm">
                @foreach ([
                    ['الاسم بالإنجليزي', $company->name_en, true],
                    ['الهاتف', $company->phone, true],
                    ['البريد', $company->email, true],
                    ['المحافظة', $company->governorate?->name_ar, false],
                    ['العنوان', $company->address, false],
                ] as [$label, $value, $ltr])
                    <div class="flex justify-between gap-3">
                        <dt class="shrink-0 text-ink-500">{{ $label }}</dt>
                        <dd class="min-w-0 break-words text-end {{ filled($value) ? '' : 'text-ink-400' }}"
                            @if ($ltr && filled($value)) dir="ltr" @endif>{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @php
            $features = $company->featureStates();
            $open = collect($features)->where('enabled', true);
            $theme = $company->theme();
        @endphp
        <section class="card p-5">
            <div class="mb-4 flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold">نظامها</h2>
                <a href="{{ route('admin.companies.system', $company) }}"
                   class="text-xs font-semibold text-[var(--brand)] hover:underline">الميزات والمظهر</a>
            </div>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">ميزاتٌ مفتوحة</dt>
                    <dd><span class="num font-semibold">{{ $open->count() }}</span> من <span class="num">{{ count($features) }}</span></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">رسومها الشهرية</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($open->sum('price')) }} د.ع</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">المظهر</dt>
                    <dd class="flex items-center gap-2">
                        <span class="size-3.5 rounded-full" style="background: {{ $theme->shades[600] }}"></span>
                        {{ $theme->name() }}
                    </dd>
                </div>
            </dl>
            @if ($open->where('price', '>', 0)->isNotEmpty())
                <ul class="mt-3 space-y-1 border-t border-ink-100 pt-3 text-xs text-ink-600">
                    @foreach ($open->where('price', '>', 0) as $row)
                        <li class="flex justify-between gap-3">
                            <span>{{ $row['feature']->label() }}</span>
                            <span class="num" dir="ltr">{{ number_format($row['price']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ما عليها ومتى يوقفها التأخّر، وإعفاؤها منه (docs/plan/36) --}}
        @php $dues = \App\Support\Billing\Dues::for($company); @endphp
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">التحصيل</h2>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">عليها الآن</dt>
                    <dd class="num font-semibold {{ $dues->oldestOverdue() ? 'text-bad-700' : '' }}">{{ number_format($dues->balance()) }} د.ع</dd>
                </div>
                @if ($dues->oldestOverdue())
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">متأخّرة</dt>
                        <dd class="text-bad-700">منذ {{ \App\Support\Arabic::days(max(1, $dues->daysLate())) }}</dd>
                    </div>
                @endif
                @if ($dues->suspendsOn())
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">يتوقّف نظامها في</dt>
                        <dd class="num font-semibold text-bad-700">{{ $dues->suspendsOn()->format('Y-m-d') }}</dd>
                    </div>
                @endif
            </dl>
            <form method="POST" action="{{ route('admin.companies.billing-exempt', $company) }}" class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 pt-3">
                @csrf
                <input type="hidden" name="exempt" value="0">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="exempt" value="1" @checked($company->billing_exempt)>
                    معفاة من الإيقاف التلقائي
                </label>
                <button type="submit" class="btn-ghost py-1">احفظ</button>
            </form>
            <a href="{{ route('admin.settings') }}" class="mt-2 inline-block text-xs text-[var(--brand)] hover:underline">مهلة الإيقاف وطرق الدفع</a>
        </section>

        <section class="card p-5">
            <div class="mb-4 flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold">الاشتراك</h2>
                <a href="{{ route('admin.subscriptions.show', $company) }}"
                   class="text-xs font-semibold text-[var(--brand)] hover:underline">إدارة الاشتراك</a>
            </div>

            @if ($subscription)
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-500">الباقة</dt>
                        <dd class="font-semibold">{{ $subscription->plan->name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">الحالة</dt>
                        <dd><x-subscription-status :status="$subscription->status" /></dd>
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
                        <dd class="font-bold {{ $subscription->daysUntilEnd() <= 7 ? 'text-bad-700' : 'text-[var(--brand)]' }}">
                            {{ $subscription->remainingLabel() }}
                        </dd>
                    </div>
                </dl>
            @else
                <p class="text-sm text-ink-500">لا اشتراك فعّال.</p>
                <a href="{{ route('admin.subscriptions.show', $company) }}" class="btn-primary mt-3 w-full">أضف اشتراكاً</a>
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
                <button type="submit" class="btn-ghost w-full text-bad-700 ring-bad-200 hover:bg-bad-50">أوقف الشركة</button>
            </form>
        @endif
    </div>
</div>
@endsection

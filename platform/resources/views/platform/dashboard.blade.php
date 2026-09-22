@extends('layouts.platform')
@section('title', 'الرئيسية')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">نظرة عامة</h1>
        <p class="mt-1 text-sm text-slate-500">كل الشركات المشتركة على المنصّة.</p>
    </div>
    <a href="{{ route('admin.companies.create') }}" class="btn-primary">+ تسجيل شركة</a>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ([
        ['شركات مفعّلة', $companies['active'], 'text-emerald-700'],
        ['تجريبية', $companies['trial'], 'text-amber-700'],
        ['موقوفة', $companies['suspended'], 'text-red-600'],
    ] as [$label, $value, $tone])
        <div class="card p-4">
            <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </div>
    @endforeach

    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">الإيراد الشهري المتكرّر</div>
        <div class="mt-1 text-2xl font-bold text-brand-700" dir="ltr">
            {{ number_format($mrr) }} <span class="text-sm font-medium text-slate-500">د.ع</span>
        </div>
    </div>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3">
    @foreach ([
        ['شحنات هذا الشهر', (int) ($shipments->this_month ?? 0)],
        ['إجمالي الشحنات', (int) ($shipments->total ?? 0)],
        ['مسلَّمة', (int) ($shipments->delivered ?? 0)],
    ] as [$label, $value])
        <div class="card p-4">
            <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold" dir="ltr">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
    <section class="card p-5">
        <h2 class="mb-4 text-sm font-bold">اشتراكات تنتهي خلال أسبوعين</h2>

        @if ($expiring->isEmpty())
            <p class="py-8 text-center text-sm text-slate-500">لا شيء ينتهي قريباً.</p>
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($expiring as $subscription)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <a href="{{ route('admin.companies.show', $subscription->company) }}"
                           class="font-semibold text-brand-700 hover:underline">
                            {{ $subscription->company->name }}
                        </a>
                        <span class="text-xs text-slate-500">{{ $subscription->plan->name }}</span>
                        @php $days = $subscription->daysRemaining(); @endphp
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1
                                     {{ $days <= 3 ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-50 text-amber-800 ring-amber-200' }}">
                            {{ $days === 0 ? 'ينتهي اليوم' : "باقٍ {$days} يوم" }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="card p-5">
        <h2 class="mb-4 text-sm font-bold">آخر ما جرى</h2>

        @if ($audit->isEmpty())
            <p class="py-8 text-center text-sm text-slate-500">لا نشاط بعد.</p>
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($audit as $entry)
                    <div class="flex items-center justify-between gap-3 py-2">
                        <span class="text-sm">
                            {{ [
                                'company_registered'     => 'سُجّلت شركة',
                                'company_suspended'      => 'أُوقفت شركة',
                                'company_activated'      => 'فُعّلت شركة',
                                'impersonation_started'  => 'دخول إلى نظام شركة',
                                'impersonation_ended'    => 'خروج من نظام شركة',
                            ][$entry->action] ?? $entry->action }}
                            @if ($entry->user_name)
                                <span class="text-slate-500">— {{ $entry->user_name }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-xs text-slate-400" dir="ltr">
                            {{ $entry->created_at->format('Y-m-d H:i') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection

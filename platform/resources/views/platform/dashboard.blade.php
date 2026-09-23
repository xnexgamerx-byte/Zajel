@extends('layouts.platform')
@section('title', 'الرئيسية')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">نظرة عامة</h1>
        <p class="mt-1 text-sm text-ink-500">كل الشركات المشتركة على المنصّة.</p>
    </div>
    <a href="{{ route('admin.companies.create') }}" class="btn-primary">+ تسجيل شركة</a>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ([
        ['شركات مفعّلة', $companies['active'], 'text-ok-700'],
        ['تجريبية', $companies['trial'], 'text-warn-700'],
        ['موقوفة', $companies['suspended'], 'text-bad-700'],
    ] as [$label, $value, $tone])
        <div class="stat">
            <div class="stat-label">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">
                <span class="num">{{ number_format($value) }}</span>
            </div>
        </div>
    @endforeach

    <div class="stat">
        <div class="stat-label">الإيراد الشهري المتكرّر</div>
        <div class="mt-1 text-2xl font-bold text-[var(--brand)]">
            <span class="num">{{ number_format($mrr) }}</span>
            <span class="text-sm font-medium text-ink-500">د.ع</span>
        </div>
    </div>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3">
    @foreach ([
        ['شحنات هذا الشهر', (int) ($shipments->this_month ?? 0)],
        ['إجمالي الشحنات', (int) ($shipments->total ?? 0)],
        ['مسلَّمة', (int) ($shipments->delivered ?? 0)],
    ] as [$label, $value])
        <div class="stat">
            <div class="stat-label">{{ $label }}</div>
            <div class="stat-value"><span class="num">{{ number_format($value) }}</span></div>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
    <section class="card p-5">
        <h2 class="mb-4 text-sm font-bold">انتهاء الاشتراكات</h2>

        @if ($expiring->isEmpty())
            <p class="py-8 text-center text-sm text-ink-500">لا اشتراك انتهى ولا ينتهي خلال أسبوعين.</p>
        @else
            @foreach ([
                'lapsed' => ['انتهت ولم تُجدَّد', 'chip-bad'],
                'today'  => ['تنتهي اليوم', 'chip-warn'],
                'soon'   => ['تنتهي خلال أسبوعين', 'chip-info'],
            ] as $state => [$heading, $tone])
                @continue(! $expiring->has($state))
                <div class="mb-4 last:mb-0">
                    <div class="mb-1.5 flex items-center gap-2">
                        <span class="chip {{ $tone }}">{{ $heading }}</span>
                        <span class="num text-xs text-ink-500">{{ number_format($expiring[$state]->count()) }}</span>
                    </div>
                    <div class="divide-y divide-ink-100">
                        @foreach ($expiring[$state] as $subscription)
                            @php $days = $subscription->daysUntilEnd(); @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <a href="{{ route('admin.companies.show', $subscription->company) }}"
                                   class="font-semibold text-[var(--brand)] hover:underline">
                                    {{ $subscription->company->name }}
                                </a>
                                <span class="text-xs text-ink-500">
                                    {{ $subscription->plan->name }}
                                    @if ($subscription->status === 'trialing') · تجريبي @endif
                                    @if ($subscription->status === 'past_due') · متأخّر الدفع @endif
                                </span>
                                <span class="text-xs {{ $days < 0 ? 'font-semibold text-bad-700' : 'text-ink-600' }}">
                                    @if ($days < 0)
                                        انتهى منذ {{ \App\Support\Arabic::days(-$days) }}
                                    @elseif ($days === 0)
                                        اليوم
                                    @else
                                        باقٍ {{ \App\Support\Arabic::days($days) }} · <span class="num">{{ $subscription->ends_at->format('Y-m-d') }}</span>
                                    @endif
                                    {{-- التلقائيّ النشط يتجدّد ليلاً (zajel:renew)؛ غيره يحتاج قراراً --}}
                                    @if ($subscription->status === 'active' && $subscription->auto_renew)
                                        <span class="chip chip-mute ms-1">يتجدّد تلقائياً</span>
                                    @elseif ($days >= 0)
                                        <span class="chip chip-warn ms-1">لا يتجدّد</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </section>

    <section class="card p-5">
        <h2 class="mb-4 text-sm font-bold">آخر ما جرى</h2>

        @if ($audit->isEmpty())
            <p class="py-8 text-center text-sm text-ink-500">لا نشاط بعد.</p>
        @else
            <div class="divide-y divide-ink-100">
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
                                <span class="text-ink-500">— {{ $entry->user_name }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-xs text-ink-400" dir="ltr">
                            {{ $entry->created_at->format('Y-m-d H:i') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection

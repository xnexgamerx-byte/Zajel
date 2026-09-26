@extends('layouts.platform')
@section('title', 'الاشتراكات')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">الاشتراكات</h1>
        <p class="mt-1 text-sm text-ink-500">
            مالك مع الشركات: ما تدفعه كل شركةٍ لقاء النظام، وما بقي عليها.
            وأسعار التوصيل ليست هنا — تلك بين كل شركةٍ وتجّارها.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.invoices.index') }}" class="btn-ghost">الفواتير</a>
        <a href="{{ route('admin.plans.index') }}" class="btn-ghost">الباقات</a>
    </div>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ([
        ['الدخل الشهري', $summary['monthly'], 'text-[var(--brand)]', true, 'من الاشتراكات المدفوعة'],
        ['محصَّل هذا الشهر', $summary['collected'], 'text-ok-700', true, 'دفعاتٌ سُجّلت على الفواتير'],
        ['مستحقّ على الشركات', $summary['owed'], 'text-warn-700', true, 'فواتير لم تُسدَّد كاملةً'],
        ['شركات بلا اشتراك', $summary['without'], 'text-ink-900', false, 'لا تدفع شيئاً الآن'],
    ] as [$label, $value, $tone, $money, $hint])
        <div class="card p-4">
            <div class="text-xs font-medium text-ink-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">
                <span class="num">{{ number_format($value) }}</span>
                @if ($money)<span class="text-sm font-medium text-ink-500">د.ع</span>@endif
            </div>
            <div class="mt-0.5 text-xs text-ink-400">{{ $hint }}</div>
        </div>
    @endforeach
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-64 flex-1">
        <label class="field-label" for="q">بحث</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input" placeholder="اسم الشركة أو نطاقها">
    </div>
    <button type="submit" class="btn-primary">بحث</button>
    @if (request('q'))
        <a href="{{ route('admin.subscriptions.index') }}" class="btn-ghost">مسح</a>
    @endif
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th >الشركة</th>
                    <th >الباقة</th>
                    <th >المبلغ</th>
                    <th >الفترة</th>
                    <th >المتبقّي</th>
                    <th >عليها</th>
                    <th >الحالة</th>
                    <th ></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($companies as $company)
                    @php
                        $subscription = $current[$company->id] ?? null;
                        $due = (int) ($owed[$company->id] ?? 0);
                    @endphp
                    <tr class="hover:bg-ink-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 shrink-0 rounded-full"
                                      style="background: {{ $company->primary_color }}"></span>
                                <a href="{{ route('admin.subscriptions.show', $company) }}"
                                   class="font-semibold text-[var(--brand)] hover:underline">{{ $company->name }}</a>
                            </div>
                        </td>
                        @if ($subscription)
                            <td class="px-4 py-3">{{ $subscription->plan->name }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="num font-semibold">{{ number_format($subscription->price) }}</span>
                                <span class="text-xs text-ink-500">د.ع {{ $subscription->cycleLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs text-ink-500">
                                <span class="num">{{ $subscription->starts_at->format('Y-m-d') }}</span>
                                ←
                                <span class="num">{{ $subscription->ends_at->format('Y-m-d') }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap {{ $subscription->daysUntilEnd() <= 7 ? 'font-semibold text-bad-700' : 'text-ink-600' }}">
                                {{ $subscription->remainingLabel() }}
                            </td>
                        @else
                            <td colspan="4" class="px-4 py-3 text-ink-400">لا اشتراك: لا تدفع شيئاً الآن.</td>
                        @endif
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="num font-semibold {{ $due > 0 ? 'text-warn-700' : 'text-ink-400' }}">{{ number_format($due) }}</span>
                        </td>
                        <td class="px-4 py-3"><x-subscription-status :status="$subscription?->status" /></td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <a href="{{ route('admin.subscriptions.show', $company) }}"
                               class="text-sm font-semibold text-[var(--brand)] hover:underline">
                                {{ $subscription ? 'إدارة' : 'أضف اشتراكاً' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-16 text-center text-ink-500">
                            {{ request('q') ? 'لا شركة بهذا الاسم.' : 'لا شركات بعد.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($companies->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $companies->links() }}</div>
    @endif
</div>
@endsection

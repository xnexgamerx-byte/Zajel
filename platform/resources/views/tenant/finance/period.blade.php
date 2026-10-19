@extends('layouts.app')
@section('title', 'الفترة '.$period->number)

@section('content')
@include('tenant.finance._tabs', ['title' => 'الفترة '.$period->number, 'lead' => $period->label()])

@error('period') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('exceptions') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror

<div class="card mb-5 flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
    <div class="space-y-1">
        <span @class(['chip', 'chip-ok' => $period->isClosed(), 'chip-warn' => ! $period->isClosed()])>{{ $period->statusLabel() }}</span>
        <div class="text-ink-600">أعدّها {{ $period->preparedBy?->name ?? '—' }} <span class="num">{{ $period->prepared_at?->format('Y-m-d H:i') }}</span>
            @if ($period->isClosed()) · اعتمدها {{ $period->approvedBy?->name ?? '—' }} <span class="num">{{ $period->approved_at?->format('Y-m-d H:i') }}</span>@endif</div>
        @if ($period->closing_entry_id)<a href="{{ route('finance.entries.show', $period->closing_entry_id) }}" class="text-xs text-[var(--brand)] hover:underline">قيد الإقفال</a>@endif
    </div>
    @unless ($period->isClosed())
        <div class="flex flex-wrap gap-2">
            @can('periods.close')
                <form method="POST" action="{{ route('finance.periods.approve', $period) }}"
                      data-confirm="اعتماد إقفال الفترة {{ $period->label() }}؟ بعده لا يُكتب قيدٌ بتاريخها.">
                    @csrf<button class="btn-primary">اعتماد الإقفال</button>
                </form>
            @endcan
            @can('periods.prepare')
                <form method="POST" action="{{ route('finance.periods.cancel', $period) }}" class="flex items-center gap-1" data-confirm="إلغاء إعداد هذه الفترة؟">
                    @csrf
                    <input name="reason" required maxlength="255" placeholder="السبب" class="field-input w-40 py-1 text-xs" aria-label="سبب الإلغاء">
                    <button class="btn-ghost text-bad-700">إلغاء الإعداد</button>
                </form>
            @endcan
        </div>
    @endunless
</div>

<section class="card mb-5 p-5">
    <h2 class="card-title mb-3">{{ $period->isClosed() ? 'الأرقام المحفوظة عند الإقفال' : 'الأرقام عند الإعداد' }}</h2>
    @include('tenant.finance._period-figures', ['figures' => $period->figures ?? []])
</section>

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">الفحوص والاستثناءات</h2>
    @include('tenant.finance._period-checks', ['checks' => $period->checks ?? [], 'exceptions' => $period->exceptions ?? []])
</section>

@if ($period->isClosed())
    <section class="card mb-5 overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">نسخ التقرير</h2>
        <ul class="divide-y divide-ink-100 text-sm">
            @foreach ($period->reports as $report)
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
                    <span>النسخة <span class="num font-semibold">{{ $report->version }}</span> — {{ $report->reason }}
                        <span class="text-xs text-ink-500">{{ $report->issuedBy?->name }} <span class="num">{{ $report->issued_at?->format('Y-m-d H:i') }}</span></span></span>
                    <a href="{{ route('finance.periods.report', [$period, $report->version]) }}" target="_blank" class="btn-ghost py-1 text-xs"><x-icon name="printer" class="size-4"/> عرض وطباعة</a>
                </li>
            @endforeach
        </ul>
        @can('periods.prepare')
            <form method="POST" action="{{ route('finance.periods.reissue', $period) }}" class="flex flex-wrap items-center gap-2 border-t border-ink-100 p-4">
                @csrf
                <input name="reason" required maxlength="255" placeholder="سبب إصدار نسخةٍ جديدة" class="field-input min-w-64 flex-1" aria-label="السبب">
                <button class="btn-ghost">إصدار نسخةٍ بالتعديلات</button>
            </form>
        @endcan
    </section>

    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">تعديلاتٌ بعد الإقفال
            @if ($adjustments['entries'])<span class="text-xs font-normal text-ink-500">— أثرها على الصافي <span class="num">{{ number_format($adjustments['net']) }}</span></span>@endif</h2>
        <div class="overflow-x-auto">
            <table class="tbl text-sm">
                <thead><tr><th>القيد</th><th>كُتب بتاريخ</th><th>الحدث</th><th>البيان</th><th>المبلغ</th></tr></thead>
                <tbody>
                    @forelse ($adjustments['entries'] as $e)
                        <tr>
                            <td><a href="{{ route('finance.entries.show', $e['id']) }}" class="num text-[var(--brand)] hover:underline">{{ $e['number'] }}</a></td>
                            <td class="num">{{ $e['entry_date'] }}</td>
                            <td class="text-xs">{{ $e['event'] }}</td>
                            <td class="text-xs">{{ $e['description'] }}</td>
                            <td class="num">{{ number_format($e['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-ink-500">لم يُكتب شيءٌ يمسّ الفترة بعد إقفالها.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection

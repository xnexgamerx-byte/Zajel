@extends('layouts.app')
@section('title', 'إقفال الفترات')

@section('content')
@include('tenant.finance._tabs', [
    'title' => 'إقفال الفترات',
    'lead'  => 'الفترة المحاسبية للشركة كلّها: تُراجَع أرقامها وفحوصها، ويُعِدّ إقفالها المحاسب ويعتمده المخوَّل. بعد الإقفال لا يُكتب قيدٌ بتاريخها، وما يمسّها يُكتب في الفترة المفتوحة ويُصدَر به تقريرٌ جديد.',
])

<form method="GET" action="{{ route('finance.periods.review') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="field-input">
    </div>
    <button type="submit" class="btn-primary">مراجعة ما قبل الإقفال</button>
    <p class="w-full text-xs text-ink-500">الفترة التالية تبدأ بعد آخر فترةٍ بلا فجوة، وتنتهي قبل اليوم.</p>
</form>

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">الفترات</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الرقم</th><th>الفترة</th><th>الحال</th><th>صافي الربح</th><th>أعدّها</th><th>اعتمدها</th><th>نسخ التقرير</th></tr></thead>
            <tbody>
                @forelse ($periods as $p)
                    <tr>
                        <td><a href="{{ route('finance.periods.show', $p) }}" class="num font-semibold text-[var(--brand)] hover:underline">{{ $p->number }}</a></td>
                        <td class="num whitespace-nowrap">{{ $p->label() }}</td>
                        <td><span @class(['chip', 'chip-ok' => $p->isClosed(), 'chip-warn' => ! $p->isClosed()])>{{ $p->statusLabel() }}</span></td>
                        <td class="num font-semibold">{{ number_format($p->figures['net']['total'] ?? 0) }}</td>
                        <td class="text-xs">{{ $p->preparedBy?->name }} <span class="num text-ink-500">{{ $p->prepared_at?->format('Y-m-d') }}</span></td>
                        <td class="text-xs">{{ $p->approvedBy?->name ?? '—' }} <span class="num text-ink-500">{{ $p->approved_at?->format('Y-m-d') }}</span></td>
                        <td class="num">{{ $p->isClosed() ? $p->reports()->count() : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-ink-500">لم تُقفَل فترةٌ بعد. ابدأ بمراجعة الفترة المقترحة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@extends('layouts.app')
@section('title', 'أداء الموظّفين')

@section('content')
{{-- «أداء الموظّفين» (docs/plan/74): من أنجز ماذا في المدّة، وما هو بالضبط، والعدد أسبوعاً بأسبوع --}}
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">أداء الموظّفين</h1>
        <p class="mt-1 text-sm text-ink-500">ما حسمه كلّ موظّفٍ من تذاكر المبلغ، وما عالجه من المحاولات الفاشلة، وردوده على التجّار والمناديب — {{ $period->label() }}</p>
    </div>
    <a href="{{ route('reports.index', $period->query()) }}" class="btn-ghost">كل التقارير</a>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div class="flex flex-wrap gap-1.5">
        @foreach (\App\Http\Controllers\Tenant\StaffPerformanceController::RANGES as $key => $label)
            <a href="{{ route('reports.staff', ['range' => $key, 'by' => $by] + ($person ? ['user' => $person->id] : [])) }}"
               class="{{ $range === $key ? 'btn-primary' : 'btn-ghost' }} !px-3 !py-1.5 text-sm">{{ $label }}</a>
        @endforeach
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="by">العدد</label>
        <select id="by" name="by" class="field-input">
            @foreach (\App\Http\Controllers\Tenant\StaffPerformanceController::BUCKETS as $key => $label)
                <option value="{{ $key }}" @selected($by === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @if ($person)<input type="hidden" name="user" value="{{ $person->id }}">@endif
    <button type="submit" class="btn-primary">طبّق</button>
</form>

<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
    @foreach ([
        ['تذاكر فُتحت', $totals->opened, 'text-ink-900'],
        ['اعتُمدت', $totals->approved, 'text-ok-700'],
        ['رُفضت', $totals->rejected, 'text-bad-700'],
        ['معالجات', $totals->processed, 'text-warn-700'],
        ['ردود على التجّار', $totals->replies, 'text-info-700'],
        ['رسائل للمناديب', $totals->courier, 'text-ink-900'],
    ] as [$label, $value, $color])
        <div class="card p-4">
            <div class="text-xs font-medium text-ink-500">{{ $label }}</div>
            <div class="num mt-1 text-2xl font-bold {{ $color }}">{{ number_format($value) }}</div>
        </div>
    @endforeach
</div>

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">الموظّفون — الأكثر عملاً أوّلاً</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الموظّف</th>
                    <th>اعتمد</th>
                    <th>رفض</th>
                    <th>يحسم التذكرة بعد</th>
                    <th>عالج</th>
                    @foreach ($actions as $label)<th>{{ $label }}</th>@endforeach
                    <th>ردّ على التجّار</th>
                    <th>كتب للمناديب</th>
                    <th>المجموع</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $row)
                    <tr @class(['bg-ink-50' => $person?->id === $row->id])>
                        <td>
                            <div class="font-medium">{{ $row->name }}</div>
                            <div class="text-xs text-ink-500">{{ $row->role }}</div>
                        </td>
                        <td class="num text-ok-700">{{ number_format($row->approved) }}</td>
                        <td class="num text-bad-700">{{ number_format($row->rejected) }}</td>
                        <td class="text-sm">{{ $row->approved + $row->rejected > 0 ? \App\Support\Arabic::duration($row->ticket_minutes) : '—' }}</td>
                        <td class="num font-semibold">{{ number_format($row->processed) }}</td>
                        @foreach (array_keys($actions) as $action)
                            <td class="num">{{ number_format($row->actions[$action] ?? 0) }}</td>
                        @endforeach
                        <td class="num">{{ number_format($row->replies) }}@if ($row->threads)<span class="text-xs text-ink-500"> · {{ number_format($row->threads) }} محادثة</span>@endif</td>
                        <td class="num">{{ number_format($row->courier) }}</td>
                        <td class="num font-bold">{{ number_format($row->total) }}</td>
                        <td>
                            <a href="{{ route('reports.staff', $period->query() + ['by' => $by, 'user' => $row->id]) }}"
                               class="text-sm font-semibold text-brand hover:underline">ماذا عالج</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($actions) + 9 }}" class="px-4 py-12 text-center text-ink-500">لا عمل مسجّل في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if ($person)
    <section class="card mb-5 overflow-hidden" id="log">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-5 py-4">
            <h2 class="text-sm font-bold">ما عالجه {{ $person->name }} بالضبط — الأحدث أوّلاً</h2>
            <a href="{{ route('reports.staff', $period->query() + ['by' => $by]) }}" class="text-sm text-ink-500 hover:underline">إغلاق</a>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>متى</th><th>ماذا</th><th>الشحنة</th><th>التفاصيل</th></tr></thead>
                <tbody>
                    @forelse ($log as $item)
                        <tr>
                            <td class="num whitespace-nowrap text-sm">{{ $item->at?->format('Y-m-d H:i') }}</td>
                            <td class="whitespace-nowrap">
                                <span class="chip {{ match ($item->kind) { 'ticket' => $item->good ? 'chip-ok' : 'chip-bad', 'processing' => 'chip-warn', 'reply' => 'chip-info', default => 'chip-mute' } }}">{{ $item->what }}</span>
                                @if ($item->ref)<div class="mt-1 text-xs text-ink-500">{{ $item->ref }}</div>@endif
                            </td>
                            <td class="num">
                                @if ($item->shipment)
                                    <a href="{{ route('shipments.show', $item->shipment) }}" class="font-semibold text-brand hover:underline">{{ $item->shipment->number }}</a>
                                @else — @endif
                            </td>
                            <td class="text-sm">{{ $item->detail }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-12 text-center text-ink-500">لم يعمل شيئاً في هذه المدّة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">العدد {{ \App\Http\Controllers\Tenant\StaffPerformanceController::BUCKETS[$by] }} — الأحدث أوّلاً</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>{{ ['week' => 'الأسبوع', 'month' => 'الشهر', 'year' => 'السنة'][$by] }}</th><th>تذاكر فُتحت</th><th>اعتُمدت</th><th>رُفضت</th><th>معالجات</th><th>ردود على التجّار</th></tr></thead>
            <tbody>
                @forelse ($trend as $row)
                    <tr>
                        <td class="num whitespace-nowrap font-medium">{{ $row->label }}</td>
                        <td class="num font-semibold">{{ number_format($row->opened) }}</td>
                        <td class="num text-ok-700">{{ number_format($row->approved) }}</td>
                        <td class="num text-bad-700">{{ number_format($row->rejected) }}</td>
                        <td class="num text-warn-700">{{ number_format($row->processed) }}</td>
                        <td class="num text-info-700">{{ number_format($row->replies) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-ink-500">لا شيء في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@extends('layouts.app')
@section('title', 'تتبّع التغييرات')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">تتبّع التغييرات</h1>
        <p class="mt-1 text-sm text-ink-500">مَن غيّر ماذا ومتى — سجلٌّ لا يُعدَّل ولا يُحذف منه شيء.</p>
    </div>
    <a href="{{ route('reports.index', $period->query()) }}" class="btn-ghost">كل التقارير</a>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="number">رقم الوصل</label>
        <input id="number" name="number" type="text" class="field-input num w-40"
               placeholder="أو الباركود" value="{{ $number }}">
    </div>
    <div>
        <label class="field-label" for="type">نوع الحدث</label>
        <select id="type" name="type" class="field-input">
            <option value="">الكل</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="actor">مَن فعلها</label>
        <select id="actor" name="actor" class="field-input">
            <option value="humans" @selected($actor === 'humans')>أشخاص (دون النظام)</option>
            <option value="all" @selected($actor === 'all')>الجميع</option>
            @foreach ($actors as $value => $label)
                <option value="{{ $value }}" @selected($actor === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-primary">طبّق</button>
    @if ($number !== '' || request('type') || $actor !== 'humans')
        <a href="{{ route('reports.changes', $period->query()) }}" class="btn-ghost">إلغاء الفرز</a>
    @endif
</form>

@if ($actor === 'humans')
    <p class="mb-4 text-xs text-ink-500">
        {{-- إخفاءٌ مُعلَن لا صامت: التسوية الواحدة تكتب عشرات الآلاف من الأحداث --}}
        أحداث النظام (التسويات وما يُشتقّ منها) مخفيّة — اختر «الجميع» لإظهارها.
    </p>
@endif

@if ($events->isEmpty())
    <section class="card p-10 text-center">
        <p class="text-ink-500">لا تغييرات مطابقة في هذه المدّة.</p>
    </section>
@else
    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الوقت</th>
                        <th>الشحنة</th>
                        <th>التاجر</th>
                        <th>التغيير</th>
                        <th>مَن</th>
                        <th>التفصيل</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr>
                            <td class="num whitespace-nowrap text-xs text-ink-600">
                                {{ $event->created_at->format('Y-m-d') }}
                                <span class="block text-ink-400">{{ $event->created_at->format('H:i') }}</span>
                            </td>
                            <td>
                                @if ($event->shipment)
                                    <a href="{{ route('shipments.show', $event->shipment_id) }}"
                                       class="num font-medium hover:underline">{{ $event->shipment->number }}</a>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td class="max-w-40 truncate text-ink-600">{{ $event->shipment?->merchant?->business_name ?? '—' }}</td>
                            <td>
                                @php
                                    $from = $event->from_status ? \App\Enums\ShipmentStatus::tryFrom($event->from_status) : null;
                                    $to   = \App\Enums\ShipmentStatus::tryFrom($event->to_status);
                                @endphp
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($from && $to && $from !== $to)
                                        <span class="whitespace-nowrap text-xs text-ink-400">{{ $from->label() }}</span>
                                        {{-- السهم يسار في صفحة يمينية: من القديم إلى الجديد --}}
                                        <span class="text-ink-300" aria-hidden="true">←</span>
                                    @endif
                                    @if ($to)
                                        <x-status-badge :status="$to" />
                                    @endif
                                    @if (! $to || $event->event_type !== 'status_change')
                                        <span class="chip chip-mute">{{ $event->typeLabel() }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="block text-sm font-medium">{{ $event->actor_name ?? '—' }}</span>
                                <span class="text-xs text-ink-500">{{ $event->actorLabel() }}</span>
                            </td>
                            <td class="max-w-56 text-xs text-ink-600">
                                @if ($event->amount !== null)
                                    <span class="num block font-semibold">{{ number_format($event->amount) }}</span>
                                @endif
                                @if ($event->note)
                                    <span class="block truncate" title="{{ $event->note }}">{{ $event->note }}</span>
                                @endif
                                @if ($event->amount === null && ! $event->note)
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $events->links() }}</div>
@endif
@endsection

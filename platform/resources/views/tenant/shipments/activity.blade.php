@extends('layouts.app')
@section('title', 'حركات الطلب — '.$shipment->number)

@section('content')
{{-- «حركات الطلب» (docs/plan/53): من لمس الشحنة، ومتى، وماذا غيّر --}}
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">حركات الطلب <span class="num text-ink-500">{{ $shipment->number }}</span></h1>
        <p class="mt-1 text-sm text-ink-500">كلّ ما فعله موظّفٌ أو مندوبٌ أو التاجر بهذه الشحنة: التعديل بحقوله، والحالة، والإجبار، والمال — الأحدث أوّلاً.</p>
    </div>
    <div class="flex items-center gap-2">
        <x-status-badge :status="$shipment->status" :shipment="$shipment" />
        <a href="{{ route('shipments.show', $shipment) }}" class="btn-ghost">رجوع للشحنة</a>
    </div>
</div>

@if ($people->isNotEmpty())
    <section class="card mb-5 p-5">
        <h2 class="mb-3 text-sm font-bold">من عمل عليها</h2>
        <div class="flex flex-wrap gap-2" data-people>
            @foreach ($people as $name => $person)
                <span class="chip chip-mute">
                    <span class="font-semibold">{{ $name ?: 'موظّف' }}</span>
                    · {{ $person->count }} {{ $person->count === 1 ? 'حركة' : 'حركات' }}
                    @if ($person->edits) · <span class="text-warn-700">{{ $person->edits }} تعديل</span> @endif
                </span>
            @endforeach
        </div>
    </section>
@endif

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>الوقت</th><th>من</th><th>الحركة</th><th>التفاصيل</th><th>الجهاز</th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($events as $event)
                    @php $rows = $changes($event); @endphp
                    <tr @class(['bg-warn-50/40' => in_array($event->event_type, ['edited', 'forced_status'], true)])>
                        <td class="num whitespace-nowrap text-xs" dir="ltr">{{ $event->created_at->format('Y-m-d H:i') }}</td>
                        <td class="whitespace-nowrap">
                            <div class="font-semibold">{{ $event->actor_name ?? 'النظام' }}</div>
                            <div class="text-xs text-ink-500">{{ $event->actorLabel() }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <div class="font-medium">{{ $event->typeLabel() }}</div>
                            @if (in_array($event->event_type, ['status_change', 'forced_status'], true))
                                <div class="text-xs text-ink-500">
                                    {{ \App\Enums\ShipmentStatus::tryFrom((string) $event->from_status)?->label() ?? '—' }}
                                    ← {{ $event->toLabel() }}
                                </div>
                            @endif
                        </td>
                        <td class="text-sm">
                            @if ($rows)
                                <ul class="space-y-0.5" data-changes>
                                    @foreach ($rows as $row)
                                        <li><span class="text-ink-500">{{ $row['field'] }}:</span>
                                            <span class="line-through decoration-bad-700/50">{{ $row['from'] }}</span>
                                            ← <span class="font-semibold">{{ $row['to'] }}</span></li>
                                    @endforeach
                                </ul>
                                @if (! empty($event->meta['override']))<p class="mt-1 text-xs text-warn-700">بصلاحية تعديل الأجور</p>@endif
                            @elseif ($event->note)
                                <span class="text-ink-700">{{ $event->note }}</span>
                            @endif
                            @if ($event->amount !== null)
                                <div class="num text-xs text-ok-700" dir="ltr">{{ number_format($event->amount) }} د.ع</div>
                            @endif
                            @if ($event->courier)<div class="text-xs text-ink-500">المندوب: {{ $event->courier->name }}</div>@endif
                        </td>
                        <td class="num text-xs text-ink-500" dir="ltr">{{ $event->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-ink-500">لا حركات بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

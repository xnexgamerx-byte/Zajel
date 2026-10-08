@extends('layouts.app')
@section('title', 'كشوف المناديب')

@section('content')
@include('tenant.courier_manifests._tabs', ['tab' => 'history'])

@php
    // ألوان الخانات: الواصل أخضر، والراجع رماديّ، وما زال عند المندوب كهرمانيّ أو أزرق
    $tones = [
        'delivered' => 'chip-ok', 'returned' => 'chip-mute', 'return_in_store' => 'chip-mute',
        'out' => 'chip-info', 'redelivery' => 'chip-info', 'postponed' => 'chip-warn',
        'to_process' => 'chip-warn', 'return_with_him' => 'chip-warn', 'other' => 'chip-mute',
    ];
    $final = ['returned', 'return_in_store'];
    $withHim = ['out', 'redelivery', 'postponed', 'to_process', 'return_with_him'];
@endphp

<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الكشف</th>
                    <th>التاريخ</th>
                    <th>المندوب</th>
                    <th class="num">الشحنات</th>
                    <th>تمّ التسليم</th>
                    <th>راجع نهائي</th>
                    <th>عند المندوب</th>
                    <th>المنجز</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($manifests as $manifest)
                    <tr>
                        <td class="num text-xs font-semibold whitespace-nowrap">{{ $manifest->code }}</td>
                        <td class="num text-sm whitespace-nowrap">{{ $manifest->day->format('Y-m-d') }}</td>
                        <td>
                            <span class="font-medium">{{ $manifest->courier?->name ?? '—' }}</span>
                            @if ($manifest->courier?->deleted_at)
                                <span class="chip chip-bad mt-0.5 block w-fit">محذوف</span>
                            @endif
                        </td>
                        <td class="num font-semibold">{{ number_format($manifest->shipments) }}</td>
                        <td>
                            @if ($manifest->counts['delivered'] ?? 0)
                                <span class="chip chip-ok">واصل {{ $manifest->counts['delivered'] }}</span>
                            @else
                                <span class="text-ink-400">—</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($final as $key)
                                    @if ($manifest->counts[$key] ?? 0)
                                        <span class="chip {{ $tones[$key] }}">{{ $buckets[$key] }} {{ $manifest->counts[$key] }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($withHim as $key)
                                    @if ($manifest->counts[$key] ?? 0)
                                        <span class="chip {{ $tones[$key] }}">{{ $buckets[$key] }} {{ $manifest->counts[$key] }}</span>
                                    @endif
                                @endforeach
                                @if ($manifest->counts['other'] ?? 0)
                                    <span class="chip chip-mute" title="أُعيدت للمخزن أو انتقلت لمندوبٍ آخر">أخرى {{ $manifest->counts['other'] }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="min-w-28">
                            <div class="flex items-center gap-2">
                                <div class="h-2 flex-1 rounded-full bg-ink-100">
                                    <div class="h-full rounded-full {{ $manifest->percent === 100 ? 'bg-ok-700' : 'bg-primary-600' }}" style="width: {{ $manifest->percent }}%"></div>
                                </div>
                                <span class="num text-xs font-semibold">{{ $manifest->percent }}٪</span>
                            </div>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('courier-manifests.day', [$manifest->courier_id, $manifest->day->toDateString()]) }}" class="btn-ghost py-1 whitespace-nowrap">
                                افتح الكشف
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-14 text-center text-ink-500">
                            {{ array_filter($filters) ? 'لا كشف بهذا البحث.' : 'لا كشوف بعد: تظهر هنا حين تُسنَد شحنات لمندوب.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($manifests->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $manifests->links() }}</div>
    @endif
</section>
@endsection

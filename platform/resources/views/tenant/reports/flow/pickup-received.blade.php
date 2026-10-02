@extends('layouts.app')
@section('title', 'المستلمة من مندوب الاستلام')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'المستلمة من مندوب الاستلام', 'blurb' => 'كم جمع كل مندوب استلامٍ من التجّار في المدّة، وكم استلمناه منه، وكم ما زال بيده لم يصل مخزننا.'])

<x-report-period :period="$period">
    <div class="min-w-48">
        <label class="field-label" for="courier_id">مندوب الاستلام</label>
        <select id="courier_id" name="courier_id" class="field-input">
            <option value="">الكل</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <p class="ms-auto max-w-80 text-xs text-ink-500">بتاريخ استلام الطرد من التاجر.</p>
</x-report-period>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="stat">
        <div class="stat-label">جمعوها من التجّار</div>
        <div class="stat-value num">{{ number_format($totals->total) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">استلمناها منهم</div>
        <div class="stat-value num">{{ number_format($totals->received) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">ما زالت بأيديهم</div>
        <div class="stat-value num {{ $totals->remaining ? 'text-warn-700' : '' }}">{{ number_format($totals->remaining) }}</div>
        <div class="mt-1 text-xs text-ink-500">مبلغها <span class="num">{{ number_format($totals->remaining_amount) }}</span></div>
    </div>
    <div class="stat">
        <div class="stat-label">أُلغيت بعد استلامها</div>
        <div class="stat-value num">{{ number_format($totals->cancelled) }}</div>
    </div>
</div>

<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>مندوب الاستلام</th>
                    <th>جمع</th>
                    <th>استلمناه</th>
                    <th>ما زال بيده</th>
                    <th>مبلغ ما بيده</th>
                    <th>أقدم ما بيده</th>
                    <th>أُلغي</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="font-medium">{{ $row->name }}</td>
                        <td class="num">{{ number_format($row->total) }}</td>
                        <td class="num text-ok-700">{{ number_format($row->received) }}</td>
                        <td class="num">
                            @if ($row->remaining && auth()->user()->can('shipments.view'))
                                {{-- القائمة تعرض كل ما بيده الآن، لا ما جمعه في المدّة وحدها --}}
                                <a href="{{ route('shipments.index', ['status' => 'picked_up', 'pickup_courier_id' => $row->courier_id]) }}"
                                   class="font-semibold text-warn-700 hover:underline" title="كل ما بيده الآن">{{ number_format($row->remaining) }}</a>
                            @else
                                <span class="{{ $row->remaining ? 'font-semibold text-warn-700' : 'text-ink-400' }}">{{ number_format($row->remaining) }}</span>
                            @endif
                        </td>
                        <td class="num">{{ number_format($row->remaining_amount) }}</td>
                        <td class="num text-xs whitespace-nowrap text-ink-500">
                            @if ($row->oldest)
                                {{ $row->oldest->format('Y-m-d H:i') }}
                                <span class="block">منذ {{ \App\Support\Arabic::days((int) $row->oldest->diffInDays(now())) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="num text-ink-600">{{ number_format($row->cancelled) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-ink-500">لم يجمع مندوب استلامٍ شيئاً في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
            @if ($rows->count() > 1)
                <tfoot>
                    <tr>
                        <td>المجموع</td>
                        <td class="num">{{ number_format($totals->total) }}</td>
                        <td class="num">{{ number_format($totals->received) }}</td>
                        <td class="num">{{ number_format($totals->remaining) }}</td>
                        <td class="num">{{ number_format($totals->remaining_amount) }}</td>
                        <td></td>
                        <td class="num">{{ number_format($totals->cancelled) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
@endsection

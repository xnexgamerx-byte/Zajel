@extends('layouts.app')
@section('title', 'واصل إجباري')

@section('content')
<x-report-shell title="واصل إجباري"
                question="ما خرج عن مسار الحالات، بمن أمر به وسببه."
                :period="$period"
                basis="تُحسب بتاريخ التغيير القسريّ داخل المدّة.">

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3">
        <div class="stat">
            <div class="stat-label">تغييرات قسريّة</div>
            <div class="num mt-1 text-2xl font-bold {{ $shipments->total() ? 'text-warn-700' : 'text-ink-400' }}">
                {{ number_format($shipments->total()) }}
            </div>
        </div>
        <div class="stat">
            <div class="stat-label">مبالغها</div>
            <div class="num mt-1 text-2xl font-bold">{{ number_format($byUser->sum('cod')) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">أكثر من أمر بها</div>
            <div class="mt-1 truncate text-lg font-bold">{{ $byUser->first()->name ?? '—' }}</div>
            <div class="num text-xs text-ink-500">{{ number_format($byUser->first()->total ?? 0) }}</div>
        </div>
    </div>

    @if ($shipments->isEmpty())
        <section class="card p-10 text-center">
            <p class="text-ink-500">لا تغييرات قسريّة في هذه المدّة — وهذا هو المطلوب.</p>
        </section>
    @else
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <section class="card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="tbl">
                            <thead>
                                <tr><th>الوصل</th><th>التاجر</th><th>الحالة</th><th>المبلغ</th><th>السبب</th><th>متى</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($shipments as $shipment)
                                    <tr>
                                        <td>
                                            <a href="{{ route('shipments.show', $shipment) }}"
                                               class="num font-semibold hover:underline">{{ $shipment->number }}</a>
                                        </td>
                                        <td class="max-w-40 truncate text-ink-600">{{ $shipment->merchant?->business_name }}</td>
                                        <td><x-status-badge :status="$shipment->status" /></td>
                                        <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                                        <td class="max-w-56 truncate text-warn-700">{{ $shipment->forced_reason }}</td>
                                        <td class="whitespace-nowrap text-xs text-ink-500">
                                            {{ $shipment->status_changed_at?->format('Y-m-d H:i') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($shipments->hasPages())
                        <div class="border-t border-ink-100 px-5 py-4">{{ $shipments->links() }}</div>
                    @endif
                </section>
            </div>

            <section class="card h-fit p-5">
                <h2 class="card-title">مَن أمر بها</h2>
                <p class="card-hint mb-4">القائمة نفسها هي الرادع.</p>
                <div class="space-y-3">
                    @foreach ($byUser as $row)
                        <x-bar-row :label="$row->name" :value="$row->total" :max="$byUser->max('total')"
                                   :sub="number_format($row->cod).' د.ع'" />
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</x-report-shell>
@endsection

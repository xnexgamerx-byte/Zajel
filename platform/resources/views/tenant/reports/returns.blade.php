@extends('layouts.app')
@section('title', 'لماذا ترجع شحناتي؟')

@section('content')
<x-report-shell title="لماذا ترجع شحناتي؟"
                question="السؤال الذي يُصلح البيانات بدل أن يتّهم المندوب."
                :period="$period"
                basis="تُحسب بتاريخ آخر تغيّر في حالة الشحنة داخل المدّة.">

    @php
        $totalFailures = $reasons->sum('total');
        $categories = ['customer' => 'الزبون', 'address' => 'العنوان',
                       'merchandise' => 'البضاعة', 'merchant' => 'التاجر', 'agent' => 'المندوب'];
    @endphp

    @if ($totalFailures === 0)
        <section class="card p-10 text-center">
            <p class="text-ink-500">لا محاولات فاشلة في هذه المدّة.</p>
        </section>
    @else
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="stat">
                <div class="stat-label">محاولات فاشلة</div>
                <div class="num mt-1 text-2xl font-bold">{{ number_format($totalFailures) }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">انتهت راجعة</div>
                <div class="num mt-1 text-2xl font-bold text-warn-700">{{ number_format($reasons->sum('returned')) }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">أكثر سبب</div>
                <div class="mt-1 truncate text-lg font-bold">{{ $reasons->first()->name ?? '—' }}</div>
                <div class="num text-xs text-ink-500">{{ number_format($reasons->first()->total ?? 0) }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">أكثر باب</div>
                <div class="mt-1 truncate text-lg font-bold">
                    {{ $categories[$byCategory->first()->category ?? ''] ?? '—' }}
                </div>
                <div class="num text-xs text-ink-500">{{ number_format($byCategory->first()->total ?? 0) }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <section class="card overflow-hidden">
                    <h2 class="card-title border-b border-ink-100 px-5 py-4">الأسباب مرتّبة</h2>
                    <div class="overflow-x-auto">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>السبب</th>
                                    <th>الباب</th>
                                    <th>المحاولات</th>
                                    <th>انتهت راجعة</th>
                                    <th>من الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($reasons as $reason)
                                    <tr>
                                        <td class="font-medium">{{ $reason->name }}</td>
                                        <td class="text-ink-600">{{ $categories[$reason->category] ?? $reason->category }}</td>
                                        <td class="num">{{ number_format($reason->total) }}</td>
                                        <td class="num text-warn-700">{{ number_format($reason->returned) }}</td>
                                        <td class="num text-ink-600">
                                            {{ round($reason->total / $totalFailures * 100) }}%
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="space-y-5">
                <section class="card p-5">
                    <h2 class="card-title">أين الخلل</h2>
                    <p class="card-hint mb-4">التصنيف هو ما يُحوّل القائمة إلى قرار.</p>
                    <div class="space-y-3">
                        @foreach ($byCategory as $row)
                            <x-bar-row :label="$categories[$row->category] ?? $row->category"
                                       :value="$row->total" :max="$byCategory->max('total')"
                                       :sub="round($row->total / $totalFailures * 100).'%'" />
                        @endforeach
                    </div>
                </section>

                @if ($byMerchant->isNotEmpty())
                    <section class="card p-5">
                        <h2 class="card-title">أكثر التجّار رواجعَ</h2>
                        <p class="card-hint mb-4">مَن تتكرّر رواجعه يحتاج مكالمة لا تقريراً.</p>
                        <div class="space-y-3">
                            @foreach ($byMerchant as $row)
                                <x-bar-row :label="$row->name" :value="$row->total"
                                           :max="$byMerchant->max('total')" />
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        </div>
    @endif
</x-report-shell>
@endsection

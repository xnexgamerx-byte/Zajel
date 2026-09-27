@extends('layouts.app')
@section('title', 'المتابعة والمراجعة')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'المتابعة والمراجعة', 'blurb' => '«موظّفو المتابعة» و«أداء المراجعة» و«عولجت من العميل أو الموظّف»: من قرّر في المحاولات الفاشلة وبعد كم انتظرت، ومن أجاز المعلَّق للمراجعة. '.$period->label()])

<x-report-period :period="$period" />

<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">عولجت من الموظّفين</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($bySource['staff'] ?? 0) }}</div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">عولجت من التجّار</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($bySource['merchant'] ?? 0) }}</div>
    </div>
    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">أُجيزت بعد المراجعة</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($reviews->sum('total')) }}</div>
    </div>
</div>

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">المعالجات — بمن اتّخذها</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>من</th><th>النوع</th><th>المعالجات</th>
                    @foreach ($actions as $label)<th>{{ $label }}</th>@endforeach
                    <th>متوسّط الانتظار قبلها</th></tr>
            </thead>
            <tbody>
                @forelse ($people as $person)
                    <tr>
                        <td class="font-medium">{{ $person->name }}</td>
                        <td><span class="chip {{ $person->by === 'merchant' ? 'chip-info' : 'chip-mute' }}">{{ $person->by === 'merchant' ? 'تاجر' : 'موظّف' }}</span></td>
                        <td class="num font-semibold">{{ number_format($person->total) }}</td>
                        @foreach (array_keys($actions) as $action)
                            <td class="num">{{ number_format($person->actions[$action] ?? 0) }}</td>
                        @endforeach
                        <td class="text-sm">{{ \App\Support\Arabic::duration($person->avg_wait) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($actions) + 4 }}" class="px-4 py-12 text-center text-ink-500">لا معالجات في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">المراجعة — من أجاز شحنات المعلّقين للتدقيق</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>المدقّق</th><th>أجاز</th><th>متوسّط المدّة المستغرقة</th></tr></thead>
            <tbody>
                @forelse ($reviews as $review)
                    <tr>
                        <td class="font-medium">{{ $names[$review->user_id] ?? '—' }}</td>
                        <td class="num font-semibold">{{ number_format($review->total) }}</td>
                        <td class="text-sm">{{ \App\Support\Arabic::duration($review->avg_wait) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-12 text-center text-ink-500">لم يُجَز شيءٌ بعد مراجعةٍ في هذه المدّة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

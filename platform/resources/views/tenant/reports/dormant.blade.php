@extends('layouts.app')
@section('title', 'عملاء منقطعون')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">عملاء منقطعون</h1>
        <p class="mt-1 text-sm text-ink-500">تجّار نشطون لم يُرسلوا شحنة واحدة منذ {{ \App\Support\Arabic::days($days) }}.</p>
    </div>
    <a href="{{ route('reports.index') }}" class="btn-ghost">كل التقارير</a>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="days">مدّة الانقطاع (أيام)</label>
        <input id="days" name="days" type="number" min="7" max="365" step="1"
               class="field-input w-32" value="{{ $days }}">
    </div>
    <button type="submit" class="btn-primary">طبّق</button>
    <div class="ms-auto flex gap-2">
        @foreach ([15, 30, 60, 90] as $preset)
            <a href="{{ route('reports.dormant', ['days' => $preset]) }}"
               class="chip {{ $days === $preset ? 'chip-info' : 'chip-mute' }}">{{ $preset }}</a>
        @endforeach
    </div>
</form>

@if ($rows->isEmpty())
    <section class="card p-10 text-center">
        <p class="font-medium text-ok-700">لا تاجر منقطعاً.</p>
        <p class="mt-1 text-sm text-ink-500">كل تاجر نشط أرسل شحنة خلال المدّة.</p>
    </section>
@else
    @php
        $lapsed = $rows->where('total', '>', 0);
        $never  = $rows->where('total', 0);
    @endphp

    <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-3">
        <div class="stat">
            <span class="stat-label">منقطعون</span>
            <span class="stat-value num">{{ number_format($rows->count()) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">توقّفوا بعد نشاط</span>
            <span class="stat-value num text-warn-700">{{ number_format($lapsed->count()) }}</span>
        </div>
        <div class="stat">
            <span class="stat-label">لم يبدأوا أصلاً</span>
            <span class="stat-value num text-ink-500">{{ number_format($never->count()) }}</span>
        </div>
    </div>

    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>التاجر</th>
                        <th>الهاتف</th>
                        <th>آخر شحنة</th>
                        <th>منذ</th>
                        <th>شحناته كلّها</th>
                        <th>رصيده</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $silent = $row->last_at ? (int) $row->last_at->diffInDays(now()) : null; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('merchants.show', $row->id) }}" class="font-medium hover:underline">{{ $row->name }}</a>
                                <span class="num block text-xs text-ink-500">{{ $row->code }}</span>
                            </td>
                            <td class="num text-ink-600">{{ $row->phone }}</td>
                            <td class="num text-ink-600">
                                {{ $row->last_at?->format('Y-m-d') ?? '—' }}
                            </td>
                            <td>
                                @if ($silent === null)
                                    <span class="chip chip-mute">لم يُرسل قطّ</span>
                                @else
                                    <span class="chip {{ $silent >= 60 ? 'chip-bad' : 'chip-warn' }}">{{ \App\Support\Arabic::days($silent) }}</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($row->total) }}</td>
                            <td class="num {{ $row->balance < 0 ? 'text-bad-700' : 'text-ink-600' }}">{{ number_format($row->balance) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <p class="mt-3 text-xs text-ink-500">
        مرتّبون بمَن توقّف بعد نشاط أولاً ثم بالأقدم صمتاً — وهذا ترتيب الاتصال لا ترتيب العرض.
    </p>
@endif
@endsection

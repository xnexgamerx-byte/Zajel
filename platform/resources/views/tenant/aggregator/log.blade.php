@extends('layouts.app')
@section('title', 'سجلّ ربط الوسيط')

{{-- ما ينتظر الإرسال أو فشل (مع سببه وإعادته)، وكل تحديثٍ وصلنا منهم وما فُعل به (docs/plan/104) --}}
@section('content')
<div class="mb-5">
    <a href="{{ route('aggregator.edit') }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ ربط الشركة الوسيطة</a>
    <h1 class="page-title mt-2">سجلّ الربط</h1>
</div>

<section class="card mb-5 p-5">
    <h2 class="card-title">ما لم يُرسل بعد</h2>
    @if ($jobs->isEmpty())
        <p class="mt-3 text-sm text-ink-500">لا شيء ينتظر: كل ما سُلِّم للوسيط وصلهم.</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm [&_td]:px-2 [&_th]:px-2">
                <thead><tr class="text-start text-xs text-ink-500">
                    <th class="py-2 text-start">الشحنة</th><th class="text-start">العمل</th><th class="text-start">الحال</th><th class="text-start">السبب</th><th></th>
                </tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($jobs as $job)
                        <tr>
                            <td class="py-2"><a href="{{ route('shipments.show', $job->shipment_id) }}" class="num font-semibold hover:underline">{{ $job->shipment?->number }}</a></td>
                            <td>{{ $job->label() }}</td>
                            <td>
                                <span class="chip {{ $job->status === 'failed' ? 'chip-bad' : 'chip-warn' }}">{{ $job->status === 'failed' ? 'فشل' : 'ينتظر' }}</span>
                                <span class="text-xs text-ink-500">{{ \App\Support\Arabic::attempts($job->attempts) }}</span>
                            </td>
                            <td class="max-w-md text-xs text-bad-700">{{ $job->last_error }}</td>
                            <td>
                                <form method="POST" action="{{ route('aggregator.jobs.retry', $job) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost py-1">أعد الآن</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="card-title">ما وصلنا منهم</h2>
        <div class="flex flex-wrap gap-1 text-xs">
            <a href="{{ route('aggregator.log') }}" class="chip {{ request('result') ? 'chip-mute' : 'chip-info' }}">الكل</a>
            @foreach (\App\Models\AggregatorEvent::RESULTS as $key => $label)
                <a href="{{ route('aggregator.log', ['result' => $key]) }}" class="chip {{ request('result') === $key ? 'chip-info' : 'chip-mute' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>
    @if ($events->isEmpty())
        <p class="mt-3 text-sm text-ink-500">لم يصل شيءٌ بعد.</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm [&_td]:px-2 [&_th]:px-2">
                <thead><tr class="text-xs text-ink-500">
                    <th class="py-2 text-start">الوقت</th><th class="text-start">الشحنة</th><th class="text-start">رقمهم</th><th class="text-start">حالتهم</th><th class="text-start">ما فُعل</th>
                </tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($events as $event)
                        <tr>
                            <td class="num py-2 text-xs text-ink-500" dir="ltr">{{ $event->created_at?->format('m-d H:i') }}</td>
                            <td>@if ($event->shipment)<a href="{{ route('shipments.show', $event->shipment_id) }}" class="num font-semibold hover:underline">{{ $event->shipment->number }}</a>@else<span class="num text-ink-500">{{ $event->company_order_id ?? '—' }}</span>@endif</td>
                            <td class="num text-xs">{{ $event->remote_order_id }}</td>
                            <td>{{ $event->status_text }} @if ($event->deliver_done)<span class="chip chip-ok">سُلّمت</span>@endif
                                @if ($event->old_price !== null && $event->old_price !== -1)<span class="text-xs text-warn-700">السعر: <span class="num">{{ number_format($event->old_price) }}</span> ← <span class="num">{{ number_format($event->price) }}</span></span>@endif</td>
                            <td>
                                <span class="chip {{ ['applied' => 'chip-ok', 'recorded' => 'chip-mute', 'waiting' => 'chip-warn', 'unmatched' => 'chip-warn', 'error' => 'chip-bad'][$event->result] ?? 'chip-mute' }}">{{ \App\Models\AggregatorEvent::RESULTS[$event->result] ?? $event->result }}</span>
                                <span class="text-xs text-ink-500">{{ $event->message }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $events->links() }}</div>
    @endif
</section>
@endsection

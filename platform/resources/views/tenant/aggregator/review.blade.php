@extends('layouts.app')
@section('title', 'مراجعة مسح الوسيط')

{{--
  «مراجعة مسح الوسيط» (docs/plan/121): كلّ شحنةٍ مفتوحةٍ مع المندوب الوسيط، وأيقرأ مندوبهم طردها بالمسح.
  نظامهم يجد الطلب برقمه عندهم وحده (البادئة ثمّ رقم الوصل): فلا يُقرأ ما لم يُنشأ عندهم، ولا طردٌ باركوده غير ذلك.
--}}
@section('content')
@php
    $problems = \App\Services\Aggregator\AggregatorScanReview::PROBLEMS;
    $tone = ['not_sent' => 'chip-bad', 'failed' => 'chip-bad', 'missing' => 'chip-bad', 'pending' => 'chip-warn',
             'paper' => 'chip-warn', 'old_label' => 'chip-warn', 'no_label' => 'chip-warn'];
@endphp

<div class="mb-5">
    <a href="{{ route('aggregator.edit') }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ ربط الشركة الوسيطة</a>
    <h1 class="page-title mt-2">مراجعة مسح الوسيط</h1>
    <p class="mt-1 max-w-3xl text-sm text-ink-500">
        نظامهم يجد الطلب برقمه عندهم: <span class="num font-semibold" dir="ltr">{{ $link->order_prefix }}</span> ثمّ رقم الوصل.
        فلا يقرأ مندوبهم طرداً لم يُنشأ طلبه عندهم، ولا طرداً باركوده غير هذا الرقم — كورقة دفتر الوصولات، أو وصلٍ طُبع قبل البادئة.
    </p>
</div>

@unless ($link->courier_id)
    <div class="alert mb-5 bg-bad-50 text-bad-700">لم يُختر المندوب الوسيط في إعداد الربط: لا شحنات تُراجَع.</div>
@endunless

<div class="mb-5 flex flex-wrap gap-2 text-sm">
    <span class="chip chip-mute">مع الوسيط <span class="num">{{ $total }}</span></span>
    <span class="chip chip-ok">يُقرأ <span class="num">{{ $total - $bad }}</span></span>
    <span class="chip {{ $bad ? 'chip-bad' : 'chip-ok' }}">لا يُقرأ <span class="num">{{ $bad }}</span></span>
    @if ($verifiedCount)
        <span class="chip chip-info">سُئل نظامهم عن <span class="num">{{ $verifiedCount }}</span></span>
    @endif
</div>

{{-- ما يُصلحها --}}
<div class="mb-5 grid gap-4 lg:grid-cols-3">
    <section class="card p-5">
        <h2 class="card-title">١. اطبع وصولها</h2>
        <p class="mt-1 text-sm text-ink-500">
            ورقة الدفتر، والوصل القديم، وما لم يُطبع له وصل: يُطبع لها وصلٌ برقمهم ويُلصق على الطرد، فيمسحه مندوبهم.
        </p>
        @if ($toPrint->isEmpty())
            <p class="mt-3 text-sm font-semibold text-ok-700">لا شيء يُطبع.</p>
        @else
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($toPrint->chunk(\App\Http\Controllers\ShipmentLabelController::MAX) as $i => $ids)
                    <a href="{{ route('shipments.labels', ['ids' => $ids->values()->all()]) }}" target="_blank" rel="noopener" class="btn-primary">
                        <x-icon name="printer" class="size-5"/>
                        اطبع <span class="num">{{ $ids->count() }}</span> {{ $ids->count() === 1 ? 'وصلاً' : 'وصولات' }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="card p-5">
        <h2 class="card-title">٢. أرسل ما لم يصلهم</h2>
        <p class="mt-1 text-sm text-ink-500">
            ما لم يُرسل، وما رفضوه أو تعذّر: يُرسل خلال دقيقة. ما رُفض لسببٍ (منطقةٍ غير مربوطة مثلاً) أصلحه أوّلاً من
            <a href="{{ route('aggregator.areas') }}" class="font-semibold text-[var(--brand)] hover:underline">ربط المناطق</a>.
        </p>
        <form method="POST" action="{{ route('aggregator.review.resend') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-primary" @disabled(! $toSend || ! $link->sends_orders)>
                أرسل <span class="num">{{ $toSend }}</span>
            </button>
            @unless ($link->sends_orders)
                <p class="mt-2 text-xs text-bad-700">إرسال الطلبات مطفأ في إعداد الربط.</p>
            @endunless
        </form>
    </section>

    <section class="card p-5">
        <h2 class="card-title">٣. تحقّق من نظامهم</h2>
        <p class="mt-1 text-sm text-ink-500">
            يُسأل نظامهم عن كلّ شحنةٍ برقمها عندهم، <span class="num">{{ \App\Http\Controllers\Tenant\AggregatorController::VERIFY_BATCH }}</span> في كلّ ضغطة. وما وُجد عندهم ولم يصلنا رقمه يُربط به.
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('aggregator.review.verify') }}">
                @csrf
                <button type="submit" class="btn-primary" @disabled(! $total)>تحقّق</button>
            </form>
            @if ($verifiedCount)
                <form method="POST" action="{{ route('aggregator.review.forget') }}">
                    @csrf
                    <button type="submit" class="btn-ghost">امسح الجواب</button>
                </form>
            @endif
        </div>
    </section>
</div>

{{-- لكلّ تاجر: أين المشكلة --}}
@if ($merchants->isNotEmpty())
    <section class="card mb-5 p-5">
        <h2 class="card-title">لكلّ تاجر</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm [&_td]:px-2 [&_th]:px-2">
                <thead><tr class="text-xs text-ink-500">
                    <th class="py-2 text-start">التاجر</th><th class="text-start">مع الوسيط</th><th class="text-start">يُقرأ</th><th class="text-start">لا يُقرأ، ولماذا</th>
                </tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($merchants as $m)
                        <tr>
                            <td class="py-2 font-semibold">{{ $m['merchant'] }}</td>
                            <td class="num">{{ $m['total'] }}</td>
                            <td class="num text-ok-700">{{ $m['ok'] }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($m['counts'] as $key => $n)
                                        <span class="chip {{ $tone[$key] ?? 'chip-mute' }}">{{ $problems[$key] }} <span class="num">{{ $n }}</span></span>
                                    @empty
                                        <span class="text-xs text-ok-700">كلّها تُقرأ</span>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="card-title">{{ $all ? 'كلّ الشحنات مع الوسيط' : 'ما لا يُقرأ' }}</h2>
        <div class="flex gap-1 text-xs">
            <a href="{{ route('aggregator.review') }}" class="chip {{ $all ? 'chip-mute' : 'chip-info' }}">ما لا يُقرأ</a>
            <a href="{{ route('aggregator.review', ['all' => 1]) }}" class="chip {{ $all ? 'chip-info' : 'chip-mute' }}">الكلّ</a>
        </div>
    </div>

    @if ($rows->isEmpty())
        <p class="mt-3 text-sm text-ok-700">{{ $total ? 'كلّ الشحنات مع الوسيط يقرؤها مندوبهم.' : 'لا شحنات مفتوحة مع المندوب الوسيط.' }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm [&_td]:px-2 [&_th]:px-2">
                <thead><tr class="text-xs text-ink-500">
                    <th class="py-2 text-start">الشحنة</th><th class="text-start">التاجر</th><th class="text-start">الحال</th>
                    <th class="text-start">رقمها عندهم</th><th class="text-start">باركود وصلها</th><th class="text-start">المشكلة</th><th class="text-start">عندهم</th>
                </tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($rows as $row)
                        @php $s = $row['shipment']; @endphp
                        <tr>
                            <td class="py-2"><a href="{{ route('shipments.show', $s) }}" class="num font-semibold hover:underline">{{ $s->number }}</a></td>
                            <td>{{ $s->merchant?->business_name }}</td>
                            <td class="text-xs">{{ $s->statusLabel() }}</td>
                            <td class="num" dir="ltr">{{ $row['expected'] }}</td>
                            <td class="num" dir="ltr">
                                @if ($row['label'] !== null)
                                    <span class="{{ $row['label'] === $row['expected'] ? 'text-ok-700' : 'text-bad-700' }}">{{ $row['label'] }}</span>
                                @elseif (\App\Http\Controllers\ShipmentLabelController::preprinted($s))
                                    <span class="text-bad-700">{{ $s->barcode }}</span> <span class="text-xs text-ink-500">(الدفتر)</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td class="max-w-sm">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($row['problems'] as $key)
                                        <span class="chip {{ $tone[$key] ?? 'chip-mute' }}">{{ $problems[$key] }}</span>
                                    @empty
                                        <span class="chip chip-ok">يُقرأ</span>
                                    @endforelse
                                </div>
                                @if ($row['error'])<p class="mt-1 text-xs text-bad-700">{{ $row['error'] }}</p>@endif
                            </td>
                            <td>
                                @if ($row['found'] === true)
                                    <span class="chip chip-ok">موجودة</span>
                                @elseif ($row['found'] === false)
                                    <span class="chip chip-bad">غير موجودة</span>
                                @else
                                    <span class="text-xs text-ink-400">لم يُسأل</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection

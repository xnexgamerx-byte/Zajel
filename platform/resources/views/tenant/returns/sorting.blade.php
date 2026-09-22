@extends('layouts.app')
@section('title', 'فرز الراجع للفروع')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">فرز الراجع للفروع</h1>
        <p class="mt-1 text-sm text-ink-500">
            الراجع يعود إلى حيث يقف المندوب لا إلى حيث يقف التاجر. ما على رفٍّ غير رفّ فرع تاجره يُكيَّس إليه.
        </p>
    </div>
    <a href="{{ route('returns.outgoing') }}" class="btn-ghost">
        جاهز للتسليم هنا: <span class="num">{{ number_format($readyHere) }}</span>
    </a>
</div>

@if ($misplaced->isEmpty() && $onTheWay->isEmpty())
    <section class="card p-10 text-center">
        <p class="font-medium text-ok-700">كل راجعٍ مستلَم على رفّ فرع تاجره.</p>
        <p class="mt-1 text-sm text-ink-500">لا شيء يُفرَز، ولا شيء في الطريق.</p>
    </section>
@endif

@foreach ($misplaced as $branchId => $shipments)
    @php
        $branch = $branches->get($branchId);
        $origins = $shipments->pluck('hub.name')->unique()->filter();
    @endphp
    <form method="POST" action="{{ route('returns.sort') }}" class="card mb-5 overflow-hidden" data-sort-group>
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branchId }}">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 px-5 py-4">
            <div>
                <h2 class="card-title">
                    إلى {{ $branch?->name ?? 'فرع محذوف' }}
                    <span class="num ms-1 text-ink-500">({{ number_format($shipments->count()) }})</span>
                </h2>
                <p class="card-hint">على رفّ: {{ $origins->implode('، ') ?: '—' }}</p>
            </div>
            <button type="submit" class="btn-primary">كيّس المُختار لـ {{ $branch?->name }}</button>
        </div>

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="w-10">
                            <input type="checkbox" checked aria-label="اختر الكل"
                                   onchange="this.closest('[data-sort-group]').querySelectorAll('input[name=\'shipment_ids[]\']').forEach(c => c.checked = this.checked)">
                        </th>
                        <th>الوصل</th>
                        <th>التاجر</th>
                        <th>على رفّ</th>
                        <th>سبب الرجوع</th>
                        <th>مستلَم منذ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($shipments as $shipment)
                        @php $days = (int) $shipment->return_received_at->diffInDays(now()); @endphp
                        <tr>
                            <td><input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" checked
                                       aria-label="اختر {{ $shipment->number }}"></td>
                            <td><a href="{{ route('shipments.show', $shipment) }}" class="num font-semibold hover:underline">{{ $shipment->number }}</a></td>
                            <td class="text-ink-600">{{ $shipment->merchant?->business_name }}</td>
                            <td class="text-ink-600">{{ $shipment->hub?->name ?? '—' }}</td>
                            <td class="text-xs text-ink-600">{{ $shipment->lastFailureReason?->name_ar ?? '—' }}</td>
                            <td>
                                {{-- راجعٌ على رفٍّ غريب منذ أيام: التاجر يسأل عنه ولا أحد يعرف مكانه --}}
                                <span class="chip {{ $days >= 3 ? 'chip-bad' : ($days >= 1 ? 'chip-warn' : 'chip-mute') }}">
                                    {{ $days === 0 ? 'اليوم' : \App\Support\Arabic::days($days) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </form>
@endforeach

@if ($onTheWay->isNotEmpty())
    <section class="card overflow-hidden">
        <div class="border-b border-ink-200 px-5 py-4">
            <h2 class="card-title">في الطريق إلى فروعها <span class="num ms-1 text-ink-500">({{ number_format($onTheWay->count()) }})</span></h2>
            <p class="card-hint">كُيِّست ولم يُفتح كيسها في الفرع بعد. ما طال هنا فقد ضاع أو نُسي في مركز الوصول.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>الوصل</th><th>التاجر</th><th>الكيس</th><th>إلى</th><th>حال الكيس</th><th>منذ</th></tr>
                </thead>
                <tbody>
                    @foreach ($onTheWay as $shipment)
                        @php
                            $bag = $shipment->currentBag;
                            $since = $bag?->sealed_at ?? $bag?->created_at;
                            $days = $since ? (int) $since->diffInDays(now()) : 0;
                        @endphp
                        <tr>
                            <td><a href="{{ route('shipments.show', $shipment) }}" class="num font-semibold hover:underline">{{ $shipment->number }}</a></td>
                            <td class="text-ink-600">{{ $shipment->merchant?->business_name }}</td>
                            <td>
                                @if ($bag)
                                    <a href="{{ route('bags.show', $bag) }}" class="num hover:underline">{{ $bag->code }}</a>
                                @endif
                            </td>
                            <td class="text-ink-600">{{ $bag?->toHub?->name }}</td>
                            <td>
                                @if ($bag)
                                    <span class="chip {{ $bag->statusTone() }}">{{ $bag->statusLabel() }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="chip {{ $days >= 3 ? 'chip-bad' : 'chip-mute' }}">
                                    {{ $days === 0 ? 'اليوم' : \App\Support\Arabic::days($days) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection

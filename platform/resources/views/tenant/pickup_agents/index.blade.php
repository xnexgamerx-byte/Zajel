@extends('layouts.app')
@section('title', 'حسابات مندوبي الاستلام')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">حسابات مندوبي الاستلام</h1>
        <p class="mt-1 text-sm text-ink-500">
            يجمع طروداً لا أموالاً، فحسابه عمولة صافية لا تسوية نقد.
        </p>
    </div>
    @if ($objections)
        <a href="{{ route('pickup-agents.objections') }}"
           class="card px-5 py-3 text-center transition hover:border-warn-700">
            <div class="text-xs text-ink-500">اعتراضات لم تُحسم</div>
            <div class="num text-2xl font-bold text-warn-700">{{ number_format($objections) }}</div>
        </a>
    @endif
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <button type="submit" class="btn-primary">طبّق</button>
</form>

<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>المندوب</th>
                    <th>طلبات المدّة</th>
                    <th>طروداً جمع</th>
                    <th>استحقّ في المدّة</th>
                    <th>الرصيد المستحقّ الآن</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($agents as $agent)
                    <tr>
                        <td>
                            <a href="{{ route('pickup-agents.show', $agent) }}"
                               class="font-medium text-ink-900 hover:underline">{{ $agent->name }}</a>
                            <div class="num text-xs text-ink-500">{{ $agent->phone }}</div>
                        </td>
                        <td class="num">{{ number_format($agent->period->requests ?? 0) }}</td>
                        <td class="num">{{ number_format($agent->period->parcels ?? 0) }}</td>
                        <td class="num">{{ number_format($agent->period->earned ?? 0) }}</td>
                        <td class="num font-bold {{ $agent->commission_balance > 0 ? 'text-[var(--brand)]' : 'text-ink-400' }}">
                            {{ number_format($agent->commission_balance) }}
                        </td>
                        <td class="text-end">
                            @if ($agent->commission_balance > 0)
                                <form method="POST" action="{{ route('pickup-agents.pay', $agent) }}"
                                      class="flex items-center justify-end gap-1">
                                    @csrf
                                    <select name="cash_box_id" class="field-input w-auto py-1 text-xs">
                                        <option value="">بلا صندوق</option>
                                        @foreach ($boxes as $box)
                                            <option value="{{ $box->id }}">{{ $box->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-ghost px-2 py-1 text-xs">ادفع</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-10 text-center text-ink-500">لا مندوبي استلام بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

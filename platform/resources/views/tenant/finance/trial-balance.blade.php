@extends('layouts.app')
@section('title', 'ميزان المراجعة')

@section('content')
@include('tenant.finance._tabs', [
    'title' => 'ميزان المراجعة والمطابقة',
    'lead'  => 'كل حسابٍ برصيده أوّل المدّة وحركته فيها ورصيده آخرها، من دفتر القيود. وتحته مطابقة الدفتر بالصناديق والمناديب والتجّار.',
])

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" type="date" name="from" value="{{ $period->from->toDateString() }}" class="field-input">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" type="date" name="to" value="{{ $period->to->toDateString() }}" class="field-input">
    </div>
    <button type="submit" class="btn-primary">عرض</button>
</form>

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">مطابقة الدفتر</h2>
    <ul class="divide-y divide-ink-100">
        @foreach ($checks as $c)
            <li class="flex flex-wrap items-start gap-3 px-5 py-3 text-sm">
                <span @class(['chip shrink-0', 'chip-ok' => $c['status'] === 'ok', 'chip-bad' => $c['status'] === 'fail', 'chip-warn' => $c['status'] === 'note'])>
                    {{ ['ok' => 'مطابق', 'fail' => 'فرق', 'note' => 'للعلم'][$c['status']] }}
                </span>
                <div class="min-w-0 flex-1">
                    <div class="font-semibold">{{ $c['label'] }}</div>
                    <div class="text-xs leading-6 text-ink-500">{{ $c['detail'] }}</div>
                </div>
                @if ($c['value'])<span class="num font-semibold">{{ number_format($c['value']) }}</span>@endif
            </li>
        @endforeach
    </ul>
</section>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>الحساب</th><th>أوّل المدّة</th><th>مدين</th><th>دائن</th><th>آخر المدّة</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($accounts as $type => $group)
                    <tr class="bg-ink-50"><td colspan="6" class="text-xs font-bold text-ink-600">{{ \App\Support\Finance\Accounts::TYPES[$type] ?? $type }}</td></tr>
                    @foreach ($group as $a)
                        <tr>
                            <td><span class="num text-xs text-ink-500">{{ $a->code }}</span> {{ $a->name }}</td>
                            <td class="num">{{ number_format($a->opening) }}</td>
                            <td class="num">{{ number_format($a->debit) }}</td>
                            <td class="num">{{ number_format($a->credit) }}</td>
                            <td class="num font-semibold">{{ number_format($a->closing) }}</td>
                            <td><a href="{{ route('finance.ledger', ['account' => $a->code] + $period->query()) }}" class="text-xs text-[var(--brand)] hover:underline">القيود ←</a></td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="6" class="px-4 py-14 text-center text-ink-500">لا قيود بعد.</td></tr>
                @endforelse
            </tbody>
            @if ($accounts->isNotEmpty())
                @php($all = $accounts->flatten())
                <tfoot>
                    <tr class="font-bold">
                        <td>المجموع</td>
                        <td class="num">{{ number_format($all->sum('opening')) }}</td>
                        <td class="num">{{ number_format($all->sum('debit')) }}</td>
                        <td class="num">{{ number_format($all->sum('credit')) }}</td>
                        <td class="num">{{ number_format($all->sum('closing')) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">الأرصدة مدينٌ موجب ودائنٌ سالب؛ ومجموع كل عمودٍ صفرٌ حين يتوازن الدفتر.</p>
</div>
@endsection

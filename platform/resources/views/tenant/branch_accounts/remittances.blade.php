@extends('layouts.app')
@section('title', 'استلام مبالغ الفروع')

@section('content')
<div class="mb-5">
    <h1 class="page-title">استلام مبالغ الفروع</h1>
    <p class="mt-1 text-sm text-ink-500">
        ما أرسله فرعٌ تسديداً لما عليه. عُدَّه واستلمه بمبلغه الفعليّ في صندوق فرعك — والفرق عن المُرسَل يُكتب سببه.
    </p>
</div>

<section class="card mb-5 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">بانتظار الاستلام</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الرقم</th><th>من فرع</th><th>إلى فرع</th><th>المُرسَل</th><th>أرسله</th><th>متى</th>@can('money.cash')<th>الاستلام</th>@endcan</tr></thead>
            <tbody>
                @forelse ($pending as $remittance)
                    <tr>
                        <td class="num font-semibold">{{ $remittance->number }}</td>
                        <td>{{ $remittance->fromBranch?->name }}</td>
                        <td>{{ $remittance->toBranch?->name }}</td>
                        <td class="num font-bold">{{ number_format($remittance->amount) }}</td>
                        <td class="text-sm">{{ $remittance->sentBy?->name ?? '—' }}</td>
                        <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $remittance->sent_at->format('Y-m-d H:i') }}</td>
                        @can('money.cash')
                            <td>
                                @if (($boxes[$remittance->to_branch_id] ?? collect())->isNotEmpty())
                                    <form method="POST" action="{{ route('branch-accounts.receive', $remittance) }}" class="flex flex-wrap items-center gap-1.5">
                                        @csrf
                                        <input name="received_amount" type="number" min="0" value="{{ $remittance->amount }}" class="field-input num w-28 py-1 text-xs" aria-label="المستلم فعلياً">
                                        <select name="to_box_id" class="field-input w-auto py-1 text-xs" aria-label="في صندوق">
                                            @foreach ($boxes[$remittance->to_branch_id] as $box)
                                                <option value="{{ $box->id }}">{{ $box->name }}</option>
                                            @endforeach
                                        </select>
                                        <input name="difference_note" maxlength="255" class="field-input w-40 py-1 text-xs" placeholder="ملاحظة فرق المبلغ" aria-label="ملاحظة فرق المبلغ">
                                        <button class="btn-primary py-1 text-xs">استلمت</button>
                                    </form>
                                @else
                                    <span class="text-xs text-ink-500">لا صندوق للفرع المستلم</span>
                                @endif
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-ink-500">لا مبلغ بانتظار الاستلام.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if ($outgoing->isNotEmpty())
    <section class="card mb-5 overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">أرسلناه ولم يُستلم بعد</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>الرقم</th><th>إلى فرع</th><th>المبلغ</th><th>متى</th></tr></thead>
                <tbody>
                    @foreach ($outgoing as $remittance)
                        <tr>
                            <td class="num font-semibold">{{ $remittance->number }}</td>
                            <td>{{ $remittance->toBranch?->name }}</td>
                            <td class="num">{{ number_format($remittance->amount) }}</td>
                            <td class="num text-xs text-ink-500">{{ $remittance->sent_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">آخر ما استُلم</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>الرقم</th><th>من فرع</th><th>إلى فرع</th><th>المُرسَل</th><th>المستلم فعلياً</th><th>ملاحظة فرق المبلغ</th><th>استلمه</th><th>متى</th></tr></thead>
            <tbody>
                @forelse ($history as $remittance)
                    <tr>
                        <td class="num font-semibold">{{ $remittance->number }}</td>
                        <td>{{ $remittance->fromBranch?->name }}</td>
                        <td>{{ $remittance->toBranch?->name }} <span class="text-xs text-ink-500">· {{ $remittance->toBox?->name }}</span></td>
                        <td class="num">{{ number_format($remittance->amount) }}</td>
                        <td class="num font-semibold {{ $remittance->difference() ? 'text-bad-700' : 'text-ok-700' }}">{{ number_format($remittance->received_amount) }}</td>
                        <td class="text-sm text-ink-600">{{ $remittance->difference_note ?? '—' }}</td>
                        <td class="text-sm">{{ $remittance->receivedBy?->name ?? '—' }}</td>
                        <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $remittance->received_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-ink-500">لم يُستلم شيءٌ بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

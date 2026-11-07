@extends('layouts.app')
@section('title', 'مطابقة كشف ' . $settlement->code)

@php
    use App\Services\Settlements\CourierFileMatch;
    $cats = CourierFileMatch::CATEGORIES;
    $c = $summary['count'];
    $okNet = $summary['ok_amount'] - $summary['ok_fee'];
    $diffs = $c['amount'] + $c['fee'] + $c['status'];
    $outside = $c['addable'] + $c['settled'] + $c['not_delivered'] + $c['other_courier'] + $c['unknown'];
    $link = fn (?string $key) => route('settlements.couriers.match', [$settlement, $token] + ($key ? ['show' => $key] : []));
@endphp

@section('content')
{{-- «مطابقة الوصولات» (docs/plan/124): الملخّص أعلاه، والطلبات تحته بفئاتها، والمحاسبة على المطابق بضغطة --}}
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">مطابقة الوصولات</h1>
        <p class="mt-1 text-sm text-ink-500">
            كشف <span class="font-mono" dir="ltr">{{ $settlement->code }}</span> · {{ $settlement->courier->name }}
            · الملف <bdi>{{ $fileName }}</bdi>
        </p>
    </div>
    <a href="{{ route('settlements.couriers.show', $settlement) }}" class="btn-ghost">رجوع للكشف</a>
</div>

@if ($errors->any())
    <div class="mb-4 rounded-lg bg-bad-50 px-4 py-3 text-sm text-bad-700 ring-1 ring-bad-200">
        @foreach ($errors->all() as $error) <p>{{ $error }}</p> @endforeach
    </div>
@endif

{{-- الملخّص --}}
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
    <a href="{{ $link(null) }}" class="card p-4 hover:ring-2 hover:ring-ink-200">
        <p class="text-xs text-ink-500">بالملف</p>
        <p class="num mt-1 text-2xl font-bold">{{ number_format($summary['file_rows']) }}</p>
        <p class="text-xs text-ink-500"><span class="num">{{ number_format($summary['file_amount']) }}</span> د.ع</p>
    </a>
    <a href="{{ $link('ok') }}" class="card p-4 ring-1 ring-ok-200 hover:ring-2">
        <p class="text-xs text-ok-700">مطابقة</p>
        <p class="num mt-1 text-2xl font-bold text-ok-700">{{ number_format($c['ok']) }}</p>
        <p class="text-xs text-ink-500">يُستلم منه <span class="num">{{ number_format($okNet) }}</span> د.ع</p>
    </a>
    <a href="{{ $link($c['amount'] ? 'amount' : ($c['fee'] ? 'fee' : 'status')) }}" class="card p-4 hover:ring-2 hover:ring-bad-200">
        <p class="text-xs text-bad-700">فيها فرق</p>
        <p class="num mt-1 text-2xl font-bold {{ $diffs ? 'text-bad-700' : '' }}">{{ number_format($diffs) }}</p>
        <p class="text-xs text-ink-500">
            @if ($summary['amount_diff'])
                الفرق <span class="num" dir="ltr">{{ $summary['amount_diff'] > 0 ? '+' : '' }}{{ number_format($summary['amount_diff']) }}</span> د.ع
            @else
                بالمبلغ أو الأجرة أو الحالة
            @endif
        </p>
    </a>
    <a href="{{ $link($c['addable'] ? 'addable' : ($c['unknown'] ? 'unknown' : null)) }}" class="card p-4 hover:ring-2 hover:ring-warn-200">
        <p class="text-xs text-warn-700">بالملف وليست بالكشف</p>
        <p class="num mt-1 text-2xl font-bold">{{ number_format($outside) }}</p>
        <p class="text-xs text-ink-500">{{ number_format($c['addable']) }} تُضاف · {{ number_format($c['unknown']) }} غير موجودة</p>
    </a>
    <a href="{{ $link('absent') }}" class="card p-4 hover:ring-2 hover:ring-warn-200">
        <p class="text-xs text-warn-700">بالكشف وليست بالملف</p>
        <p class="num mt-1 text-2xl font-bold">{{ number_format($c['absent']) }}</p>
        <p class="text-xs text-ink-500">من <span class="num">{{ number_format($summary['draft_rows']) }}</span> بالكشف</p>
    </a>
</div>

{{-- ما يُفعل بالمطابقة --}}
@if ($draft)
    <div class="mb-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
        <form method="POST" action="{{ route('settlements.couriers.confirm', $settlement) }}" class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
            @csrf
            @foreach ($summary['ok_ids'] as $id)
                <input type="hidden" name="shipment_ids[]" value="{{ $id }}">
            @endforeach
            <input type="hidden" name="notes" value="مطابقة ملف: {{ mb_substr($fileName, 0, 80) }}">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold">حاسب المندوب على المطابقة</p>
                <p class="text-xs text-ink-500">
                    {{ \App\Support\Arabic::shipments($c['ok']) }} بلا فرق، يُستلم منه {{ number_format($okNet) }} د.ع،
                    وتبقى البقية في المسودّة.
                </p>
                @if ($boxes->isNotEmpty())
                    <select name="cash_box_id" aria-label="يدخل النقد في" class="field-input mt-2 w-full py-1.5 text-sm">
                        @foreach ($boxes as $choice)
                            <option value="{{ $choice->id }}" @selected($defaultBox?->id === $choice->id)>
                                يدخل في {{ $choice->name }}{{ $choice->user_id === auth()->id() ? ' (صندوقي)' : '' }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </div>
            <button type="submit" class="btn-primary shrink-0" @disabled(! $c['ok'])
                    data-confirm="يُحاسَب المندوب على {{ \App\Support\Arabic::shipments($c['ok']) }} ويُستلم منه {{ number_format($okNet) }} د.ع. بعد التأكيد لا يُعدَّل ما حوسب عليه.">
                حاسب على المطابقة
            </button>
        </form>

        <form method="POST" action="{{ route('settlements.couriers.lines.add', $settlement) }}" class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
            @csrf
            @foreach ($summary['addable_ids'] as $id)
                <input type="hidden" name="shipment_ids[]" value="{{ $id }}">
            @endforeach
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold">أضف للكشف ما بالملف وليس به</p>
                <p class="text-xs text-ink-500">
                    {{ \App\Support\Arabic::shipments($c['addable']) }} واصلة عندنا لهذا المندوب ولم تُحاسَب. بعد إضافتها تُطابَق من جديد.
                </p>
            </div>
            <button type="submit" class="btn-ghost shrink-0" @disabled(! $c['addable'])>أضفها للكشف</button>
        </form>
    </div>
@else
    <p class="mb-5 rounded-lg bg-info-50 px-4 py-3 text-sm text-info-700 ring-1 ring-info-200">
        أُقفل الكشف: المطابقة للعرض وحده.
    </p>
@endif

{{-- الطلبات بفئاتها: يُحدَّد منها ما يُراد، فيُحاسَب عليه أو يُضاف للكشف من الشريط أسفل الشاشة --}}
<section @class(['card overflow-hidden', 'mb-24' => $draft])>
    <div class="flex flex-wrap items-center gap-2 border-b border-ink-100 px-5 py-3">
        <a href="{{ $link(null) }}" @class(['chip', 'chip-info ring-2 ring-info-300' => ! $show, 'chip-mute' => $show])>
            الكل <span class="num">{{ number_format(array_sum($c)) }}</span>
        </a>
        @foreach ($cats as $key => [$label, $tone])
            @continue(! $c[$key])
            <a href="{{ $link($key) }}" @class(['chip', "chip-{$tone}", 'ring-2 ring-ink-300' => $show === $key])
               title="{{ $cats[$key][2] }}">
                {{ $label }} <span class="num">{{ number_format($c[$key]) }}</span>
            </a>
        @endforeach
    </div>

    @if ($show)
        <p class="border-b border-ink-100 bg-ink-50 px-5 py-2 text-xs text-ink-600">{{ $cats[$show][2] }}</p>
    @endif
    @if ($draft)
        <p class="border-b border-ink-100 px-5 py-2 text-xs text-ink-500">
            حدّد ما تريد بنفسك: ما بالكشف تحاسبه عليه، وما ليس به تضيفه — ولو كان فيه فرق. والمطابقة محدَّدةٌ سلفاً، تلغي منها ما شئت.
        </p>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr>
                    @if ($draft)
                        <th class="w-10">
                            <input type="checkbox" aria-label="حدّد كل ما يُحاسَب عليه" class="size-4 accent-[var(--brand)]"
                                   data-check-all-in="table" data-check-all-of='tbody input[form="match-settle"]'>
                        </th>
                    @endif
                    <th>المطابقة</th>
                    <th>رقم الوصل</th>
                    <th>رقم الطلب</th>
                    <th>الزبون</th>
                    <th>بالملف</th>
                    <th>بالكشف</th>
                    @if ($summary['has_fee'])
                        <th>الأجرة بالملف</th>
                        <th>عمولته</th>
                    @endif
                    @if ($summary['has_status'])
                        <th>الحالة بالملف</th>
                    @endif
                    <th>حالتها عندنا</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($items as $item)
                    @php
                        [$label, $tone] = $cats[$item['category']];
                        $file = $item['file'];
                        $s = $item['shipment'];
                        $line = $item['line'];
                    @endphp
                    <tr @class(['bg-bad-50/40' => $tone === 'bad'])>
                        @if ($draft)
                            <td class="px-4 py-2.5">
                                @if ($line && $item['category'] !== 'duplicate')
                                    {{-- بالكشف: يُحاسَب عليه --}}
                                    <input type="checkbox" name="shipment_ids[]" value="{{ $s->id }}" form="match-settle"
                                           data-net="{{ (int) $line->collected_amount - (int) $line->commission }}"
                                           @checked($item['category'] === 'ok')
                                           aria-label="حاسب على {{ $s->number }}" class="size-4 accent-[var(--brand)]">
                                @elseif ($item['category'] === 'addable')
                                    {{-- واصلةٌ ليست بالكشف: تُضاف إليه --}}
                                    <input type="checkbox" name="shipment_ids[]" value="{{ $s->id }}" form="match-add"
                                           aria-label="أضف {{ $s->number }} للكشف" class="size-4 accent-[var(--brand)]">
                                @endif
                            </td>
                        @endif
                        <td class="px-4 py-2.5"><span class="chip chip-{{ $tone }} whitespace-nowrap">{{ $label }}</span></td>
                        <td class="px-4 py-2.5">
                            @if ($s)
                                <a href="{{ route('shipments.show', $s) }}" class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $s->number }}</a>
                            @else
                                <span class="font-mono text-ink-500" dir="ltr">{{ $file['number'] ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-mono text-ink-600" dir="ltr">{{ $file['order'] ?? $s?->aggregator_order_id ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ $s?->recipient_name ?? $file['name'] ?? '—' }}</td>
                        <td @class(['px-4 py-2.5 font-semibold', 'text-bad-700' => $item['category'] === 'amount']) dir="ltr">
                            {{ isset($file['amount']) ? number_format($file['amount']) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 font-semibold" dir="ltr">{{ $line ? number_format($line->collected_amount) : '—' }}</td>
                        @if ($summary['has_fee'])
                            <td @class(['px-4 py-2.5', 'text-warn-700 font-semibold' => $item['category'] === 'fee']) dir="ltr">
                                {{ isset($file['fee']) ? number_format($file['fee']) : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-ok-700" dir="ltr">{{ $line ? number_format($line->commission) : '—' }}</td>
                        @endif
                        @if ($summary['has_status'])
                            <td @class(['px-4 py-2.5 text-ink-600', 'text-bad-700 font-semibold' => $item['category'] === 'status'])>{{ $file['status'] ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-2.5">
                            @if ($s) <x-status-badge :status="$s->status" /> @else — @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-8 text-center text-ink-500">لا طلبات في هذه الفئة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if ($draft)
    <form id="match-settle" method="POST" action="{{ route('settlements.couriers.confirm', $settlement) }}">
        @csrf
        <input type="hidden" name="notes" value="مطابقة ملف (تحديد): {{ mb_substr($fileName, 0, 70) }}">
    </form>
    <form id="match-add" method="POST" action="{{ route('settlements.couriers.lines.add', $settlement) }}">
        @csrf
    </form>

    {{-- ما حُدِّد: يُحاسَب عليه بصافيه، أو يُضاف للكشف --}}
    <div class="dock fixed inset-x-0 bottom-0 z-40">
        <x-picked-bar form="match-settle" :total="(int) $summary['draft_rows']" all="كل شحنات الكشف" net-label="يُستلم منه">
            @if ($boxes->isNotEmpty())
                <select name="cash_box_id" form="match-settle" aria-label="يدخل النقد في" class="field-input h-9 w-auto py-1 text-sm">
                    @foreach ($boxes as $choice)
                        <option value="{{ $choice->id }}" @selected($defaultBox?->id === $choice->id)>
                            {{ $choice->name }}{{ $choice->user_id === auth()->id() ? ' (صندوقي)' : '' }}
                        </option>
                    @endforeach
                </select>
            @endif
            <button type="submit" form="match-settle" class="btn-primary h-9 px-4 text-sm"
                    data-confirm-some="يُحاسَب المندوب على {count} ويُستلم منه {net} د.ع، وتبقى البقية في المسودّة {{ $settlement->code }}. بعد التأكيد لا يُعدَّل ما حوسب عليه."
                    data-confirm-all="حدّدت كل شحنات الكشف: يُقفَل كشف {{ $settlement->code }} كلّه ويُستلم من المندوب {net} د.ع. بعد التأكيد لا يُعدَّل.">
                حاسب المندوب على المحدَّد
            </button>
        </x-picked-bar>
        <x-picked-bar form="match-add">
            <button type="submit" form="match-add" class="btn-primary h-9 px-4 text-sm">أضف المحدَّد للكشف</button>
        </x-picked-bar>
    </div>
@endif
@endsection

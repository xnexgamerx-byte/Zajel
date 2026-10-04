@extends('layouts.app')
@section('title', 'طلبات المناديب لتغيير المبلغ')

@section('content')
<div class="mb-4">
    <h1 class="page-title">طلبات المناديب لتغيير المبلغ</h1>
    <p class="mt-1 text-sm text-ink-500">
        المندوب لا يغيّر المبلغ عند الباب: إن قال الزبون مبلغاً آخر طلبه من هنا. اتّصل بالتاجر أو بالزبون، ثم اعتمد المبلغ أو ارفضه —
        المندوب عند الباب ينتظر، والأقدم أوّلاً.
    </p>
    @if ($governorates->isNotEmpty())
        <p class="mt-2 text-sm">
            <span class="text-ink-500">تظهر لك طلبات محافظاتك:</span>
            @foreach ($governorates as $name)<span class="chip chip-info ms-1">{{ $name }}</span>@endforeach
        </p>
    @endif
</div>

<nav class="tab-nav mb-4" aria-label="الطلبات">
    <a href="{{ route('tickets.index') }}" @class(['tab-link', 'tab-link-active' => $tab === 'open'])>
        بانتظار الجواب @if ($openCount)<span class="nav-badge">{{ number_format($openCount) }}</span>@endif
    </a>
    <a href="{{ route('tickets.index', ['tab' => 'done']) }}" @class(['tab-link', 'tab-link-active' => $tab === 'done'])>حُسمت (٧ أيام)</a>
</nav>

@if ($tab === 'open')
    @if ($tickets->isEmpty())
        <p class="card py-12 text-center text-sm text-ink-500">لا طلب ينتظر جوابك.</p>
    @else
        <div class="space-y-3">
            @foreach ($tickets as $ticket)
                @php
                    $shipment = $ticket->shipment;
                    $minutes = (int) $ticket->created_at->diffInMinutes(now());
                @endphp
                <section class="card p-4" id="ticket-{{ $ticket->id }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                        <span class="num font-semibold" dir="ltr">{{ $ticket->number }}</span>
                        <span @class(['chip', 'chip-warn' => $ticket->kind === 'partial', 'chip-info' => $ticket->kind === 'price'])>{{ $ticket->kindLabel() }}</span>
                        <a href="{{ route('shipments.show', $shipment) }}" class="num font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $shipment->number }}</a>
                        <span class="font-medium">{{ $shipment->recipient_name }}</span>
                        <span class="text-ink-500"><x-phone :number="$shipment->recipient_phone" :name="$shipment->recipient_name" /></span>
                        <span class="text-ink-500">{{ $ticket->governorate?->name_ar }}@if ($shipment->city) · {{ $shipment->city->name_ar }}@endif</span>
                        <span @class(['ms-auto text-xs', 'font-semibold text-bad-700' => $minutes >= 15, 'text-ink-500' => $minutes < 15])>
                            {{ $minutes ? "ينتظر منذ {$minutes} د" : 'الآن' }}
                        </span>
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <div class="text-xs text-ink-500">المبلغ</div>
                            <div class="text-lg font-bold">
                                <span class="num text-ink-500 line-through">{{ number_format($ticket->current_amount) }}</span>
                                <span class="text-ink-400">←</span>
                                <span class="num text-warn-700">{{ number_format($ticket->requested_amount) }}</span>
                                <span class="text-xs font-normal text-ink-500">د.ع</span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-ink-500">المندوب</div>
                            <div class="font-medium">{{ $ticket->courier?->name ?? '—' }}
                                @if ($ticket->courier?->phone)
                                    <a href="tel:{{ $ticket->courier->phone }}" class="num ms-1 text-sm text-[var(--brand)] hover:underline" dir="ltr">{{ $ticket->courier->phone }}</a>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-ink-500">التاجر</div>
                            <div class="font-medium">{{ $shipment->merchant?->business_name }}
                                @if ($shipment->merchant?->phone)
                                    <a href="tel:{{ $shipment->merchant->phone }}" class="num ms-1 text-sm text-[var(--brand)] hover:underline" dir="ltr">{{ $shipment->merchant->phone }}</a>
                                @endif
                            </div>
                        </div>
                    </div>

                    <p class="mt-3 rounded-lg bg-warn-50 px-3 py-2 text-sm text-warn-700">
                        <span class="font-semibold">ما قاله الزبون:</span> {{ $ticket->reason }}
                    </p>

                    <form method="POST" action="{{ route('tickets.approve', $ticket) }}" class="mt-3 flex flex-wrap items-end gap-2">
                        @csrf
                        <div class="w-40">
                            <label class="field-label" for="amount-{{ $ticket->id }}">المبلغ المعتمد</label>
                            <input id="amount-{{ $ticket->id }}" name="approved_amount" type="number" min="0" step="1" required
                                   class="field-input py-1.5 text-left" dir="ltr" value="{{ $ticket->requested_amount }}">
                        </div>
                        <div class="min-w-56 flex-1">
                            <label class="field-label" for="reply-{{ $ticket->id }}">ردّك للمندوب</label>
                            <input id="reply-{{ $ticket->id }}" name="reply" class="field-input py-1.5" maxlength="255"
                                   placeholder="مثلاً: اتّصلت بالتاجر ووافق">
                        </div>
                        <button type="submit" class="btn-primary py-1.5">اعتماد</button>
                        <button type="submit" formaction="{{ route('tickets.reject', $ticket) }}" class="btn-danger py-1.5"
                                formnovalidate>رفض</button>
                    </form>
                    <p class="mt-2 text-xs text-ink-500">
                        @if ($ticket->kind === 'price')
                            الاعتماد يغيّر مبلغ الشحنة الآن (ويُكتب في سجلّها)، ويسلّم المندوب «واصل» بالمبلغ الجديد.
                        @else
                            الاعتماد يفتح للمندوب «واصل جزئي» بهذا المبلغ، وباقي الطلب يرجع لتاجره راجعاً بلا أجرة راجع.
                        @endif
                        والرفض يحتاج سبباً في «ردّك».
                    </p>
                </section>
            @endforeach
        </div>
        @if ($tickets->hasPages())
            <div class="mt-4">{{ $tickets->links() }}</div>
        @endif
    @endif
@else
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الطلب</th><th>الوصل</th><th>السبب</th><th>المبلغ</th><th>النتيجة</th><th>عالجه</th><th>الوقت</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="num whitespace-nowrap font-semibold" dir="ltr">{{ $ticket->number }}</td>
                            <td class="num whitespace-nowrap" dir="ltr">
                                <a href="{{ route('shipments.show', $ticket->shipment) }}" class="text-[var(--brand)] hover:underline">{{ $ticket->shipment->number }}</a>
                            </td>
                            <td class="min-w-48 text-sm">
                                <span class="font-medium">{{ $ticket->kindLabel() }}</span>
                                <span class="block text-xs text-ink-500">{{ $ticket->reason }}</span>
                            </td>
                            <td class="num whitespace-nowrap">{{ number_format($ticket->current_amount) }} ← {{ number_format($ticket->requested_amount) }}</td>
                            <td class="min-w-48 text-sm">
                                <span @class(['chip', 'chip-ok' => $ticket->status === 'approved', 'chip-bad' => $ticket->status === 'rejected', 'chip-mute' => $ticket->status === 'closed'])>{{ $ticket->statusLabel() }}</span>
                                @if ($ticket->status === 'approved')
                                    <span class="num">{{ number_format($ticket->approved_amount) }}</span>
                                @endif
                                @if ($ticket->reply)<span class="block text-xs text-ink-500">{{ $ticket->reply }}</span>@endif
                                @if ($ticket->closed_note)<span class="block text-xs text-ink-500">{{ $ticket->closed_note }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap">{{ $ticket->handledBy?->name ?? '—' }}</td>
                            <td class="num whitespace-nowrap text-xs text-ink-500">{{ $ticket->updated_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-12 text-center text-ink-500">لم يُحسم طلبٌ في الأيام السبعة الأخيرة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tickets->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $tickets->links() }}</div>
        @endif
    </div>
@endif
@endsection

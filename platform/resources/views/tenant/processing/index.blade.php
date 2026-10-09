@extends('layouts.app')
@section('title', 'شحنات لم تُسلَّم (للمعالجة)')

@section('content')
<div class="mb-4">
    <h1 class="page-title">شحنات لم تُسلَّم (للمعالجة)</h1>
    <p class="mt-1 text-sm text-ink-500">
        محاولاتٌ فاشلة تنتظر قراراً، الأقدم أوّلاً: اتّصل بالزبون، ثم إعادة توصيل، أو تأجيل إلى موعده، أو تأكيد الراجع.
    </p>
    @if ($governorates->isNotEmpty())
        <p class="mt-2 text-sm">
            <span class="text-ink-500">تظهر لك شحنات محافظاتك:</span>
            @foreach ($governorates as $name)<span class="chip chip-info ms-1">{{ $name }}</span>@endforeach
        </p>
    @endif
</div>

<nav class="tab-nav mb-4" aria-label="المعالجة">
    <a href="{{ route('processing.index') }}" @class(['tab-link', 'tab-link-active' => $tab === 'pending'])>
        للمعالجة @if ($pendingCount)<span class="nav-badge">{{ number_format($pendingCount) }}</span>@endif
    </a>
    <a href="{{ route('processing.index', ['tab' => 'done']) }}" @class(['tab-link', 'tab-link-active' => $tab === 'done'])>تمّت معالجتها (٧ أيام)</a>
</nav>

@error('action') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror
@error('until') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror

@if ($tab === 'pending')
    @if ($pendingCount)
        <form method="GET" action="{{ route('processing.index') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-56 flex-1">
                <label class="field-label" for="q">رقم الوصل أو هاتف الزبون</label>
                <input id="q" name="q" class="field-input num" value="{{ request('q') }}" inputmode="search" autocomplete="off">
            </div>
            <div class="min-w-48">
                <label class="field-label" for="courier_id">المندوب</label>
                <select id="courier_id" name="courier_id" class="field-input" data-searchable>
                    <option value="">الكل</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary">بحث</button>
            @if ($filtered)
                <a href="{{ route('processing.index') }}" class="btn-ghost">إلغاء البحث</a>
            @endif
        </form>
    @endif

    @if ($shipments->isEmpty())
        <p class="card py-12 text-center text-sm text-ink-500">
            {{ $filtered ? 'لا شحنة للمعالجة تطابق بحثك.' : 'لا شيء ينتظر المعالجة.' }}
        </p>
    @else
        <div class="space-y-3">
            @foreach ($shipments as $shipment)
                @php $hours = (int) $shipment->status_changed_at?->diffInHours(now()); @endphp
                <section class="card p-4">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                        <a href="{{ route('shipments.show', $shipment) }}" class="num font-semibold text-[var(--brand)] hover:underline" dir="ltr">{{ $shipment->number }}</a>
                        <span class="font-medium">{{ $shipment->recipient_name }}</span>
                        <span class="text-ink-500"><x-phone :number="$shipment->recipient_phone" :name="$shipment->recipient_name" /></span>
                        <span class="text-ink-500">{{ $shipment->governorate?->name_ar }}@if ($shipment->city) · {{ $shipment->city->name_ar }}@endif</span>
                        <span class="text-ink-500">{{ $shipment->merchant?->business_name }}</span>
                        @if ($shipment->lastFailureReason)<span class="chip chip-warn">{{ $shipment->lastFailureReason->name_ar }}</span>@endif
                        @if ($shipment->attempts_count > 1)<span class="chip chip-bad">المحاولة {{ $shipment->attempts_count }}</span>@endif
                        <span @class(['ms-auto text-xs', 'font-semibold text-bad-700' => $hours >= 24, 'text-ink-500' => $hours < 24])>
                            {{ $hours >= 24 ? 'تنتظر منذ '.\App\Support\Arabic::days(intdiv($hours, 24)) : ($hours ? "منذ {$hours} س" : 'الآن') }}
                            · {{ $shipment->deliveryCourier?->name ?? 'بلا مندوب' }}
                        </span>
                    </div>
                    @php
                        $text = \App\Support\MerchantMessage::for($shipment, auth()->user());
                        $whatsapp = \App\Support\Phone::whatsappUrl($shipment->merchant?->phone, $text);
                    @endphp
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        {{-- «أرسل للتاجر» بلا قرار: الرسالة تصله في «المحادثات»، والشحنة تبقى هنا حتى يردّ (docs/plan/41) --}}
                        @if ($canAsk && $shipment->merchant)
                            <form method="POST" action="{{ route('processing.ask', $shipment) }}">
                                @csrf
                                <button type="submit" class="btn-primary py-1">أرسل للتاجر</button>
                            </form>
                        @endif
                        <button type="button" class="btn-ghost py-1" data-copy-text="{{ $text }}">نسخ رسالة التاجر</button>
                        @if ($whatsapp)
                            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-ghost py-1">واتساب التاجر</a>
                        @endif
                        @if ($shipment->asked_at)
                            <span class="chip chip-info">أُرسل للتاجر {{ \Illuminate\Support\Carbon::parse($shipment->asked_at)->diffForHumans() }} — ينتظر ردّه</span>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('processing.store', $shipment) }}" class="mt-3 flex flex-wrap items-end gap-2">
                        @csrf
                        <input name="note" class="field-input min-w-56 flex-1 py-1.5" maxlength="255" placeholder="ما قاله الزبون"
                               aria-label="ما قاله الزبون عن {{ $shipment->number }}">
                        <button name="action" value="redeliver" class="btn-primary py-1">إعادة توصيل</button>
                        <span class="flex items-center gap-1">
                            <input type="date" name="until" class="field-input w-40 py-1.5" min="{{ today()->toDateString() }}"
                                   value="{{ today()->addDay()->toDateString() }}" aria-label="موعد التأجيل">
                            <button name="action" value="postpone" class="btn-ghost py-1">تأجيل</button>
                        </span>
                        {{-- «تأكيد الراجع»: يصير «راجع مؤكد» ويرجع لتاجره (docs/plan/38) --}}
                        <button name="action" value="return" class="btn-danger py-1">تأكيد الراجع</button>
                    </form>
                </section>
            @endforeach
        </div>
        @if ($shipments->hasPages())
            <div class="mt-4">{{ $shipments->links() }}</div>
        @endif
    @endif
@else
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الوصل</th><th>التاجر</th><th>القرار</th><th>ما قاله الزبون</th><th>عالجها</th><th>انتظرت</th><th>الوقت</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($done as $event)
                        @php $minutes = (int) ($event->meta['waited_minutes'] ?? 0); @endphp
                        <tr>
                            <td class="num font-semibold" dir="ltr">
                                @if ($event->shipment)<a href="{{ route('shipments.show', $event->shipment) }}" class="text-[var(--brand)] hover:underline">{{ $event->shipment->number }}</a>@endif
                            </td>
                            <td>{{ $event->shipment?->merchant?->business_name }}</td>
                            <td class="whitespace-nowrap">{{ \App\Http\Controllers\Tenant\ProcessingController::ACTIONS[$event->meta['action'] ?? ''] ?? $event->toLabel() }}</td>
                            <td class="min-w-48 text-sm text-ink-700">{{ $event->meta['said'] ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ $event->actor_name }}</td>
                            <td class="num whitespace-nowrap">{{ $minutes >= 1440 ? \App\Support\Arabic::days(intdiv($minutes, 1440)) : intdiv($minutes, 60).' س '.($minutes % 60).' د' }}</td>
                            <td class="num whitespace-nowrap text-xs text-ink-500">{{ $event->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-12 text-center text-ink-500">لم يُعالَج شيء في الأيام السبعة الأخيرة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($done->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $done->links() }}</div>
        @endif
    </div>
@endif
@endsection

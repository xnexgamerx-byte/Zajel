@extends('layouts.courier')
@section('title', 'المكتب')

@section('content')
<h1 class="mb-1 font-heading text-lg font-bold">المحادثة مع المكتب</h1>
<p class="mb-3 text-sm text-ink-500">اكتب للكول سنتر عن أيّ شحنة: الزبون لا يردّ، العنوان غلط، يريد موعداً آخر.</p>

<ol class="mb-4 space-y-3" aria-label="الرسائل">
    @forelse ($messages as $message)
        @php $mine = $message->author === 'courier'; @endphp
        <li class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
            <div class="max-w-[85%] rounded-2xl px-4 py-2.5 {{ $mine ? 'bg-[var(--brand)] text-white' : 'border border-ink-200 bg-white' }}">
                @if ($message->shipment)
                    {{-- رقم الوصل يفتح الشحنة نفسها (docs/plan/46): المندوب يعرف أيّ طلبٍ تخصّه الرسالة بضغطة --}}
                    @php $his = in_array($courier->id, [$message->shipment->delivery_courier_id, $message->shipment->pickup_courier_id], true); @endphp
                    @if ($his)
                        <a href="{{ route('courier.shipments.show', $message->shipment) }}"
                           class="mb-1 inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold underline underline-offset-2 {{ $mine ? 'bg-white/20 text-white' : 'bg-primary-50 text-[var(--brand)]' }}">
                            الشحنة <span class="num">{{ $message->shipment->number }}</span>
                            @if ($message->shipment->recipient_name) — {{ $message->shipment->recipient_name }}@endif
                            <span aria-hidden="true">‹</span>
                        </a>
                    @else
                        <span class="mb-1 inline-block rounded-md px-2 py-0.5 text-xs {{ $mine ? 'bg-white/15' : 'bg-ink-50' }}">
                            الشحنة <span class="num">{{ $message->shipment->number }}</span>
                        </span>
                    @endif
                @endif
                <p class="whitespace-pre-line text-[15px] leading-relaxed">{{ $message->body }}</p>
                <p class="mt-1 text-[11px] {{ $mine ? 'text-white/75' : 'text-ink-400' }}">
                    {{ $mine ? 'أنت' : $message->author_name }} · <span class="num">{{ $message->created_at->format('m-d H:i') }}</span>
                </p>
                @if (! $mine && $message->shipment && ($his ?? false) && $shipment?->id !== $message->shipment->id)
                    <a href="{{ route('courier.chat', ['shipment' => $message->shipment->id]) }}#reply" class="mt-1 inline-block text-xs text-[var(--brand)] underline">ردّ عن هذه الشحنة</a>
                @endif
            </div>
        </li>
    @empty
        <li class="card p-6 text-center text-sm text-ink-500">لا رسالة بعد.</li>
    @endforelse
</ol>

<form id="reply" method="POST" action="{{ route('courier.chat.send') }}" class="card sticky bottom-24 space-y-2 p-3">
    @csrf
    @if ($shipment)
        <input type="hidden" name="shipment_id" value="{{ $shipment->id }}">
        <p class="text-xs text-ink-500">عن الشحنة <span class="num font-semibold">{{ $shipment->number }}</span> — {{ $shipment->recipient_name }}</p>
    @endif
    <textarea name="body" rows="2" maxlength="2000" required class="field-input text-[15px]" placeholder="اكتب رسالتك…" aria-label="الرسالة"></textarea>
    <button type="submit" class="btn-primary w-full">أرسل للمكتب</button>
</form>
@endsection

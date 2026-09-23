@extends('layouts.app')
@section('title', $conversation->subject)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">{{ $conversation->subject }}</h1>
        <p class="mt-1 text-sm text-ink-500">
            <a href="{{ route('merchants.show', $conversation->merchant_id) }}" class="hover:underline">{{ $conversation->merchant?->business_name }}</a>
            <span class="num">· {{ $conversation->merchant?->phone }}</span>
            @if ($conversation->shipment)
                · <a href="{{ route('shipments.show', $conversation->shipment_id) }}" class="hover:underline">
                    وصل <span class="num">{{ $conversation->shipment->number }}</span>
                  </a>
                <x-status-badge :status="$conversation->shipment->status" class="ms-1" />
            @endif
        </p>
    </div>
    <div class="flex gap-2">
        <form method="POST" action="{{ route('conversations.close', $conversation) }}">
            @csrf
            <button type="submit" class="btn-ghost">{{ $conversation->isOpen() ? 'أغلِق المحادثة' : 'أعِد فتحها' }}</button>
        </form>
        <a href="{{ route('conversations.index') }}" class="btn-ghost">كل المحادثات</a>
    </div>
</div>

<div class="mx-auto max-w-3xl">
    <section class="card mb-4 p-5">
        <x-thread :messages="$conversation->messages" mine="staff" />
    </section>

    <form method="POST" action="{{ route('conversations.reply', $conversation) }}" class="card p-4">
        @csrf
        <label class="field-label" for="body">ردّك</label>
        <textarea id="body" name="body" rows="3" class="field-input" maxlength="2000" required autofocus></textarea>
        <div class="mt-3 flex items-center justify-between gap-3">
            <p class="text-xs text-ink-500">
                @unless ($conversation->isOpen()) الردّ يُعيد فتح المحادثة. @endunless
            </p>
            <button type="submit" class="btn-primary">أرسل</button>
        </div>
    </form>
</div>
@endsection

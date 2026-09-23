@extends('layouts.portal')
@section('title', $conversation->subject)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">{{ $conversation->subject }}</h1>
        @if ($conversation->shipment)
            <p class="mt-1 text-sm text-ink-500">
                <a href="{{ route('portal.shipments.show', $conversation->shipment_id) }}" class="hover:underline">
                    وصل <span class="num">{{ $conversation->shipment->number }}</span>
                </a>
            </p>
        @endif
    </div>
    <a href="{{ route('portal.support.index') }}" class="btn-ghost">كل المحادثات</a>
</div>

<div class="mx-auto max-w-3xl">
    <section class="card mb-4 p-5">
        <x-thread :messages="$conversation->messages" mine="merchant" />
    </section>

    <form method="POST" action="{{ route('portal.support.reply', $conversation) }}" class="card p-4">
        @csrf
        <label class="field-label" for="body">رسالتك</label>
        <textarea id="body" name="body" rows="3" class="field-input" maxlength="2000" required></textarea>
        <div class="mt-3 flex justify-end">
            <button type="submit" class="btn-primary">أرسل</button>
        </div>
    </form>
</div>
@endsection

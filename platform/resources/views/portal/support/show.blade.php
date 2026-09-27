@extends('layouts.portal')
@section('title', $conversation->subject)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $conversation->subject }}</h1>
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
        <x-thread :messages="$conversation->messages" mine="merchant" file-route="portal.support.attachment" />
    </section>

    <form method="POST" action="{{ route('portal.support.reply', $conversation) }}" enctype="multipart/form-data" class="card p-4">
        @csrf
        <label class="field-label" for="body">رسالتك</label>
        <textarea id="body" name="body" rows="3" class="field-input" maxlength="2000">{{ old('body') }}</textarea>
        <div class="mt-3">
            <label class="field-label" for="attachment">ملف (اختياري)</label>
            <input id="attachment" name="attachment" type="file" class="field-input" accept="image/jpeg,image/png,image/webp,application/pdf">
            <p class="mt-1 text-xs text-ink-500">صورة أو PDF حتى 5 MB — صورة التلف أو الوصل تُغني عن الشرح.</p>
        </div>
        <div class="mt-3 flex justify-end">
            <button type="submit" class="btn-primary">أرسل</button>
        </div>
    </form>
</div>
@endsection

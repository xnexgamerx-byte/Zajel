@extends('layouts.app')
@section('title', 'رسالتي الثابتة للتاجر')

@section('content')
<div class="mb-5">
    <h1 class="page-title">رسالتي الثابتة للتاجر</h1>
    <p class="mt-1 text-sm text-ink-500">
        نصّك وحدك: يُنسخ أو يُرسَل بواتساب التاجر بضغطة من كل شحنةٍ في
        <a href="{{ route('processing.index') }}" class="text-[var(--brand)] hover:underline">«شحنات لم تُسلَّم (للمعالجة)»</a>،
        والخانات بين القوسين تُملأ من الشحنة نفسها.
    </p>
</div>

{{-- الرسالة الثابتة للتاجر (docs/plan/41): لكل موظّفٍ نصّه --}}
@php $myMessage = \App\Support\MerchantMessage::templateOf(auth()->user()); @endphp
<section class="card max-w-3xl p-5">
    <form method="POST" action="{{ route('merchant-message.update') }}" class="space-y-3">
        @csrf
        @method('PUT')
        <textarea name="merchant_message" rows="6" maxlength="{{ \App\Support\MerchantMessage::MAX }}" class="field-input"
                  aria-label="نصّ رسالتي للتاجر">{{ old('merchant_message', $myMessage) }}</textarea>
        @error('merchant_message') <p class="field-error">{{ $message }}</p> @enderror
        <p class="text-xs text-ink-500">
            الخانات بين القوسين تُملأ وحدها:
            @foreach (\App\Support\MerchantMessage::FIELDS as $field => $meaning)
                <span class="chip chip-mute ms-1" title="{{ $meaning }}">{{ $field }}</span>
            @endforeach
        </p>
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn-primary">احفظ رسالتي</button>
            @if (filled(auth()->user()->merchant_message))
                <button type="submit" name="reset" value="1" class="btn-ghost">أرجعها للقالب</button>
            @endif
        </div>
    </form>
</section>
@endsection

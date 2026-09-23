@extends('layouts.courier')
@section('title', 'الإشعارات')

@section('content')
<h1 class="mb-3 text-lg font-bold">الإشعارات</h1>

@if ($link = \App\Support\Phone::whatsappUrl($company->setting('support.whatsapp'), 'مرحباً، أنا المندوب '.auth()->user()->courier?->name))
    <a href="{{ $link }}" target="_blank" rel="noopener"
       class="mb-3 flex items-center justify-between rounded-2xl border border-ok-200 bg-ok-50 px-4 py-3 text-sm font-semibold text-ok-700">
        تواصل مع الدعم على واتساب
        <span aria-hidden="true">←</span>
    </a>
@endif

@forelse ($announcements as $announcement)
    <article class="mb-3 rounded-2xl border bg-white p-4 {{ in_array($announcement->id, $fresh, true) ? 'border-[var(--brand)]' : 'border-ink-200' }}">
        <div class="mb-1 flex items-start justify-between gap-2">
            <h2 class="font-bold">{{ $announcement->title }}</h2>
            @if (in_array($announcement->id, $fresh, true))
                <span class="chip chip-info shrink-0">جديد</span>
            @endif
        </div>
        <p class="whitespace-pre-line text-sm leading-relaxed text-ink-700">{{ $announcement->body }}</p>
        <p class="num mt-2 text-xs text-ink-400">{{ $announcement->created_at->format('Y-m-d H:i') }}</p>
    </article>
@empty
    <p class="rounded-2xl border border-ink-200 bg-white p-8 text-center text-ink-500">لا إشعارات.</p>
@endforelse
@endsection

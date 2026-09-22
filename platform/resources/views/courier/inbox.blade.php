@extends('layouts.courier')
@section('title', 'الإشعارات')

@section('content')
<h1 class="mb-3 text-lg font-bold">الإشعارات</h1>

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

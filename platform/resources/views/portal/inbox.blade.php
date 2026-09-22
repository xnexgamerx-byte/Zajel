@extends('layouts.portal')
@section('title', 'الإشعارات')

@section('content')
<h1 class="mb-1 text-xl font-bold">الإشعارات</h1>
<p class="mb-5 text-sm text-ink-500">ما أعلنته {{ $company->name }} لتجّارها.</p>

<div class="mx-auto max-w-3xl space-y-3">
    @forelse ($announcements as $announcement)
        <article class="card p-5 {{ in_array($announcement->id, $fresh, true) ? 'ring-2 ring-[var(--brand)]' : '' }}">
            <div class="mb-1 flex items-start justify-between gap-2">
                <h2 class="font-bold">{{ $announcement->title }}</h2>
                @if (in_array($announcement->id, $fresh, true))
                    <span class="chip chip-info shrink-0">جديد</span>
                @endif
            </div>
            <p class="whitespace-pre-line leading-relaxed text-ink-700">{{ $announcement->body }}</p>
            <p class="num mt-3 text-xs text-ink-400">{{ $announcement->created_at->format('Y-m-d H:i') }}</p>
        </article>
    @empty
        <p class="card p-10 text-center text-ink-500">لا إشعارات.</p>
    @endforelse
</div>
@endsection

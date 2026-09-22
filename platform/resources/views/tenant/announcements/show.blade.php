@extends('layouts.app')
@section('title', $announcement->title)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">{{ $announcement->title }}</h1>
        <p class="mt-1 text-sm text-ink-500">
            إلى {{ $announcement->audienceLabel() }} — {{ $announcement->created_at->format('Y-m-d H:i') }}
            @if ($announcement->author) · {{ $announcement->author->name }} @endif
            @if ($announcement->expires_at) · يختفي {{ $announcement->expires_at->format('Y-m-d') }} @endif
        </p>
    </div>
    <a href="{{ route('announcements.index') }}" class="btn-ghost">كل الإشعارات</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="card p-5 lg:col-span-2">
        <p class="whitespace-pre-line leading-relaxed">{{ $announcement->body }}</p>
    </section>

    <section class="card overflow-hidden">
        <div class="border-b border-ink-200 px-5 py-4">
            <h2 class="card-title">قرأه <span class="num">{{ number_format($announcement->reads->count()) }}</span> من <span class="num">{{ number_format($reach) }}</span></h2>
            <p class="card-hint">القراءة فتحُ صندوق الإشعارات بعد وصوله — لا ضغطُ زرّ.</p>
        </div>
        @if ($announcement->reads->isEmpty())
            <p class="p-6 text-center text-sm text-ink-500">لم يقرأه أحدٌ بعد.</p>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($announcement->reads as $read)
                    <li class="flex items-center justify-between px-5 py-2.5 text-sm">
                        <span>{{ $read->user?->name ?? 'حسابٌ محذوف' }}</span>
                        <span class="num text-xs text-ink-500">{{ $read->read_at->format('m-d H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection

@extends('layouts.app')
@section('title', 'إشعارات جماعية')

@section('content')
<div class="mb-5">
    <h1 class="page-title">إشعارات جماعية</h1>
    <p class="mt-1 text-sm text-ink-500">
        إعلانٌ واحد يبلغ كل مناديب التوصيل أو الاستلام أو التجّار — في تطبيقاتهم، ومعه مَن قرأه.
    </p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <form method="POST" action="{{ route('announcements.store') }}" class="card space-y-4 p-5 lg:order-last">
        @csrf
        <h2 class="card-title">إعلانٌ جديد</h2>

        <div>
            <span class="field-label">إلى</span>
            <div class="grid gap-2">
                @foreach ($audiences as $value => $label)
                    <label class="flex cursor-pointer items-center justify-between rounded-lg border border-ink-200 px-3 py-2 has-[:checked]:border-[var(--brand)] has-[:checked]:bg-ink-50">
                        <span class="flex items-center gap-2">
                            <input type="radio" name="audience" value="{{ $value }}" required @checked(old('audience', $audience) === $value)>
                            {{ $label }}
                        </span>
                        {{-- كم سيبلغ قبل أن يُرسَل: إعلانٌ لجمهورٍ بلا حسابات لا يقرؤه أحد --}}
                        <span class="num text-xs text-ink-500">{{ number_format($reach[$value]) }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <label class="field-label" for="title">العنوان</label>
            <input id="title" name="title" class="field-input" maxlength="160" required value="{{ old('title') }}"
                   placeholder="مثال: الدوام غداً يبدأ السابعة">
        </div>

        <div>
            <label class="field-label" for="body">النصّ</label>
            <textarea id="body" name="body" rows="5" class="field-input" maxlength="2000" required>{{ old('body') }}</textarea>
        </div>

        <div>
            <label class="field-label" for="expires_on">يختفي بعد (اختياري)</label>
            <input id="expires_on" name="expires_on" type="date" class="field-input" min="{{ now()->toDateString() }}" value="{{ old('expires_on') }}">
            <p class="mt-1 text-xs text-ink-500">إعلانٌ عن «غدٍ» لا معنى له بعد غد.</p>
        </div>

        <button type="submit" class="btn-primary w-full">أرسل</button>
    </form>

    <section class="card overflow-hidden lg:col-span-2">
        @if ($announcements->isEmpty())
            <p class="p-10 text-center text-ink-500">لم يُرسَل إعلانٌ بعد.</p>
        @else
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>الإعلان</th><th>إلى</th><th>قرأه</th><th>أُرسل</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($announcements as $announcement)
                            @php
                                $of = $reach[$announcement->audience] ?? 0;
                                $pct = $of > 0 ? min(100, (int) round($announcement->reads_count / $of * 100)) : 0;
                            @endphp
                            <tr class="{{ $announcement->isExpired() ? 'text-ink-400' : '' }}">
                                <td class="max-w-72">
                                    <a href="{{ route('announcements.show', $announcement) }}" class="font-medium hover:underline">{{ $announcement->title }}</a>
                                    <span class="block truncate text-xs text-ink-500">{{ $announcement->body }}</span>
                                </td>
                                <td class="whitespace-nowrap text-sm">
                                    {{ $announcement->audienceLabel() }}
                                    @if ($announcement->isExpired())
                                        <span class="chip chip-mute ms-1">انتهى</span>
                                    @endif
                                </td>
                                <td class="w-36">
                                    <div class="mb-1 flex justify-between text-xs">
                                        <span class="num font-semibold">{{ number_format($announcement->reads_count) }} / {{ number_format($of) }}</span>
                                        <span class="num text-ink-500">{{ $pct }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-ink-100">
                                        <div class="h-full rounded-full bg-ok-700" style="width: {{ $pct }}%"></div>
                                    </div>
                                </td>
                                <td class="num whitespace-nowrap text-xs text-ink-600">
                                    {{ $announcement->created_at->format('Y-m-d H:i') }}
                                    <span class="block text-ink-400">{{ $announcement->author?->name }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-4">{{ $announcements->links() }}</div>
        @endif
    </section>
</div>
@endsection

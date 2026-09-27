@extends('layouts.app')
@section('title', 'سجلّ الإشعارات')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'سجلّ الإشعارات', 'blurb' => 'ما أُرسل من إشعارات بالتطبيق والفئة، ومن أرسله، وكم قرأه — واختر تاجراً أو مندوباً لترى ما وصله وهل فتحه. '.$period->label()])

<x-report-period :period="$period">
    <div class="min-w-52">
        <label class="field-label" for="audience">التطبيق والفئة</label>
        <select id="audience" name="audience" class="field-input">
            <option value="">الكل</option>
            @foreach ($audiences as $value => $label)
                <option value="{{ $value }}" @selected($audience === $value)>{{ $apps[$value] }} · {{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-56">
        <label class="field-label" for="user_id">تاجر أو مندوب</label>
        <select id="user_id" name="user_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($recipients->groupBy(fn ($r) => $r->role->value) as $role => $people)
                <optgroup label="{{ $role === \App\Enums\UserRole::Merchant->value ? 'التجّار' : 'المناديب' }}">
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected($user?->id === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>
</x-report-period>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    @foreach ($audiences as $value => $label)
        <a href="{{ route('reports.notifications', array_merge($period->query(), ['audience' => $value])) }}"
           class="card block p-4 transition hover:border-[var(--brand)] {{ $audience === $value ? 'border-[var(--brand)]' : '' }}">
            <div class="text-xs font-medium text-ink-500">{{ $apps[$value] }}</div>
            <div class="text-sm font-bold">{{ $label }}</div>
            <div class="mt-2 flex items-end justify-between gap-3">
                <div>
                    <div class="num text-2xl font-bold">{{ number_format($sent[$value] ?? 0) }}</div>
                    <div class="text-xs text-ink-500">أُرسل في المدّة</div>
                </div>
                <div class="text-end text-xs text-ink-500">
                    <div>قراءات <span class="num font-semibold text-ink-700">{{ number_format($reads[$value] ?? 0) }}</span></div>
                    @if ($reach[$value] > 0)
                        <div>يبلغ اليوم <span class="font-semibold text-ink-700">{{ \App\Support\Arabic::count($reach[$value], ['حساباً واحداً', 'حسابين', 'حسابات', 'حساباً']) }}</span></div>
                    @else
                        <div class="text-warn-700">لا حساب في فئته اليوم</div>
                    @endif
                </div>
            </div>
        </a>
    @endforeach
</div>

@if ($user)
    <div class="card mb-5 flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
            <div class="font-bold">{{ $user->name }}</div>
            <div class="text-xs text-ink-500">
                @if ($userAudiences)
                    {{ collect($userAudiences)->map(fn ($a) => $apps[$a].' · '.$audiences[$a])->unique()->implode('، ') }}
                @else
                    لا فئة له اليوم — لا يصله إشعار
                @endif
            </div>
        </div>
        <div class="text-sm">
            @if ($announcements->total() === 0)
                لم يصله إشعارٌ في المدّة.
            @else
                وصله {{ \App\Support\Arabic::count($announcements->total(), ['إشعار واحد', 'إشعاران', 'إشعارات', 'إشعاراً']) }} في المدّة،
                قرأ منها <span class="num font-semibold">{{ number_format($userRead) }}</span>
            @endif
        </div>
    </div>
@endif

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الإشعار</th>
                    <th>التطبيق والفئة</th>
                    <th>{{ $user ? 'قرأه '.$user->name : 'قرأه' }}</th>
                    <th>أُرسل</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($announcements as $announcement)
                    @php
                        $of = $reach[$announcement->audience] ?? 0;
                        $mine = $user ? $announcement->reads->first() : null;
                    @endphp
                    <tr class="{{ $announcement->isExpired() ? 'text-ink-400' : '' }}">
                        <td class="max-w-80">
                            @can('notify.send')
                                <a href="{{ route('announcements.show', $announcement) }}" class="font-medium hover:underline">{{ $announcement->title }}</a>
                            @else
                                <span class="font-medium">{{ $announcement->title }}</span>
                            @endcan
                            <span class="block truncate text-xs text-ink-500">{{ $announcement->body }}</span>
                        </td>
                        <td class="whitespace-nowrap text-sm">
                            <span class="block text-xs text-ink-500">{{ $apps[$announcement->audience] ?? '—' }}</span>
                            {{ $announcement->audienceLabel() }}
                            @if ($announcement->isExpired())
                                <span class="chip chip-mute ms-1">انتهى</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-sm">
                            @if ($user)
                                @if ($mine)
                                    <span class="chip chip-ok">قرأه</span>
                                    <span class="num block text-xs text-ink-500">{{ $mine->read_at->format('Y-m-d H:i') }}</span>
                                @else
                                    <span class="chip chip-warn">لم يفتحه</span>
                                @endif
                            @else
                                <span class="num font-semibold">{{ number_format($announcement->reads_count) }}</span>
                                <span class="num text-xs text-ink-500">من {{ number_format($of) }}</span>
                            @endif
                        </td>
                        <td class="num whitespace-nowrap text-xs text-ink-600">
                            {{ $announcement->created_at->format('Y-m-d H:i') }}
                            <span class="block text-ink-400">{{ $announcement->author?->name }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-12 text-center text-ink-500">
                        {{ $user ? 'لم يُرسَل إلى فئته شيءٌ في هذه المدّة.' : 'لم يُرسَل إشعارٌ في هذه المدّة.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($announcements->hasPages())
        <div class="p-4">{{ $announcements->links() }}</div>
    @endif
</div>
@endsection

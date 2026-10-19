{{-- التنبيهات المالية (docs/plan/68 §١٢): تبويبٌ في «التنبيهات التشغيلية» لمن يرى الأرباح --}}
@use('App\Models\FinancialAlert')

<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
    <p class="max-w-3xl text-xs leading-6 text-ink-600">
        تُفحص الحسابات كل ساعة: ما زال سببه يُغلق وحده، وما عولج وسببه قائمٌ يُفتح ثانيةً. والمهلة من الخطورة:
        @foreach (FinancialAlert::SEVERITIES as $level => [$label, $chip, $hoursDue])
            <span class="chip {{ $chip }}">{{ $label }}</span> {{ \App\Support\Arabic::hours($hoursDue) }}@if (! $loop->last)،@endif
        @endforeach
    </p>
    <form method="POST" action="{{ route('finance.alerts.scan') }}">
        @csrf
        <button class="btn-ghost text-sm"><x-icon name="search" class="size-4"/> افحص الآن</button>
    </form>
</div>

<form method="GET" action="{{ route('operations.alerts') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <input type="hidden" name="tab" value="finance">
    <div>
        <label class="field-label" for="fa-status">الحال</label>
        <select id="fa-status" name="status" class="field-input">
            @foreach (['active' => 'المفتوح وقيد المعالجة', 'resolved' => 'المُعالَج', 'ignored' => 'المُتجاهَل', 'all' => 'الكل'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="fa-severity">الخطورة</label>
        <select id="fa-severity" name="severity" class="field-input">
            <option value="">كل الخطورات</option>
            @foreach (FinancialAlert::SEVERITIES as $level => [$label])
                <option value="{{ $level }}" @selected($severity === $level)>{{ $label }} @if ($bySeverity[$level] ?? 0)({{ number_format($bySeverity[$level]) }})@endif</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="fa-type">النوع</label>
        <select id="fa-type" name="type" class="field-input">
            <option value="">كل الأنواع</option>
            @foreach (FinancialAlert::TYPES as $key => [$label])
                <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn-ghost">عرض</button>
</form>

@error('alert') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror
@error('reason') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror

<div class="space-y-3">
    @forelse ($alerts as $alert)
        <article @class(['card p-4', 'border-s-4 border-bad-500' => $alert->severity === 'critical' && $alert->isActive()])>
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex flex-wrap items-center gap-2 text-xs">
                        <span class="chip {{ $alert->severityChip() }}">{{ $alert->severityLabel() }}</span>
                        <span class="chip chip-mute">{{ $alert->typeLabel() }}</span>
                        <span @class(['chip', 'chip-info' => $alert->status === 'in_progress', 'chip-ok' => $alert->status === 'resolved', 'chip-mute' => in_array($alert->status, ['open', 'ignored'], true)])>{{ $alert->statusLabel() }}</span>
                    </div>
                    <h3 class="font-semibold text-ink-900">
                        @if ($href = $alert->link())
                            <a href="{{ $href }}" class="hover:underline">{{ $alert->title }}</a>
                        @else
                            {{ $alert->title }}
                        @endif
                    </h3>
                    @if ($alert->details)<p class="mt-1 text-sm text-ink-600">{{ $alert->details }}</p>@endif
                    <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-500">
                        @if ($alert->branch)<span>{{ $alert->branch->name }}</span>@endif
                        @if ($alert->courier)<span>المندوب: {{ $alert->courier->name }}</span>@endif
                        @if ($alert->merchant)<span>التاجر: {{ $alert->merchant->business_name }}</span>@endif
                        <span>منذ <span class="num">{{ $alert->occurred_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span></span>
                        @if ($alert->isActive() && $alert->due_at)
                            <span @class(['font-semibold text-bad-700' => $alert->isOverdue()])>المهلة <span class="num">{{ $alert->due_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>@if ($alert->isOverdue()) (فاتت)@endif</span>
                        @endif
                        <span>المسؤول: {{ $alert->assigneeLabel() }}</span>
                        @if ($alert->reason && ! $alert->isActive())<span>{{ $alert->statusLabel() }}: {{ $alert->reason }}</span>@endif
                    </p>
                </div>
                @if ($alert->amount !== null)
                    <div @class(['num text-lg font-bold', 'text-bad-700' => $alert->amount < 0, 'text-ink-900' => $alert->amount >= 0])>{{ number_format($alert->amount) }} <span class="text-xs font-normal text-ink-500">د.ع</span></div>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap items-start gap-2 border-t border-ink-100 pt-3">
                @if ($alert->isActive())
                    @if ($alert->status === 'open' || (int) $alert->assigned_user_id !== (int) auth()->id())
                        <form method="POST" action="{{ route('finance.alerts.take', $alert) }}">
                            @csrf
                            <button class="btn-ghost text-sm">أتولّاه</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('finance.alerts.resolve', $alert) }}" class="flex flex-wrap items-center gap-1">
                        @csrf
                        <input name="note" maxlength="255" placeholder="ما فُعل (اختياري)" class="field-input w-48 py-1 text-sm" aria-label="ما فُعل">
                        <button class="btn-ghost text-sm text-ok-700">عولج</button>
                    </form>
                    <form method="POST" action="{{ route('finance.alerts.ignore', $alert) }}" class="flex flex-wrap items-center gap-1">
                        @csrf
                        <input name="reason" required maxlength="255" placeholder="سبب التجاهل" class="field-input w-44 py-1 text-sm" aria-label="سبب التجاهل">
                        <button class="btn-ghost text-sm text-ink-600">تجاهل</button>
                    </form>
                @endif
                <details class="ms-auto text-xs">
                    <summary class="cursor-pointer text-ink-500">السجل ({{ number_format($alert->actions->count()) }})</summary>
                    <ol class="mt-2 space-y-1">
                        @foreach ($alert->actions as $step)
                            <li>
                                <span class="num text-ink-500">{{ $step->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                                {{ $step->label() }}@if ($step->user_name) — {{ $step->user_name }}@endif
                                @if ($step->note)<span class="text-ink-500">: {{ $step->note }}</span>@endif
                            </li>
                        @endforeach
                    </ol>
                </details>
            </div>
        </article>
    @empty
        <div class="card px-4 py-12 text-center text-ink-500">
            {{ $status === 'active' ? 'لا تنبيه مالي مفتوح: الحسابات كما ينبغي.' : 'لا تنبيه بهذا البحث.' }}
        </div>
    @endforelse
</div>

@if ($alerts->hasPages())
    <div class="mt-4">{{ $alerts->links() }}</div>
@endif

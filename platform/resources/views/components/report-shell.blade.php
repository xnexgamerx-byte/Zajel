@props(['title', 'question', 'period', 'basis'])

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">{{ $title }}</h1>
        <p class="mt-1 text-sm text-ink-500">{{ $question }}</p>
    </div>
    <a href="{{ route('reports.index', $period->query()) }}" class="btn-ghost">كل التقارير</a>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <button type="submit" class="btn-primary">طبّق</button>

    {{-- ما الذي تقيسه المدّة بالضبط: الخلط يُنتج رقمين للسؤال الواحد --}}
    <p class="ms-auto max-w-80 text-xs text-ink-500">{{ $basis }}</p>
</form>

{{ $slot }}

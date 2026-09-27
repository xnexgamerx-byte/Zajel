<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $title }}</h1>
        <p class="mt-1 text-sm text-ink-500">{{ $blurb }}</p>
    </div>
    <a href="{{ route('reports.index', isset($period) ? $period->query() : []) }}" class="btn-ghost">كل التقارير</a>
</div>

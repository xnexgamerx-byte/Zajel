@props(['status'])

@php
    $classes = match ($status->color()) {
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'red'   => 'bg-red-50 text-red-700 ring-red-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'blue'  => 'bg-sky-50 text-sky-700 ring-sky-200',
        'gray'  => 'bg-slate-100 text-slate-600 ring-slate-200',
        default => 'bg-slate-50 text-slate-700 ring-slate-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 $classes"]) }}>
    {{ $status->label() }}
</span>

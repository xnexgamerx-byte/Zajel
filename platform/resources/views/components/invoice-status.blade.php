@props(['status'])

@php
    [$label, $classes] = match ($status) {
        'draft'   => ['مسوّدة', 'bg-slate-100 text-slate-600 ring-slate-200'],
        'issued'  => ['صادرة', 'bg-sky-50 text-sky-700 ring-sky-200'],
        'paid'    => ['مدفوعة', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'overdue' => ['متأخّرة', 'bg-red-50 text-red-700 ring-red-200'],
        'void'    => ['ملغاة', 'bg-slate-100 text-slate-400 ring-slate-200'],
        default   => [$status, 'bg-slate-100 text-slate-600 ring-slate-200'],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 $classes"]) }}>
    {{ $label }}
</span>

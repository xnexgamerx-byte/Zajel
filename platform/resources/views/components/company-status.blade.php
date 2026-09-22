@props(['status'])

@php
    [$label, $classes] = match ($status) {
        'active'    => ['مفعّلة', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'trial'     => ['تجريبية', 'bg-amber-50 text-amber-800 ring-amber-200'],
        'suspended' => ['موقوفة', 'bg-red-50 text-red-700 ring-red-200'],
        'cancelled' => ['ملغاة', 'bg-slate-100 text-slate-600 ring-slate-200'],
        default     => [$status, 'bg-slate-100 text-slate-600 ring-slate-200'],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 $classes"]) }}>
    {{ $label }}
</span>

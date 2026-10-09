@props(['status'])

@php
    [$label, $tone] = match ($status) {
        'draft'     => ['مسوّدة', 'chip-warn'],
        'confirmed' => ['مُقفَل', 'chip-info'],
        'paid'      => ['مدفوع', 'chip-ok'],
        'cancelled' => ['ملغى', 'chip-bad'],
        default     => [$status, 'chip-mute'],
    };
@endphp

<span {{ $attributes->merge(['class' => "chip $tone"]) }}>{{ $label }}</span>

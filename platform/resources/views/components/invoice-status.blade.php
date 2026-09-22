@props(['status'])

@php
    [$label, $tone] = match ($status) {
        'draft'   => ['مسوّدة', 'chip-mute'],
        'issued'  => ['صادرة', 'chip-info'],
        'paid'    => ['مدفوعة', 'chip-ok'],
        'overdue' => ['متأخّرة', 'chip-bad'],
        'void'    => ['ملغاة', 'chip-mute'],
        default   => [$status, 'chip-mute'],
    };
@endphp

<span {{ $attributes->merge(['class' => "chip $tone"]) }}>{{ $label }}</span>

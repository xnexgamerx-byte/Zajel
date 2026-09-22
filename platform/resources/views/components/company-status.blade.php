@props(['status'])

@php
    [$label, $tone] = match ($status) {
        'active'    => ['مفعّلة', 'chip-ok'],
        'trial'     => ['تجريبية', 'chip-warn'],
        'suspended' => ['موقوفة', 'chip-bad'],
        'cancelled' => ['ملغاة', 'chip-mute'],
        default     => [$status, 'chip-mute'],
    };
@endphp

<span {{ $attributes->merge(['class' => "chip $tone"]) }}>{{ $label }}</span>

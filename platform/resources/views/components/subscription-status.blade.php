@props(['status' => null])

@php
    [$label, $tone] = match ($status) {
        'active'    => ['مدفوع', 'chip-ok'],
        'trialing'  => ['تجريبي', 'chip-warn'],
        'past_due'  => ['متأخّر', 'chip-bad'],
        'cancelled' => ['ملغى', 'chip-mute'],
        'expired'   => ['منتهٍ', 'chip-mute'],
        null        => ['بلا اشتراك', 'chip-mute'],
        default     => [$status, 'chip-mute'],
    };
@endphp

<span {{ $attributes->merge(['class' => "chip $tone"]) }}>{{ $label }}</span>

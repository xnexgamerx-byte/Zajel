@props(['status', 'shipment' => null])

@php
    // اللون تعزيز؛ النصّ هو القناة الأساسية، والنقطة قناة ثالثة. والشحنة إن مُرّرت تُعرض
    // بمرحلتها التي لا حالة لها: «إعادة توصيل» و«راجع مؤكد» (docs/plan/38)
    $tone = match ($shipment?->statusColor() ?? $status->color()) {
        'green' => 'chip-ok',
        'red'   => 'chip-bad',
        'amber' => 'chip-warn',
        'blue'  => 'chip-info',
        default => 'chip-mute',
    };
@endphp

<span {{ $attributes->merge(['class' => "chip $tone"]) }}>{{ $shipment?->statusLabel() ?? $status->label() }}</span>
